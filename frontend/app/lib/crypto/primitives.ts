/**
 * The cryptography, and nothing above it.
 *
 * Everything here is WebCrypto — no dependency, no WASM, no hand-written cipher. The one
 * rule this file exists to enforce is that nobody else in the app calls `crypto.subtle`
 * directly: primitives are easy to hold wrong (a reused IV, a key imported extractable
 * "just for now", a comparison that returns early) and the mistakes are silent. Everything
 * that needs crypto goes through a named function here, and the names say what they're for.
 *
 * The curve is **P-256**, not the X25519/Ed25519 a Signal-shaped design would reach for.
 * Not a preference — a portability constraint. This bundle is shipped three ways (browser,
 * Electron, Capacitor WebView), and Curve25519 in WebCrypto is recent enough that some of
 * those runtimes don't have it. A key agreement that works everywhere beats a marginally
 * nicer one that strands mobile users with messages they can't read. P-256 with ECDH,
 * HKDF-SHA256 and AES-256-GCM is a conservative, universally available suite.
 *
 * Private keys are generated **non-extractable** wherever they can be. A non-extractable
 * CryptoKey can be used but never read back out — not by this code, not by a script that
 * gets injected into the page later. That is the single most valuable property in the file,
 * and it's why identity keys are handled as live CryptoKey objects rather than as bytes.
 */

/** AES-GCM's nonce. 96 bits is the size the spec is built around. */
const IV_BYTES = 12

/** The AES key size everything here uses. */
const AES_BITS = 256

/**
 * What an encrypt returns and a decrypt needs: the bytes, and the nonce they were sealed
 * under. Kept together because they are meaningless apart, and because the commonest way
 * to break GCM is to lose track of which IV went with which ciphertext and reuse one.
 */
export interface Sealed {
  ciphertext: Uint8Array
  iv: Uint8Array
}

/** Cryptographically random bytes. The only source of randomness this app should use. */
export function randomBytes(length: number): Uint8Array {
  return crypto.getRandomValues(new Uint8Array(length))
}

/**
 * Seal bytes under a key.
 *
 * A fresh random IV every single time, generated in here rather than accepted as an
 * argument. Callers cannot pass one in, so callers cannot reuse one — and an AES-GCM key
 * that encrypts twice under the same IV leaks the relationship between both plaintexts.
 * The 96-bit random IV is safe for far more messages than any one key here will ever see,
 * because the sender-key ratchet retires each message key after a single use.
 *
 * `additionalData` is authenticated but not encrypted: pass the things that must not be
 * tampered with even though they travel in the clear (the epoch, the sender's device id).
 * Decryption fails outright if they don't match, which turns "an attacker replayed this
 * message into a different channel" from a subtle problem into a thrown error.
 */
export async function seal(
  key: CryptoKey,
  plaintext: Uint8Array,
  additionalData?: Uint8Array,
): Promise<Sealed> {
  const iv = randomBytes(IV_BYTES)

  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt(
      {
        name: 'AES-GCM',
        iv: iv as BufferSource,
        ...(additionalData ? { additionalData: additionalData as BufferSource } : {}),
      },
      key,
      plaintext as BufferSource,
    ),
  )

  return { ciphertext, iv }
}

/**
 * Open what {@link seal} sealed.
 *
 * Throws on any failure, and deliberately doesn't say which — wrong key, wrong IV, wrong
 * additional data and deliberate tampering are all the same event to a caller, and telling
 * them apart is exactly the oracle an attacker wants. The UI's job is to draw "can't read
 * this message", not to explain why.
 */
export async function open(
  key: CryptoKey,
  sealed: Sealed,
  additionalData?: Uint8Array,
): Promise<Uint8Array> {
  const plaintext = await crypto.subtle.decrypt(
    {
      name: 'AES-GCM',
      iv: sealed.iv as BufferSource,
      ...(additionalData ? { additionalData } : {}),
    },
    key,
    sealed.ciphertext as BufferSource,
  )

  return new Uint8Array(plaintext)
}

/**
 * An AES-256-GCM key from raw bytes.
 *
 * `extractable` defaults to false and should stay false for anything long-lived. It is
 * true only where the key itself is the thing being moved — a sender key being wrapped for
 * another device, a backup blob being built — and each of those callers says so explicitly.
 */
export async function importAesKey(raw: Uint8Array, extractable = false): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', raw as BufferSource, 'AES-GCM', extractable, [
    'encrypt',
    'decrypt',
  ])
}

/** A brand-new random AES-256-GCM key. */
export async function generateAesKey(extractable = false): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: AES_BITS }, extractable, [
    'encrypt',
    'decrypt',
  ])
}

/** The raw bytes of an AES key — only possible if it was created extractable. */
export async function exportAesKey(key: CryptoKey): Promise<Uint8Array> {
  return new Uint8Array(await crypto.subtle.exportKey('raw', key))
}

/**
 * A device's long-term identity keypair: the thing that *is* the device.
 *
 * Non-extractable, so it cannot be copied off the machine even by this code — which is what
 * makes "your other device" a meaningfully different party rather than a copy of this one.
 * Used only for key agreement; signing is a separate keypair (see below) because a key that
 * both agrees and signs makes each use harder to reason about and some protocol attacks
 * possible.
 */
export async function generateIdentityKeyPair(): Promise<CryptoKeyPair> {
  return crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, [
    'deriveKey',
    'deriveBits',
  ])
}

/**
 * A device's signing keypair: what makes its published prekeys provably its own.
 *
 * Without it, a server that wanted to read a conversation could hand out its own prekey in
 * place of a device's and sit in the middle of every session. The signature is what lets a
 * recipient refuse.
 */
export async function generateSigningKeyPair(): Promise<CryptoKeyPair> {
  return crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, [
    'sign',
    'verify',
  ])
}

/**
 * An ephemeral keypair, used once and thrown away.
 *
 * Extractable, unlike the identity keys, because a one-time prekey's private half has to be
 * stored until it's claimed. Its whole value is being short-lived: it is deleted the moment
 * it's used, which is what gives a session forward secrecy against a device compromised
 * later.
 */
export async function generateEphemeralKeyPair(): Promise<CryptoKeyPair> {
  return crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, [
    'deriveKey',
    'deriveBits',
  ])
}

/** A public key as bytes, for publishing to the server. */
export async function exportPublicKey(key: CryptoKey): Promise<Uint8Array> {
  return new Uint8Array(await crypto.subtle.exportKey('raw', key))
}

/** Somebody else's ECDH public key, read back from what the server handed us. */
export async function importPublicKey(raw: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    raw as BufferSource,
    { name: 'ECDH', namedCurve: 'P-256' },
    true,
    [],
  )
}

/** Somebody else's ECDSA public key — for checking a signature, never for making one. */
export async function importVerifyKey(raw: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    raw as BufferSource,
    { name: 'ECDSA', namedCurve: 'P-256' },
    true,
    ['verify'],
  )
}

/** Sign bytes with a device's signing key. */
export async function sign(key: CryptoKey, data: Uint8Array): Promise<Uint8Array> {
  return new Uint8Array(
    await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, data as BufferSource),
  )
}

/**
 * Check a signature.
 *
 * Returns false rather than throwing, because an unverifiable prekey is an ordinary thing
 * to encounter and a decision to make — not an exception. Every caller must actually branch
 * on it; a `verify()` whose result is dropped is the same as no signature at all.
 */
export async function verify(
  key: CryptoKey,
  signature: Uint8Array,
  data: Uint8Array,
): Promise<boolean> {
  return crypto.subtle.verify(
    { name: 'ECDSA', hash: 'SHA-256' },
    key,
    signature as BufferSource,
    data as BufferSource,
  )
}

/**
 * The shared secret two devices can compute and nobody watching can.
 *
 * Raw bits, not a key: ECDH output is a curve point and should never be used as key
 * material directly. Everything that calls this feeds the result through {@link derive}
 * first, which is what turns a biased shared secret into a uniform key.
 */
export async function agree(privateKey: CryptoKey, publicKey: CryptoKey): Promise<Uint8Array> {
  return new Uint8Array(
    await crypto.subtle.deriveBits({ name: 'ECDH', public: publicKey }, privateKey, 256),
  )
}

/**
 * HKDF: turn key material into a key for one specific purpose.
 *
 * `info` is the purpose, and it is not decoration. Two keys derived from the same secret
 * with different `info` strings are unrelated, which is what lets one agreement produce a
 * session key, a chain key and a message key that can't be substituted for one another.
 * Every caller passes a distinct, literal string.
 */
export async function derive(
  material: Uint8Array,
  info: string,
  salt: Uint8Array = new Uint8Array(32),
  extractable = false,
): Promise<CryptoKey> {
  const base = await crypto.subtle.importKey('raw', material as BufferSource, 'HKDF', false, [
    'deriveKey',
  ])

  return crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: salt as BufferSource,
      info: utf8(info) as BufferSource,
    },
    base,
    { name: 'AES-GCM', length: AES_BITS },
    extractable,
    ['encrypt', 'decrypt'],
  )
}

/**
 * HKDF again, but yielding bytes instead of a key.
 *
 * The ratchet needs this: a chain key is not an AES key, it is the input to the next
 * derivation, and importing it as one would be a category error that invites using it to
 * encrypt something.
 */
export async function deriveBytes(
  material: Uint8Array,
  info: string,
  length = 32,
  salt: Uint8Array = new Uint8Array(32),
): Promise<Uint8Array> {
  const base = await crypto.subtle.importKey('raw', material as BufferSource, 'HKDF', false, [
    'deriveBits',
  ])

  return new Uint8Array(
    await crypto.subtle.deriveBits(
      {
        name: 'HKDF',
        hash: 'SHA-256',
        salt: salt as BufferSource,
        info: utf8(info) as BufferSource,
      },
      base,
      length * 8,
    ),
  )
}

/* Encoding. Boring, and worth having in one place so the wire format can't drift. */

export function utf8(text: string): Uint8Array {
  return new TextEncoder().encode(text)
}

export function fromUtf8(bytes: Uint8Array): string {
  return new TextDecoder().decode(bytes)
}

/** Base64, because the transport is JSON and the column is text. */
export function toBase64(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)

  return btoa(binary)
}

export function fromBase64(encoded: string): Uint8Array {
  const binary = atob(encoded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

  return bytes
}

/**
 * Compare two byte strings without leaking where they first differ.
 *
 * A plain `===` on two arrays would be an identity check, and a naive loop that returns
 * early on mismatch tells a patient attacker how much of a guess was right. Every byte is
 * examined either way.
 */
export function equalBytes(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false

  let difference = 0
  for (let i = 0; i < a.length; i++) difference |= a[i]! ^ b[i]!

  return difference === 0
}
