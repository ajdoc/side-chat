import { describe, expect, it } from 'vitest'

import {
  BACKUP_VERSION,
  generateRecoveryCode,
  normaliseRecoveryCode,
  PBKDF2_ITERATIONS,
  unwrapBackup,
  wrapBackup,
  type BackupChain,
} from './backup'
import { toBase64 } from './primitives'

/*
 * Key backup.
 *
 * The tests are slower than the rest of the suite and that is the point — every wrap and
 * unwrap runs 600,000 PBKDF2 iterations, which is the cost an attacker pays per guess. A test
 * run that suddenly got fast would mean the iteration count had been dropped.
 *
 * Two properties matter more than the round trip: a wrong passphrase must fail rather than
 * return something, and two backups of the same data under the same passphrase must not look
 * alike. The second is the salt doing its job, and it is what stops "these two accounts have
 * the same password" being visible in the database.
 */

const chains: BackupChain[] = [
  { channelId: 7, epoch: 1, deviceId: 'ana-laptop', chainKey: toBase64(new Uint8Array(32).fill(1)), index: 4 },
  { channelId: 7, epoch: 2, deviceId: 'ben-phone', chainKey: toBase64(new Uint8Array(32).fill(2)), index: 0 },
]

describe('key backup', () => {
  it('round-trips chains through a passphrase', async () => {
    const blob = await wrapBackup(chains, 'correct horse battery staple')

    expect(await unwrapBackup(blob, 'correct horse battery staple')).toEqual(chains)
  })

  it('refuses the wrong passphrase instead of returning something', async () => {
    const blob = await wrapBackup(chains, 'the real passphrase')

    await expect(unwrapBackup(blob, 'not the passphrase')).rejects.toThrow(/didn’t work/)
  })

  it('produces a different blob every time, even for identical input', async () => {
    // The salt and IV are fresh per backup. Without that, two people with the same passphrase
    // would produce byte-identical rows, and the database would be advertising it.
    const first = await wrapBackup(chains, 'same passphrase')
    const second = await wrapBackup(chains, 'same passphrase')

    expect(first.salt).not.toBe(second.salt)
    expect(first.ciphertext).not.toBe(second.ciphertext)
  })

  it('stores nothing readable about the chains', async () => {
    const blob = await wrapBackup(chains, 'passphrase')
    const serialised = JSON.stringify(blob)

    // Neither a chain key nor a device name should be recoverable by reading the row.
    expect(serialised).not.toContain('ana-laptop')
    expect(serialised).not.toContain(chains[0]!.chainKey)
  })

  it('records the KDF parameters it used, so an old blob still opens', async () => {
    // A backup made today must still restore in three years, by which time the iteration
    // count will have moved. Reading it back with today's parameters would silently fail.
    const blob = await wrapBackup(chains, 'passphrase')

    expect(blob.v).toBe(BACKUP_VERSION)
    expect(blob.kdf).toBe('PBKDF2-SHA256')
    expect(blob.iterations).toBe(PBKDF2_ITERATIONS)
  })

  it('honours the iteration count recorded in the blob, not the current one', async () => {
    const blob = await wrapBackup(chains, 'passphrase')
    const weaker = { ...blob, iterations: blob.iterations - 1 }

    // Deriving with different parameters gives a different key, so this must fail rather
    // than quietly succeed — which is what proves the stored value is the one being used.
    await expect(unwrapBackup(weaker, 'passphrase')).rejects.toThrow()
  })

  it('refuses a backup from a format it does not know', async () => {
    const blob = await wrapBackup(chains, 'passphrase')

    await expect(unwrapBackup({ ...blob, v: 99 }, 'passphrase')).rejects.toThrow(/newer version/)
  })

  it('survives an empty backup', async () => {
    // Somebody who turns escrow on before joining any encrypted channel. Nothing to store
    // yet, and it must not be an error.
    const blob = await wrapBackup([], 'passphrase')

    expect(await unwrapBackup(blob, 'passphrase')).toEqual([])
  })
})

describe('recovery codes', () => {
  it('generates a code with no easily-confused characters', async () => {
    // This gets written on paper and typed back months later. I, L, O and U are exactly the
    // characters that get misread as 1, 0 and each other.
    const code = generateRecoveryCode()

    expect(code).toMatch(/^[0-9A-HJKMNP-TV-Z]{5}(-[0-9A-HJKMNP-TV-Z]{5})+$/)
    expect(code).not.toMatch(/[ILOU]/)
  })

  it('never generates the same code twice', async () => {
    const codes = new Set(Array.from({ length: 50 }, () => generateRecoveryCode()))

    expect(codes.size).toBe(50)
  })

  it('accepts a code retyped in the wrong case or without hyphens', async () => {
    // All the same code. Rejecting these would be a support burden with no security benefit
    // whatsoever — case sensitivity does not inconvenience an attacker.
    const code = generateRecoveryCode()

    const variants = [code.toLowerCase(), code.replace(/-/g, ''), ` ${code} `]

    for (const variant of variants) {
      expect(normaliseRecoveryCode(variant)).toBe(normaliseRecoveryCode(code))
    }
  })

  it('opens a file wrapped under a code that was retyped untidily', async () => {
    const code = generateRecoveryCode()
    const blob = await wrapBackup(chains, normaliseRecoveryCode(code))

    expect(await unwrapBackup(blob, normaliseRecoveryCode(code.toLowerCase()))).toEqual(chains)
  })
})
