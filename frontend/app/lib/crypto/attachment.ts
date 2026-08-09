/**
 * Encrypting the bytes of a file, and getting them back.
 *
 * Separate from the message ratchet on purpose. A message key is used once and derived from a
 * chain; a *file* key is random, belongs to one file, and has to survive being handed to
 * whoever can read the message — which is why it travels in the envelope's `f` block rather
 * than being derived from anything. Keeping the two apart also means an attachment can be
 * re-fetched and re-decrypted any number of times without touching the ratchet.
 *
 * The whole file is sealed as one AES-GCM blob rather than in chunks. That is the right
 * tradeoff here and worth being explicit about, because it has a real limit: the browser
 * holds both the ciphertext and the plaintext in memory at once, so a very large file costs
 * roughly twice its size in RAM. Chunked, streaming encryption would fix that and would also
 * let a video start playing before it finished downloading — but it means framing, per-chunk
 * nonces, and a truncation defence, and none of that earns its complexity until somebody
 * actually sends a two-gigabyte file. One blob is one authentication tag over the entire
 * file, which is the strongest thing to say about its integrity.
 *
 * The IV rides at the front of the blob rather than in the metadata. It is not secret, it is
 * exactly twelve bytes, and putting it with the bytes it belongs to means a file can never be
 * separated from its nonce by a bug somewhere in between.
 */

import { generateAesKey, exportAesKey, importAesKey, randomBytes } from './primitives'

/** AES-GCM's nonce length, and therefore the prefix length on every encrypted file. */
const IV_BYTES = 12

/** A fresh key for one file. Extractable — its whole job is to be given to the recipients. */
export async function generateFileKey(): Promise<{ key: CryptoKey, raw: Uint8Array }> {
  const key = await generateAesKey(true)

  return { key, raw: await exportAesKey(key) }
}

/**
 * Encrypt a file for upload.
 *
 * Returns a `File` rather than a Blob so it drops straight into the existing multipart and
 * chunked upload paths untouched. The name is deliberately *not* the real one — it is what
 * the server will store, and a filename is often the most revealing part of a document. The
 * real name travels sealed in the envelope.
 */
export async function encryptFile(file: File, key: CryptoKey): Promise<File> {
  const iv = randomBytes(IV_BYTES)
  const plaintext = new Uint8Array(await file.arrayBuffer())

  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv as BufferSource }, key, plaintext as BufferSource),
  )

  // IV first, then the sealed bytes — see the note at the top of the file.
  const blob = new Uint8Array(iv.length + ciphertext.length)
  blob.set(iv, 0)
  blob.set(ciphertext, iv.length)

  return new File([blob], 'encrypted', { type: 'application/octet-stream' })
}

/**
 * Decrypt downloaded bytes back into a usable file.
 *
 * Throws if the bytes have been altered, truncated or swapped — AES-GCM authenticates the
 * whole file, so a server that tampered with an attachment cannot have it render as anything.
 * Callers draw "can't open this" rather than trying to salvage a partial result: half a
 * decrypted file is not a file.
 */
export async function decryptFile(
  bytes: ArrayBuffer,
  rawKey: Uint8Array,
  mime: string,
): Promise<Blob> {
  const all = new Uint8Array(bytes)

  if (all.length <= IV_BYTES) throw new Error('encrypted attachment is too short to be valid')

  const key = await importAesKey(rawKey)
  const plaintext = await crypto.subtle.decrypt(
    { name: 'AES-GCM', iv: all.slice(0, IV_BYTES) as BufferSource },
    key,
    all.slice(IV_BYTES) as BufferSource,
  )

  // The MIME type comes from the sealed metadata, never from the server — it is what makes
  // the browser willing to render the blob as an image, and letting the untrusted side pick
  // it would be handing over the content type of something the user is about to open.
  return new Blob([plaintext], { type: mime })
}
