import {
  createDevice,
  createSignedPrekey,
  initiateSession,
  acceptSession,
  wrapSenderKey,
  unwrapSenderKey,
  type PrekeyBundle,
} from '~/lib/crypto/identity'
import {
  exportPublicKey,
  fromBase64,
  generateEphemeralKeyPair,
  sign,
  toBase64,
} from '~/lib/crypto/primitives'
import { createChain, type SenderChain } from '~/lib/crypto/senderKey'
import {
  chainKeyFor,
  openKeyStore,
  type KeyStore,
  type StoredSignedPrekey,
} from '~/lib/crypto/keyStore'

/**
 * This device's identity, and the sender keys it shares with everybody else's.
 *
 * The seam between the crypto library — which knows about bytes and knows nothing about this
 * app — and the API. Nothing above this composable should import from `~/lib/crypto`
 * directly; nothing inside `~/lib/crypto` should know that an HTTP request exists.
 *
 * Three things happen here, in this order, and each is a prerequisite for the next:
 *
 *  1. **Bootstrap.** Generate this device's keys once, publish the public halves, keep the
 *     private ones in a store the rest of the app can't reach into.
 *  2. **Distribution.** Before sending in an encrypted channel for the first time this era,
 *     wrap our sender key once per other device and post the lot.
 *  3. **Collection.** On opening an encrypted channel, fetch whatever was addressed to us and
 *     unwrap it into chains we can decrypt with.
 *
 * Everything is per-epoch, because everything about this feature is. A chain belongs to one
 * era of one channel, and the era ending is what stops a departed member reading on.
 */

/** How low the one-time prekey stock may get before a refill. */
const PREKEY_FLOOR = 20

/** How many to publish when refilling. */
const PREKEY_BATCH = 100

/**
 * How long a signed prekey serves before it is rotated out.
 *
 * A week. Short enough that a stolen one stops being useful reasonably soon; long enough
 * that the previous key is always still around when a session wrapped against it arrives.
 */
const SIGNED_PREKEY_LIFETIME_MS = 7 * 24 * 60 * 60 * 1000

/** A bundle as the API serves it — snake_case, and carrying the row id to address. */
interface ApiBundle {
  device_key_id: number
  user_id: number
  device_id: string
  identity_public: string
  signing_public: string
  signed_prekey: string
  prekey_signature: string
  one_time_prekey: string | null
  one_time_prekey_id: string | null
}

/**
 * The crypto library's shape, from the API's.
 *
 * A dull mapping, and the right place for it: `~/lib/crypto` is deliberately ignorant of
 * this app's transport, so the naming conventions meet here rather than leaking either way.
 * Nulls become absent, because "no one-time prekey was available" is a missing field to
 * X3DH rather than a present one holding nothing.
 */
function toPrekeyBundle(row: ApiBundle): PrekeyBundle {
  return {
    deviceId: row.device_id,
    identityPublic: row.identity_public,
    signingPublic: row.signing_public,
    signedPrekey: row.signed_prekey,
    prekeySignature: row.prekey_signature,
    ...(row.one_time_prekey ? { oneTimePrekey: row.one_time_prekey } : {}),
    ...(row.one_time_prekey_id ? { oneTimePrekeyId: row.one_time_prekey_id } : {}),
  }
}

export function useEncryption() {
  const api = useApi()
  const { user } = useAuth()

  /**
   * Nuxt state rather than a module-level variable, so signing out and back in as somebody
   * else doesn't inherit the previous account's device. The store is keyed by account id
   * too — see `openKeyStore`.
   */
  const deviceId = useState<string | null>('encryption:device-id', () => null)
  const ready = useState<boolean>('encryption:ready', () => false)

  /**
   * Live keys for this session. Deliberately *not* in `useState`: a non-extractable
   * CryptoKey has no business being serialised into Nuxt's payload, and the store is the
   * thing that persists them anyway.
   */
  let keys: { identity: CryptoKeyPair; signing: CryptoKeyPair } | null = null
  let signedPrekeys: StoredSignedPrekey[] = []
  let store: KeyStore | null = null
  /** Set only on the launch that rotates; otherwise the prekey is re-signed on the spot. */
  let lastSignature: string | null = null

  function requireStore(): KeyStore {
    if (!user.value) throw new Error('encryption needs a signed-in account')
    store ??= openKeyStore(user.value.id)

    return store
  }

  /**
   * Make sure this device exists and its keys are published.
   *
   * Safe to call on every launch, and meant to be: re-registering rotates the signed prekey
   * and tells the server the device is still alive. The identity keys are generated exactly
   * once and then loaded from the store forever after — regenerating them would look, to
   * everybody else, exactly like an impostor taking over the account, because that is the
   * signal a changed identity key is *for*.
   */
  async function bootstrap(): Promise<void> {
    if (ready.value) return

    const keyStore = requireStore()
    const existing = await keyStore.loadIdentity()
    let identity = existing

    if (identity) {
      keys = { identity: identity.identity, signing: identity.signing }
      deviceId.value = identity.deviceId
      signedPrekeys = identity.signedPrekeys ?? []
    } else {
      keys = await createDevice()
      deviceId.value = crypto.randomUUID()
      signedPrekeys = []

      identity = {
        deviceId: deviceId.value,
        identity: keys.identity,
        signing: keys.signing,
        createdAt: Date.now(),
        signedPrekeys: [],
      }
    }

    // Rotated on a clock, not on every launch. Rotating each time a tab opened would be
    // pointless churn, and — worse — it would retire keys faster than the sessions wrapped
    // against them arrive, which is how a rotation turns into lost messages.
    const current = signedPrekeys[0]
    if (!current || Date.now() - current.createdAt > SIGNED_PREKEY_LIFETIME_MS) {
      const rotated = await createSignedPrekey(keys)

      signedPrekeys = [
        {
          publicKey: toBase64(rotated.publicKey),
          keyPair: rotated.keyPair,
          createdAt: Date.now(),
        },
        // Keep exactly one predecessor, so a key wrapped against the outgoing prekey still
        // opens. See StoredIdentity.signedPrekeys.
        ...signedPrekeys.slice(0, 1),
      ]

      identity.signedPrekeys = signedPrekeys
      // Published below, with the signature that proves it is ours.
      lastSignature = toBase64(rotated.signature)
    }

    await keyStore.saveIdentity(identity)

    const registration = await api<{ data: { one_time_prekeys: number } }>('/api/encryption/devices', {
      method: 'PUT',
      body: {
        device_id: deviceId.value,
        identity_public: toBase64(await exportPublicKey(keys.identity.publicKey)),
        signing_public: toBase64(await exportPublicKey(keys.signing.publicKey)),
        signed_prekey: signedPrekeys[0]!.publicKey,
        prekey_signature: lastSignature ?? (await resignCurrent()),
      },
    })

    if (registration.data.one_time_prekeys < PREKEY_FLOOR) await refillPrekeys()

    ready.value = true
  }

  /**
   * Sign the current signed prekey again.
   *
   * Needed on a launch that *doesn't* rotate: the prekey is loaded from the store but its
   * signature isn't, and the server wants both. Re-signing is cheap and deterministic in
   * meaning — the same key, attested by the same identity — so there is nothing to gain from
   * persisting the signature alongside it.
   */
  async function resignCurrent(): Promise<string> {
    const signature = await sign(keys!.signing.privateKey, fromBase64(signedPrekeys[0]!.publicKey))

    return toBase64(signature)
  }

  /**
   * Publish a fresh batch of single-use prekeys.
   *
   * The private halves go in the store before the public ones go to the server. The other
   * order has a window in which somebody could claim a prekey we cannot answer for, and the
   * session they started with it would be undecryptable — a message that silently never
   * arrives, which is the worst failure this system has.
   */
  async function refillPrekeys(): Promise<void> {
    const keyStore = requireStore()
    const published: Array<{ prekey_id: string; public_key: string }> = []

    for (let i = 0; i < PREKEY_BATCH; i++) {
      const id = crypto.randomUUID()
      const pair = await generateEphemeralKeyPair()

      await keyStore.savePrekey({ id, keyPair: pair, createdAt: Date.now() })
      published.push({ prekey_id: id, public_key: toBase64(await exportPublicKey(pair.publicKey)) })
    }

    await api('/api/encryption/devices/prekeys', {
      method: 'POST',
      body: { device_id: deviceId.value, one_time_prekeys: published },
    })
  }

  /**
   * Our sender chain for an era of a channel, creating and distributing it if it's new.
   *
   * The chain is stored before it is distributed, and distribution failing does not throw
   * the chain away: a chain nobody else has yet is useless but recoverable (redistribute),
   * whereas a chain we discarded after encrypting with it is a message nobody can ever read.
   * When in doubt this code keeps key material rather than losing it.
   */
  async function chainFor(channelId: number, epoch: number): Promise<SenderChain> {
    await bootstrap()

    const keyStore = requireStore()
    const stored = await keyStore.loadChain(channelId, epoch, deviceId.value!)

    /*
     * `rooted` is the repair gate — see StoredChain.
     *
     * An unmarked sending chain was written by the build that persisted the ratcheted key
     * instead of the root, so its bytes are a descendant of the key everybody else holds.
     * Continuing on it would keep producing messages nobody in the channel can read. There
     * is nothing to salvage, so it is replaced: a fresh chain, redistributed to every device.
     *
     * What that costs is the messages this device already sent on the broken chain. They stay
     * readable to everyone who received the original root — the damage was always local — but
     * this device cannot recover them, because the root it would need was overwritten.
     */
    if (stored?.rooted) {
      const chain = { epoch, deviceId: deviceId.value!, chainKey: stored.chainKey, index: stored.index }

      // Catch up with anybody who has appeared since this chain was last handed out. Cheap
      // when nothing has changed — one GET that consumes nothing — and it is what keeps a
      // device that joined mid-conversation from being permanently deaf to us.
      await topUpDistribution(channelId, chain, stored.distributedTo ?? [])

      return chain
    }

    const chain = createChain(epoch, deviceId.value!)
    await saveChain(channelId, chain)
    await distribute(channelId, chain)

    return chain
  }

  /**
   * Persist a chain's position, and optionally who it has reached.
   *
   * `distributedTo` is left alone when not given, because the common caller is a send — which
   * moves the counter and changes nothing about distribution.
   */
  async function saveChain(
    channelId: number,
    chain: SenderChain,
    distributedTo?: number[],
  ): Promise<void> {
    const keyStore = requireStore()
    const existing = await keyStore.loadChain(channelId, chain.epoch, chain.deviceId)

    await keyStore.saveChain({
      id: chainKeyFor(channelId, chain.epoch, chain.deviceId),
      channelId,
      epoch: chain.epoch,
      deviceId: chain.deviceId,
      chainKey: chain.chainKey,
      index: chain.index,
      // Always the root — only `index` moves on a send. See SenderChain.
      rooted: true,
      distributedTo: distributedTo ?? existing?.distributedTo ?? [],
    })
  }

  /**
   * Hand an existing chain to any device that has appeared since it was last distributed.
   *
   * The fix for the case that looks most like the feature being broken: somebody opens the
   * app in a new browser profile, or on a second machine, and every message in the channel
   * reads "this device doesn't have the key" — because at the moment each sender created its
   * chain, that device did not exist to be given one.
   *
   * The identities endpoint is the right one to poll here precisely because it consumes
   * nothing (see EncryptionKeyService). Only the diff goes to `bundles`, so the one-time
   * prekeys spent are one per genuinely new device rather than one per device in the channel.
   */
  async function topUpDistribution(
    channelId: number,
    chain: SenderChain,
    alreadySent: number[],
  ): Promise<void> {
    try {
      const identities = await api<{ data: Array<{ device_key_id: number, device_id: string }> }>(
        `/api/channels/${channelId}/encryption/identities`,
      )

      const missing = identities.data
        .filter(row => row.device_id !== deviceId.value && !alreadySent.includes(row.device_key_id))
        .map(row => row.device_key_id)

      if (missing.length === 0) return

      await distribute(channelId, chain, missing)
    } catch {
      // Never block a send on this. Failing here means one device stays unable to read us
      // until the next attempt, which is the situation we were already in.
    }
  }

  /**
   * Wrap our chain key once per other device in the channel, and post the lot.
   *
   * The bundle fetch consumes a one-time prekey from every device it returns, so this is not
   * something to do speculatively or on a timer — only when there is actually a new chain to
   * hand out.
   *
   * A device whose bundle fails verification is **skipped, not fatal**. It is the one case
   * where the server may be lying about somebody's keys, and the right response is to leave
   * that device unable to read rather than to seal our key to an impostor. The rest of the
   * channel carries on; that device sees "can't read this" and its owner can compare safety
   * numbers, which is exactly the conversation the failure should start.
   */
  async function distribute(
    channelId: number,
    chain: SenderChain,
    only: number[] = [],
  ): Promise<void> {
    const bundles = await api<{ data: ApiBundle[] }>(
      `/api/channels/${channelId}/encryption/bundles`,
      {
        method: 'POST',
        // `only` narrows this to devices we know we haven't reached. Left off for a brand-new
        // chain, which has reached nobody.
        body: { device_id: deviceId.value, ...(only.length ? { device_key_ids: only } : {}) },
      },
    )

    const wrapped: unknown[] = []

    for (const row of bundles.data) {
      try {
        const session = await initiateSession(keys!, toPrekeyBundle(row))
        const sealed = await wrapSenderKey(session.secret, chain.chainKey)

        wrapped.push({
          recipient_device_key_id: row.device_key_id,
          wrapped_key: toBase64(sealed.ciphertext),
          wrap_iv: toBase64(sealed.iv),
          ephemeral_public: toBase64(session.ephemeralPublic),
          prekey_id: row.one_time_prekey_id ?? null,
        })
      } catch {
        // Unverifiable bundle, or a key we couldn't import. Skip the device.
      }
    }

    if (wrapped.length === 0) return

    await api(`/api/channels/${channelId}/encryption/sender-keys`, {
      method: 'POST',
      body: { device_id: deviceId.value, epoch: chain.epoch, keys: wrapped },
    })

    /*
     * Record who now has it, so the next send only has to reach genuinely new devices.
     *
     * Written after the post succeeds, never before: a device recorded as reached but never
     * actually sent to would be skipped forever, which is a silent permanent failure. Getting
     * it wrong the other way merely costs a redundant wrap.
     */
    const reached = wrapped.map(entry => (entry as { recipient_device_key_id: number }).recipient_device_key_id)
    const existing = await requireStore().loadChain(channelId, chain.epoch, chain.deviceId)

    await saveChain(channelId, chain, [...new Set([...(existing?.distributedTo ?? []), ...reached])])
  }

  /**
   * Fetch and unwrap every sender key addressed to this device in a channel.
   *
   * Called on opening an encrypted channel. Chains already in the store are left alone —
   * theirs may have ratcheted forward since, and overwriting a wound-on chain with its
   * starting key would make every message we've already read unreadable again.
   */
  async function collectInbox(channelId: number): Promise<void> {
    await bootstrap()

    const keyStore = requireStore()
    const inbox = await api<{
      data: Array<{
        epoch: number
        sender_device_id: string
        sender_identity_public: string
        wrapped_key: string
        wrap_iv: string
        ephemeral_public: string
        prekey_id: string | null
      }>
    }>(`/api/channels/${channelId}/encryption/inbox`, {
      method: 'POST',
      body: { device_id: deviceId.value },
    })

    for (const entry of inbox.data) {
      if (await keyStore.loadChain(channelId, entry.epoch, entry.sender_device_id)) continue

      const oneTime = entry.prekey_id ? await keyStore.takePrekey(entry.prekey_id) : null

      // Which signed prekey the sender wrapped against isn't recorded — nothing on the wire
      // says so — so try each one we still hold, newest first. Normally the first works; the
      // second is the rotation boundary this list exists for.
      let chainKey: Uint8Array | null = null

      for (const candidate of signedPrekeys) {
        try {
          const secret = await acceptSession(
            keys!,
            candidate.keyPair.privateKey,
            fromBase64(entry.sender_identity_public),
            fromBase64(entry.ephemeral_public),
            oneTime?.keyPair.privateKey,
          )

          chainKey = await unwrapSenderKey(secret, {
            ciphertext: fromBase64(entry.wrapped_key),
            iv: fromBase64(entry.wrap_iv),
          })
          break
        } catch {
          // Wrong prekey, or not for us at all. Try the next.
        }
      }

      // Nothing opened it: a prekey rotated out of the window, a one-time key already
      // consumed, or an impostor. That one sender's messages stay unreadable and everybody
      // else's still work — which is why this loop never rethrows.
      if (!chainKey) continue

      await keyStore.saveChain({
        id: chainKeyFor(channelId, entry.epoch, entry.sender_device_id),
        channelId,
        epoch: entry.epoch,
        deviceId: entry.sender_device_id,
        chainKey,
        index: 0,
        // A sender key as distributed is by definition the chain's root.
        rooted: true,
      })
    }
  }

  /**
   * If we already have a chain in this channel, make sure everyone currently here has it.
   *
   * Called when a channel is opened, not only when a message is sent, so that a device which
   * turns up mid-conversation starts being able to read as soon as any existing member so
   * much as *looks* at the channel — rather than having to wait for each of them to type
   * something.
   *
   * The retroactive part is the good bit, and it falls out of the design rather than being
   * arranged: a sender key is the *root* of an epoch's chain, so handing it over lets the new
   * device derive every message key in that era, including the ones from before it existed.
   * One late distribution unlocks the whole epoch.
   *
   * Deliberately does *not* create a chain. Opening a channel you have never spoken in should
   * cost nothing and spend nobody's prekeys.
   */
  async function announcePresence(channelId: number, epoch: number): Promise<void> {
    if (epoch < 1) return

    await bootstrap()

    const stored = await requireStore().loadChain(channelId, epoch, deviceId.value!)

    /*
     * A chain left behind by the build that persisted the ratcheted key is repaired here, on
     * open, rather than waiting for the next send.
     *
     * Waiting was the original behaviour and it is too passive: somebody looking at a channel
     * where nothing renders has no reason to guess that *typing something* is what fixes it.
     * Replacing it the moment the channel is opened means the damage is confined to the
     * messages already sent under the broken chain — which are unrecoverable on this device
     * regardless, since the root they would need was overwritten.
     *
     * Only when a broken chain actually exists. Minting one for a channel this device has
     * never spoken in would spend everybody's prekeys for nothing.
     */
    if (stored && !stored.rooted) {
      const replacement = createChain(epoch, deviceId.value!)
      await saveChain(channelId, replacement, [])
      await distribute(channelId, replacement)

      return
    }

    if (!stored?.rooted) return

    await topUpDistribution(
      channelId,
      { epoch, deviceId: deviceId.value!, chainKey: stored.chainKey, index: stored.index },
      stored.distributedTo ?? [],
    )
  }

  /** The chain for reading a particular sender's messages, if we have one. */
  async function chainForSender(
    channelId: number,
    epoch: number,
    senderDeviceId: string,
  ): Promise<SenderChain | null> {
    const stored = await requireStore().loadChain(channelId, epoch, senderDeviceId)

    return stored
      ? { epoch, deviceId: senderDeviceId, chainKey: stored.chainKey, index: stored.index }
      : null
  }

  /** Signing out. Every key for this account goes, and its messages become unreadable here. */
  async function forget(): Promise<void> {
    await requireStore().clear()
    keys = null
    signedPrekeys = []
    lastSignature = null
    store = null
    deviceId.value = null
    ready.value = false
  }

  return {
    deviceId,
    ready,
    bootstrap,
    refillPrekeys,
    announcePresence,
    chainFor,
    saveChain,
    collectInbox,
    chainForSender,
    forget,
  }
}
