/**
 * Devices, prekeys, and how two of them agree on a secret without ever being online together.
 *
 * The problem this solves: Ana wants to send Ben a sender key, Ben's laptop is shut, and the
 * server must not learn the key on the way. The answer is the X3DH shape — Ben publishes a
 * small bundle of public keys in advance, Ana combines several Diffie-Hellmans against it,
 * and Ben can reproduce the same secret whenever he next opens the app. Nothing is
 * interactive, and the server only ever holds public halves.
 *
 * Why several DHs rather than one. Each contributes a different property, and dropping any
 * of them costs something real:
 *
 *  - identity × signed prekey — Ana proves it was *her*; a stranger can't forge the session.
 *  - ephemeral × identity — Ben knows it was addressed to *him*, not replayed from elsewhere.
 *  - ephemeral × signed prekey — freshness, so two sessions between the same pair differ.
 *  - ephemeral × one-time prekey — forward secrecy. This is the one that matters most: the
 *    one-time key is deleted on use, so a device seized next year cannot reconstruct the
 *    secret even with every long-term key it holds.
 *
 * The last is optional, because one-time prekeys can run out — a popular account's stock can
 * be drained faster than the client refills it. A session without one is still secure
 * against everyone except a future compromise of that specific device, which is the standard
 * tradeoff and much better than refusing to start a conversation.
 *
 * The signature on the signed prekey is the load-bearing defence against the server itself.
 * Without it, a server wanting to read a channel would publish its own prekey in Ben's name
 * and sit in the middle of every session. {@link verifyBundle} is what makes that fail, and
 * it is the one check in this file that must never be skipped for convenience.
 */

import {
  agree,
  derive,
  deriveBytes,
  exportPublicKey,
  generateEphemeralKeyPair,
  generateIdentityKeyPair,
  generateSigningKeyPair,
  importAesKey,
  importPublicKey,
  importVerifyKey,
  open,
  seal,
  sign,
  toBase64,
  verify,
  type Sealed,
} from './primitives'

/** HKDF purpose for the session secret. Distinct strings, for the reason in `senderKey.ts`. */
const SESSION_INFO = 'side-chat/x3dh/session'

/**
 * Domain separation for a wrapped sender key, as authenticated data rather than a second
 * derivation.
 *
 * The obvious shape — derive a separate wrapping key from the session secret — can't be
 * written: the session secret is non-extractable by design, so its bytes are not available
 * to feed to HKDF. Rather than weaken that (making the secret extractable would put the
 * session key itself within reach of any script on the page), the session secret is used
 * directly and this string is bound in as additional authenticated data. It buys the same
 * thing the separate derivation would: a blob sealed for one purpose cannot be opened as
 * though it were another.
 */
const WRAP_CONTEXT = new TextEncoder().encode('side-chat/x3dh/sender-key-wrap')

/** This device's keypairs, before the server has named it. */
export interface DeviceKeys {
  identity: CryptoKeyPair
  signing: CryptoKeyPair
}

/**
 * What a device publishes so others can start sessions with it.
 *
 * Public keys only — this is designed to be handed to the server and served to anyone who
 * asks. Base64 because it travels as JSON.
 */
export interface PrekeyBundle {
  deviceId: string
  identityPublic: string
  signingPublic: string
  signedPrekey: string
  /** The signed prekey, signed by the signing key. The whole point. */
  prekeySignature: string
  /** Present when the server still had one in stock. */
  oneTimePrekey?: string
  oneTimePrekeyId?: string
}

/** A fresh device. Private halves are non-extractable and stay on this machine. */
export async function createDevice(): Promise<DeviceKeys> {
  return {
    identity: await generateIdentityKeyPair(),
    signing: await generateSigningKeyPair(),
  }
}

/**
 * The signed prekey and its signature.
 *
 * Extractable, unlike the identity key: its private half has to be kept and reloaded across
 * restarts to answer sessions started while this device was offline. Rotated periodically —
 * a long-lived signed prekey widens the window in which a stolen one is useful.
 */
export async function createSignedPrekey(
  device: DeviceKeys,
): Promise<{ keyPair: CryptoKeyPair; publicKey: Uint8Array; signature: Uint8Array }> {
  const keyPair = await generateEphemeralKeyPair()
  const publicKey = await exportPublicKey(keyPair.publicKey)
  const signature = await sign(device.signing.privateKey, publicKey)

  return { keyPair, publicKey, signature }
}

/**
 * Is this bundle really from the device it claims to be?
 *
 * Returns false rather than throwing — an unverifiable bundle is a decision, not a crash —
 * but a false here means *do not start a session*. There is no degraded mode worth having:
 * a bundle that fails this check is either corrupt or an attack, and both mean the key
 * exchange has to stop.
 */
export async function verifyBundle(bundle: PrekeyBundle): Promise<boolean> {
  try {
    const signingKey = await importVerifyKey(fromBase64Safe(bundle.signingPublic))

    return await verify(
      signingKey,
      fromBase64Safe(bundle.prekeySignature),
      fromBase64Safe(bundle.signedPrekey),
    )
  } catch {
    // Malformed base64, a key that isn't on the curve, a signature of the wrong length —
    // all of it is "no" rather than an exception for the caller to handle separately.
    return false
  }
}

/**
 * Ana's side: derive the shared secret from Ben's published bundle.
 *
 * Verifies the bundle first and refuses outright if it doesn't check out. That refusal is a
 * thrown error rather than a null, because unlike a malformed message — which is routine and
 * gets drawn as "can't read this" — a bundle that fails verification while the app is trying
 * to *start* a conversation is either a bug or an attack, and it must not be possible to
 * ignore the return value and carry on.
 *
 * Returns the ephemeral public key alongside the secret: Ben needs it to reproduce the same
 * derivation, and it travels with the wrapped sender key.
 */
export async function initiateSession(
  device: DeviceKeys,
  bundle: PrekeyBundle,
): Promise<{ secret: CryptoKey; ephemeralPublic: Uint8Array }> {
  if (!(await verifyBundle(bundle))) {
    throw new Error(`prekey bundle for device ${bundle.deviceId} failed signature verification`)
  }

  const ephemeral = await generateEphemeralKeyPair()

  const theirIdentity = await importPublicKey(fromBase64Safe(bundle.identityPublic))
  const theirSignedPrekey = await importPublicKey(fromBase64Safe(bundle.signedPrekey))

  const parts = [
    await agree(device.identity.privateKey, theirSignedPrekey),
    await agree(ephemeral.privateKey, theirIdentity),
    await agree(ephemeral.privateKey, theirSignedPrekey),
  ]

  if (bundle.oneTimePrekey) {
    const theirOneTime = await importPublicKey(fromBase64Safe(bundle.oneTimePrekey))
    parts.push(await agree(ephemeral.privateKey, theirOneTime))
  }

  return {
    secret: await derive(concat(parts), SESSION_INFO),
    ephemeralPublic: await exportPublicKey(ephemeral.publicKey),
  }
}

/**
 * Ben's side: reproduce the same secret from what Ana sent.
 *
 * The mirror image — the same agreements with the roles swapped, in the same order. The
 * order is part of the protocol: concatenating the same DH outputs differently produces a
 * different secret, and the failure would look like "decryption doesn't work" rather than
 * anything pointing at this function.
 *
 * `oneTimePrivate` is whatever the store had for the prekey id Ana claimed, or undefined if
 * she used none. Passing the wrong one produces a secret that simply doesn't match, which
 * surfaces as an undecryptable sender key.
 */
export async function acceptSession(
  device: DeviceKeys,
  signedPrekeyPrivate: CryptoKey,
  theirIdentityPublic: Uint8Array,
  theirEphemeralPublic: Uint8Array,
  oneTimePrivate?: CryptoKey,
): Promise<CryptoKey> {
  const theirIdentity = await importPublicKey(theirIdentityPublic)
  const theirEphemeral = await importPublicKey(theirEphemeralPublic)

  const parts = [
    await agree(signedPrekeyPrivate, theirIdentity),
    await agree(device.identity.privateKey, theirEphemeral),
    await agree(signedPrekeyPrivate, theirEphemeral),
  ]

  if (oneTimePrivate) parts.push(await agree(oneTimePrivate, theirEphemeral))

  return derive(concat(parts), SESSION_INFO)
}

/**
 * Wrap a sender key for one recipient device, under the session secret.
 *
 * This is the only time a sender key is ever bytes on the wire, and it happens once per
 * recipient device per epoch — not once per message. The chain key is exported here, which
 * is why sender keys are created extractable while identity keys are not: the whole purpose
 * of a sender key is to be given to somebody else.
 */
export async function wrapSenderKey(
  sessionSecret: CryptoKey,
  chainKey: Uint8Array,
): Promise<Sealed> {
  return seal(sessionSecret, chainKey, WRAP_CONTEXT)
}

/** Unwrap what {@link wrapSenderKey} produced. Throws if the session secret is wrong. */
export async function unwrapSenderKey(
  sessionSecret: CryptoKey,
  sealed: Sealed,
): Promise<Uint8Array> {
  return open(sessionSecret, sealed, WRAP_CONTEXT)
}

/**
 * The short string a person can read aloud to check they're talking to who they think.
 *
 * The only defence against a server that swaps a device's identity key *before* anybody has
 * ever seen the real one — no amount of signature checking helps if the impostor's key is
 * the first one you were shown. Two people comparing this over any channel the server
 * doesn't control (out loud, in person) close that hole.
 *
 * Both identity keys go in sorted order so each side computes the same string regardless of
 * who is asking, and it is derived rather than truncated so that no part of it is a piece
 * of an actual key.
 */
export async function safetyNumber(
  ourIdentityPublic: Uint8Array,
  theirIdentityPublic: Uint8Array,
): Promise<string> {
  const [first, second] = [toBase64(ourIdentityPublic), toBase64(theirIdentityPublic)].sort()

  const digest = await deriveBytes(
    new TextEncoder().encode(`${first}|${second}`),
    'side-chat/safety-number',
    30,
  )

  // Groups of five digits, the way Signal does it: long enough to be infeasible to collide,
  // chunked so two people can actually read it to each other without losing their place.
  return Array.from({ length: 6 }, (_, group) =>
    Array.from(digest.slice(group * 5, group * 5 + 5))
      .map(byte => (byte % 10).toString())
      .join(''),
  ).join(' ')
}

/** Join DH outputs into one buffer for the KDF. Order matters — see {@link acceptSession}. */
function concat(parts: Uint8Array[]): Uint8Array {
  const total = parts.reduce((sum, part) => sum + part.length, 0)
  const joined = new Uint8Array(total)

  let offset = 0
  for (const part of parts) {
    joined.set(part, offset)
    offset += part.length
  }

  return joined
}

/** Base64 that throws on rubbish rather than returning a short buffer that fails later. */
function fromBase64Safe(encoded: string): Uint8Array {
  const binary = atob(encoded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

  return bytes
}

/** Re-exported so callers building a bundle don't reach into primitives themselves. */
export { importAesKey, exportPublicKey }
