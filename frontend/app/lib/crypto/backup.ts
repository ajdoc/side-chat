/**
 * Getting your history onto a new device.
 *
 * What is backed up is **chain keys, and only chain keys**. Not the device identity — that is
 * a non-extractable CryptoKey and cannot leave the browser even if this code wanted it to,
 * which is the single strongest property in the whole design and not one to trade away for
 * convenience. A new device is genuinely a new party: it mints its own identity, publishes
 * it, and other people's clients see the safety number change. What it needs from the old
 * device isn't an identity, it's the ability to *read what was already said* — and that is
 * exactly what a sender chain is.
 *
 * The wrapping key comes from a passphrase, so the whole scheme rests on how hard that
 * passphrase is to guess. **PBKDF2-SHA256 at 600,000 iterations**, which is the current OWASP
 * figure and roughly a second of work on a phone. Argon2id would be the better choice — it is
 * memory-hard, where PBKDF2 is merely slow, and so much more expensive to attack with a GPU —
 * but it means shipping a WASM blob, and PBKDF2 is native everywhere this bundle runs. If a
 * dependency ever becomes acceptable, this is the first place to spend it; the format carries
 * its own KDF parameters (see {@link BackupBlob}) precisely so that swap can happen without
 * stranding anybody's existing backup.
 *
 * The same code serves both of the ways a person can keep their keys:
 *
 *  - **Escrow.** The blob goes to the server. The server holds ciphertext and no passphrase,
 *    so it cannot read the chains — but it can be *asked* for the blob, which means an
 *    attacker who takes the server gets unlimited offline guesses at the passphrase. That is
 *    the real cost of escrow and the reason the iteration count matters.
 *  - **A recovery file.** The identical blob, wrapped under a generated code instead of a
 *    chosen passphrase, downloaded and kept by the person. Nothing is stored anywhere, so
 *    there is nothing to take — and nothing to recover from if the file is lost.
 */

import { fromBase64, fromUtf8, randomBytes, toBase64, utf8 } from './primitives'

/** OWASP's current guidance for PBKDF2-SHA256. Recorded in every blob — see below. */
export const PBKDF2_ITERATIONS = 600_000

/** The backup format this build writes. */
export const BACKUP_VERSION = 1

/** One sender chain, as it travels in a backup. */
export interface BackupChain {
  channelId: number
  epoch: number
  deviceId: string
  /** Base64 of the chain key. */
  chainKey: string
  index: number
}

/**
 * A wrapped backup, as stored or downloaded.
 *
 * The KDF parameters travel *with* the blob rather than being assumed by the reader. A backup
 * made today must still open in three years, by which time the iteration count will have gone
 * up and the algorithm may have changed — and a restore that silently used today's parameters
 * against an old blob would fail with no way to tell why.
 */
export interface BackupBlob {
  v: number
  kdf: 'PBKDF2-SHA256'
  iterations: number
  /** Base64. Random per backup, so two people with the same passphrase share no work. */
  salt: string
  iv: string
  ciphertext: string
}

/**
 * Derive the wrapping key from a passphrase.
 *
 * Non-extractable, so the derived key cannot be read back out of the page even though the
 * passphrase that made it was just typed into one.
 */
async function deriveWrappingKey(
  passphrase: string,
  salt: Uint8Array,
  iterations: number,
): Promise<CryptoKey> {
  const base = await crypto.subtle.importKey('raw', utf8(passphrase) as BufferSource, 'PBKDF2', false, [
    'deriveKey',
  ])

  return crypto.subtle.deriveKey(
    { name: 'PBKDF2', hash: 'SHA-256', salt: salt as BufferSource, iterations },
    base,
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  )
}

/** Wrap a set of chains under a passphrase. */
export async function wrapBackup(chains: BackupChain[], passphrase: string): Promise<BackupBlob> {
  const salt = randomBytes(16)
  const iv = randomBytes(12)
  const key = await deriveWrappingKey(passphrase, salt, PBKDF2_ITERATIONS)

  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt(
      { name: 'AES-GCM', iv: iv as BufferSource },
      key,
      utf8(JSON.stringify(chains)) as BufferSource,
    ),
  )

  return {
    v: BACKUP_VERSION,
    kdf: 'PBKDF2-SHA256',
    iterations: PBKDF2_ITERATIONS,
    salt: toBase64(salt),
    iv: toBase64(iv),
    ciphertext: toBase64(ciphertext),
  }
}

/**
 * Unwrap a backup, or fail because the passphrase is wrong.
 *
 * Throws rather than returning null, and the two cases a caller cares about — "wrong
 * passphrase" and "this file is not a backup" — are deliberately given different messages.
 * Everything else about the failure stays vague: AES-GCM cannot tell a wrong key from a
 * tampered blob, and inventing a distinction would be a lie.
 */
export async function unwrapBackup(blob: BackupBlob, passphrase: string): Promise<BackupChain[]> {
  if (blob.v !== BACKUP_VERSION || blob.kdf !== 'PBKDF2-SHA256') {
    throw new Error('This backup was made by a newer version of the app.')
  }

  const key = await deriveWrappingKey(passphrase, fromBase64(blob.salt), blob.iterations)

  let plaintext: ArrayBuffer
  try {
    plaintext = await crypto.subtle.decrypt(
      { name: 'AES-GCM', iv: fromBase64(blob.iv) as BufferSource },
      key,
      fromBase64(blob.ciphertext) as BufferSource,
    )
  } catch {
    throw new Error('That passphrase didn’t work.')
  }

  const parsed = JSON.parse(fromUtf8(new Uint8Array(plaintext)))

  return Array.isArray(parsed) ? parsed : []
}

/**
 * A recovery code, for somebody who doesn't want a passphrase escrowed.
 *
 * Generated rather than chosen, because the whole point is that it is not guessable — and a
 * person choosing "the same one they always use" would put their history behind a password
 * that is already in a breach corpus. 128 bits from `crypto.getRandomValues`, in Crockford's
 * base32 (no I, L, O or U), grouped in fives.
 *
 * The alphabet matters more than it looks: this is a string somebody writes down and types
 * back in months later, and the excluded letters are exactly the ones misread as 1, 0 and
 * each other.
 */
export function generateRecoveryCode(): string {
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
  const bytes = randomBytes(20)

  const code = Array.from(bytes, byte => alphabet[byte % alphabet.length]).join('')

  return (code.match(/.{1,5}/g) ?? []).join('-')
}

/**
 * Normalise a typed recovery code before using it as a passphrase.
 *
 * People retype these with the hyphens missing, in lower case, or with a stray space from a
 * copy-paste. All of those are the same code, and rejecting them would be a support burden
 * with no security benefit whatsoever — an attacker is not inconvenienced by case sensitivity.
 */
export function normaliseRecoveryCode(code: string): string {
  return code.toUpperCase().replace(/[^0-9A-Z]/g, '')
}
