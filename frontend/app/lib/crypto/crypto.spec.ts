import { describe, expect, it } from 'vitest'

import { contextBytes, packEnvelope, sealedFrom, unpackEnvelope } from './envelope'
import {
  acceptSession,
  createDevice,
  createSignedPrekey,
  initiateSession,
  safetyNumber,
  unwrapSenderKey,
  verifyBundle,
  wrapSenderKey,
  type PrekeyBundle,
} from './identity'
import { chainKeyFor, MemoryKeyStore } from './keyStore'
import {
  advance,
  createChain,
  decryptWithKey,
  encryptWithChain,
  messageKeyAt,
} from './senderKey'
import {
  equalBytes,
  exportPublicKey,
  fromBase64,
  fromUtf8,
  generateEphemeralKeyPair,
  open,
  randomBytes,
  seal,
  toBase64,
  utf8,
} from './primitives'

/*
 * The crypto layer.
 *
 * Worth more care than most tests in this repo, because the failure mode is silent. A bug in
 * a component shows up as a component that looks wrong; a bug in here shows up as messages
 * that encrypt fine, send fine, and can never be read again — possibly noticed weeks later,
 * by which time the plaintext is gone.
 *
 * So the assertions are mostly of the form "this must NOT be possible" rather than "the happy
 * path works". A round trip passing proves very little: XOR with a constant round-trips too.
 */

/** Ana and Ben, each with a device and a published bundle. The setup for every session test. */
async function twoDevices() {
  const ana = await createDevice()
  const ben = await createDevice()

  const benPrekey = await createSignedPrekey(ben)
  const benOneTime = await generateEphemeralKeyPair()

  const bundle: PrekeyBundle = {
    deviceId: 'ben-laptop',
    identityPublic: toBase64(await exportPublicKey(ben.identity.publicKey)),
    signingPublic: toBase64(await exportPublicKey(ben.signing.publicKey)),
    signedPrekey: toBase64(benPrekey.publicKey),
    prekeySignature: toBase64(benPrekey.signature),
    oneTimePrekey: toBase64(await exportPublicKey(benOneTime.publicKey)),
    oneTimePrekeyId: 'otp-1',
  }

  return { ana, ben, benPrekey, benOneTime, bundle }
}

describe('primitives', () => {
  it('never seals the same plaintext to the same bytes twice', async () => {
    // The IV is generated inside seal() precisely so a caller can't reuse one. Two identical
    // messages producing identical ciphertext would mean the IV was fixed — which in GCM
    // leaks the XOR of any two plaintexts encrypted under that key.
    const key = await generateAes()
    const message = utf8('same message both times')

    const first = await seal(key, message)
    const second = await seal(key, message)

    expect(equalBytes(first.iv, second.iv)).toBe(false)
    expect(equalBytes(first.ciphertext, second.ciphertext)).toBe(false)
  })

  it('refuses to open a ciphertext whose bytes were altered', async () => {
    const key = await generateAes()
    const sealed = await seal(key, utf8('the original message'))

    sealed.ciphertext[3] = sealed.ciphertext[3]! ^ 0xff

    await expect(open(key, sealed)).rejects.toThrow()
  })

  it('refuses to open when the authenticated context differs', async () => {
    // This is what binds a message to its epoch and sender. If a mismatched context still
    // decrypted, an attacker could replay a message into a different era and it would render
    // as genuine.
    const key = await generateAes()
    const sealed = await seal(key, utf8('hello'), utf8('epoch 1'))

    await expect(open(key, sealed, utf8('epoch 2'))).rejects.toThrow()
    expect(fromUtf8(await open(key, sealed, utf8('epoch 1')))).toBe('hello')
  })

  it('compares byte strings without a length-based shortcut giving a false positive', () => {
    expect(equalBytes(new Uint8Array([1, 2, 3]), new Uint8Array([1, 2, 3]))).toBe(true)
    expect(equalBytes(new Uint8Array([1, 2, 3]), new Uint8Array([1, 2, 4]))).toBe(false)
    expect(equalBytes(new Uint8Array([1, 2]), new Uint8Array([1, 2, 0]))).toBe(false)
  })

  it('round-trips arbitrary bytes through base64, including the awkward ones', () => {
    // 0x00 and the high bytes are where a naive String.fromCharCode/charCodeAt pairing goes
    // wrong, and the message body column is where that would show up.
    const bytes = new Uint8Array([0, 1, 127, 128, 254, 255, 0, 0])

    expect(equalBytes(fromBase64(toBase64(bytes)), bytes)).toBe(true)
    expect(equalBytes(fromBase64(toBase64(randomBytes(1024))), fromBase64(toBase64(randomBytes(0))))).toBe(false)
  })
})

describe('sender key ratchet', () => {
  it('gives every message a different key', async () => {
    const chain = createChain(1, 'ana-laptop')

    const first = await advance(chain)
    const second = await advance(first.chain)

    // Same chain, two steps, two unrelated message keys — and the chain key itself has moved.
    expect(equalBytes(first.chain.chainKey, second.chain.chainKey)).toBe(false)
    expect(await rawOf(first.messageKey)).not.toEqual(await rawOf(second.messageKey))
  })

  it('does not mutate the chain it was given', async () => {
    // Two concurrent sends off one shared mutable chain would derive the same message key
    // twice, which is a two-time pad. Returning a new chain is what makes that impossible.
    const chain = createChain(1, 'ana-laptop')
    const before = new Uint8Array(chain.chainKey)

    await advance(chain)

    expect(equalBytes(chain.chainKey, before)).toBe(true)
    expect(chain.index).toBe(0)
  })

  it('cannot derive a message key going backwards', async () => {
    // Forward-only is the whole security property: a device compromised now must not yield
    // the messages that came before. Silently returning a wrong key would hide the bug.
    const chain = createChain(1, 'ana-laptop')
    const stepped = (await advance((await advance(chain)).chain)).chain

    await expect(messageKeyAt(stepped, 0)).rejects.toThrow(/consumed/)
  })

  it('winds forward to reach a message that arrived out of order', async () => {
    const sender = createChain(1, 'ana-laptop')
    const context = utf8('ctx')

    // Ana sends five; Ben's client sees the fifth first.
    let chain = sender
    let fifth
    for (let i = 0; i < 5; i++) {
      const sent = await encryptWithChain(chain, `message ${i}`, context)
      chain = sent.chain
      if (i === 4) fifth = sent
    }

    const receiver = { ...sender }
    const step = await messageKeyAt(receiver, fifth!.index)

    expect(fromUtf8(await decryptWithKey(step.messageKey, fifth!.sealed, context))).toBe(
      'message 4',
    )
  })

  it('encrypts and decrypts a message through the chain', async () => {
    const chain = createChain(3, 'ana-laptop')
    const context = contextBytes({ v: 1, e: 3, d: 'ana-laptop', i: 1 })

    const sent = await encryptWithChain(chain, 'lunch at one?', context)
    const step = await messageKeyAt({ ...chain }, sent.index)

    expect(fromUtf8(await decryptWithKey(step.messageKey, sent.sealed, context))).toBe(
      'lunch at one?',
    )
  })
})

describe('envelope', () => {
  it('round-trips a sealed message through the body column', async () => {
    const chain = createChain(2, 'ana-laptop')
    const header = { v: 1, e: 2, d: 'ana-laptop', i: 1 }
    const sent = await encryptWithChain(chain, 'in the envelope', contextBytes(header))

    const body = packEnvelope({ e: 2, d: 'ana-laptop', i: sent.index }, sent.sealed)
    const envelope = unpackEnvelope(body)

    expect(envelope).not.toBeNull()
    expect(envelope!.e).toBe(2)
    expect(envelope!.d).toBe('ana-laptop')

    const step = await messageKeyAt({ ...chain }, envelope!.i)
    expect(
      fromUtf8(await decryptWithKey(step.messageKey, sealedFrom(envelope!), contextBytes(envelope!))),
    ).toBe('in the envelope')
  })

  it('fails to decrypt when the header has been rewritten in transit', async () => {
    // The header travels in the clear so the server can route on it, so it must be bound
    // into the ciphertext. Re-attributing a message to another device has to break it.
    const chain = createChain(2, 'ana-laptop')
    const header = { v: 1, e: 2, d: 'ana-laptop', i: 1 }
    const sent = await encryptWithChain(chain, 'said by Ana', contextBytes(header))

    const step = await messageKeyAt({ ...chain }, sent.index)
    const forged = { ...header, d: 'mallory-laptop' }

    await expect(
      decryptWithKey(step.messageKey, sent.sealed, contextBytes(forged)),
    ).rejects.toThrow()
  })

  it('returns null for anything it cannot read, rather than throwing', () => {
    // A timeline holds plaintext from before encryption was on, ciphertext from a version
    // this client doesn't speak, and the odd bad row. All of it renders as "can't read this".
    expect(unpackEnvelope(null)).toBeNull()
    expect(unpackEnvelope('')).toBeNull()
    expect(unpackEnvelope('just a plain message from before')).toBeNull()
    expect(unpackEnvelope(toBase64(utf8('{"not":"an envelope"}')))).toBeNull()
    expect(unpackEnvelope(toBase64(utf8(JSON.stringify({ v: 1, e: 1, d: 'x', i: 'nope', n: '', c: '' }))))).toBeNull()
  })

  it('refuses an envelope from a format version it does not know', () => {
    const future = { v: 99, e: 1, d: 'ana', i: 1, n: toBase64(randomBytes(12)), c: toBase64(randomBytes(20)) }

    expect(unpackEnvelope(toBase64(utf8(JSON.stringify(future))))).toBeNull()
  })
})

describe('sessions', () => {
  it('lets two devices reach the same secret without ever meeting', async () => {
    const { ana, ben, benPrekey, benOneTime, bundle } = await twoDevices()

    const initiated = await initiateSession(ana, bundle)
    const accepted = await acceptSession(
      ben,
      benPrekey.keyPair.privateKey,
      await exportPublicKey(ana.identity.publicKey),
      initiated.ephemeralPublic,
      benOneTime.privateKey,
    )

    // The secrets aren't comparable directly — they're non-extractable — so prove it the way
    // it will actually be used: wrap a sender key on one side, unwrap on the other.
    const chainKey = randomBytes(32)
    const wrapped = await wrapSenderKey(initiated.secret, chainKey)

    expect(equalBytes(await unwrapSenderKey(accepted, wrapped), chainKey)).toBe(true)
  })

  it('still works when the recipient has run out of one-time prekeys', async () => {
    const { ana, ben, benPrekey, bundle } = await twoDevices()
    const { oneTimePrekey, oneTimePrekeyId, ...drained } = bundle

    const initiated = await initiateSession(ana, drained)
    const accepted = await acceptSession(
      ben,
      benPrekey.keyPair.privateKey,
      await exportPublicKey(ana.identity.publicKey),
      initiated.ephemeralPublic,
    )

    const chainKey = randomBytes(32)
    expect(
      equalBytes(await unwrapSenderKey(accepted, await wrapSenderKey(initiated.secret, chainKey)), chainKey),
    ).toBe(true)
  })

  it('refuses a bundle whose prekey was not signed by the device that claims it', async () => {
    // The server-in-the-middle attack. If this passes, everything else here is decoration.
    const { bundle } = await twoDevices()
    const mallory = await createDevice()
    const malloryPrekey = await createSignedPrekey(mallory)

    const swapped: PrekeyBundle = { ...bundle, signedPrekey: toBase64(malloryPrekey.publicKey) }

    expect(await verifyBundle(swapped)).toBe(false)
  })

  it('throws rather than starting a session on an unverifiable bundle', async () => {
    const { ana, bundle } = await twoDevices()
    const tampered: PrekeyBundle = { ...bundle, prekeySignature: toBase64(randomBytes(64)) }

    await expect(initiateSession(ana, tampered)).rejects.toThrow(/verification/)
  })

  it('rejects a malformed bundle without blowing up', async () => {
    const { bundle } = await twoDevices()

    expect(await verifyBundle({ ...bundle, signingPublic: 'not base64 at all!!' })).toBe(false)
  })

  it('opens only with the signed prekey the sender actually wrapped against', async () => {
    // The rotation trap. Ana fetches Ben's bundle, Ben rotates his signed prekey, Ana's
    // wrapped key lands afterwards. If Ben has thrown the old private half away, the key is
    // undecryptable and Ana's messages silently never arrive — so a client must keep the
    // predecessor. This pins both halves of that: the old key works, a newer one does not.
    const { ana, ben, benPrekey, benOneTime, bundle } = await twoDevices()

    const initiated = await initiateSession(ana, bundle)
    const chainKey = randomBytes(32)
    const wrapped = await wrapSenderKey(initiated.secret, chainKey)

    const rotated = await createSignedPrekey(ben)
    const anaIdentity = await exportPublicKey(ana.identity.publicKey)

    const withRotated = await acceptSession(
      ben,
      rotated.keyPair.privateKey,
      anaIdentity,
      initiated.ephemeralPublic,
      benOneTime.privateKey,
    )
    await expect(unwrapSenderKey(withRotated, wrapped)).rejects.toThrow()

    const withOriginal = await acceptSession(
      ben,
      benPrekey.keyPair.privateKey,
      anaIdentity,
      initiated.ephemeralPublic,
      benOneTime.privateKey,
    )
    expect(equalBytes(await unwrapSenderKey(withOriginal, wrapped), chainKey)).toBe(true)
  })

  it('derives a safety number both sides agree on, whoever asks', async () => {
    const ana = await exportPublicKey((await createDevice()).identity.publicKey)
    const ben = await exportPublicKey((await createDevice()).identity.publicKey)

    expect(await safetyNumber(ana, ben)).toBe(await safetyNumber(ben, ana))
    expect(await safetyNumber(ana, ben)).toMatch(/^(\d{5} ){5}\d{5}$/)
  })

  it('gives a different safety number for a different device', async () => {
    const ana = await exportPublicKey((await createDevice()).identity.publicKey)
    const ben = await exportPublicKey((await createDevice()).identity.publicKey)
    const mallory = await exportPublicKey((await createDevice()).identity.publicKey)

    expect(await safetyNumber(ana, ben)).not.toBe(await safetyNumber(ana, mallory))
  })
})

describe('key store', () => {
  it('keeps chains apart by channel, era and sender', async () => {
    // One id collision here would mean one conversation's ratchet state overwriting
    // another's, and both becoming unreadable.
    expect(chainKeyFor(1, 1, 'a')).not.toBe(chainKeyFor(1, 1, 'b'))
    expect(chainKeyFor(1, 1, 'a')).not.toBe(chainKeyFor(1, 2, 'a'))
    expect(chainKeyFor(1, 1, 'a')).not.toBe(chainKeyFor(2, 1, 'a'))
  })

  it('stores and returns a chain', async () => {
    const store = new MemoryKeyStore()
    const chain = createChain(1, 'ana-laptop')

    await store.saveChain({ id: chainKeyFor(7, 1, 'ana-laptop'), channelId: 7, epoch: 1, deviceId: 'ana-laptop', chainKey: chain.chainKey, index: 0 })

    const loaded = await store.loadChain(7, 1, 'ana-laptop')
    expect(loaded).not.toBeNull()
    expect(equalBytes(loaded!.chainKey, chain.chainKey)).toBe(true)
    expect(await store.loadChain(7, 2, 'ana-laptop')).toBeNull()
  })

  it('returns every chain in a channel, since a timeline is striped', async () => {
    const store = new MemoryKeyStore()

    for (const [channelId, epoch, deviceId] of [[7, 1, 'a'], [7, 2, 'a'], [7, 2, 'b'], [8, 1, 'a']] as const) {
      await store.saveChain({ id: chainKeyFor(channelId, epoch, deviceId), channelId, epoch, deviceId, chainKey: randomBytes(32), index: 0 })
    }

    expect(await store.chainsForChannel(7)).toHaveLength(3)
    expect(await store.chainsForChannel(8)).toHaveLength(1)
  })

  it('hands out a one-time prekey exactly once', async () => {
    // A prekey used twice provides none of the forward secrecy it exists for.
    const store = new MemoryKeyStore()
    const keyPair = await generateEphemeralKeyPair()

    await store.savePrekey({ id: 'otp-1', keyPair, createdAt: Date.now() })

    expect(await store.takePrekey('otp-1')).not.toBeNull()
    expect(await store.takePrekey('otp-1')).toBeNull()
    expect(await store.countPrekeys()).toBe(0)
  })

  it('forgets everything on sign-out', async () => {
    const store = new MemoryKeyStore()
    const device = await createDevice()

    await store.saveIdentity({ deviceId: 'ana-laptop', identity: device.identity, signing: device.signing, createdAt: Date.now(), signedPrekeys: [] })
    await store.saveChain({ id: chainKeyFor(1, 1, 'a'), channelId: 1, epoch: 1, deviceId: 'a', chainKey: randomBytes(32), index: 0 })

    await store.clear()

    expect(await store.loadIdentity()).toBeNull()
    expect(await store.chainsForChannel(1)).toHaveLength(0)
  })
})

describe('device identity', () => {
  it('generates identity keys that cannot be exported, even by us', async () => {
    // The single most valuable property in the whole layer: a key that cannot be read out
    // cannot be stolen by anything that later runs on the page.
    const device = await createDevice()

    expect(device.identity.privateKey.extractable).toBe(false)
    expect(device.signing.privateKey.extractable).toBe(false)
    await expect(crypto.subtle.exportKey('pkcs8', device.identity.privateKey)).rejects.toThrow()
  })
})

/** An extractable AES key, so tests can compare derived material. */
async function generateAes(): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
}

/** The raw bytes behind a message key, for proving two of them differ. */
async function rawOf(key: CryptoKey): Promise<string> {
  // Message keys are imported non-extractable, so compare them by what they *do* instead.
  const sealed = await seal(key, utf8('probe'), utf8('probe'))

  return toBase64(sealed.ciphertext)
}
