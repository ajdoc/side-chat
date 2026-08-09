import { describe, expect, it } from 'vitest'

import { chainKeyFor, MemoryKeyStore } from './keyStore'
import { equalBytes, generateAesKey, randomBytes, toBase64 } from './primitives'
import { noVault, vaultFromKey } from './vault'

/*
 * The vault: wrapping the one piece of key material that has to exist as bytes.
 *
 * The native side of this can't be tested here — there is no keychain in a node process, and
 * mocking one would only prove the mock works. What *is* testable, and is where the bugs
 * would be, is the wrapping itself and the fallback behaviour: a wrapped key must be
 * unreadable without the vault key, an unwrappable row must not take the store down with it,
 * and a runtime without a keychain must keep working exactly as it did before.
 */

describe('vault', () => {
  it('makes a chain key unreadable without the vault key', async () => {
    const vault = vaultFromKey(await generateAesKey())
    const chainKey = randomBytes(32)

    const wrapped = await vault.wrap(chainKey)

    // Not merely different — the plaintext must not appear anywhere in the blob. A "wrap"
    // that prefixed or appended would pass a naive inequality check and protect nothing.
    expect(equalBytes(wrapped, chainKey)).toBe(false)
    expect(toBase64(wrapped)).not.toContain(toBase64(chainKey).slice(0, 24))
  })

  it('round-trips a chain key', async () => {
    const vault = vaultFromKey(await generateAesKey())
    const chainKey = randomBytes(32)

    expect(equalBytes(await vault.unwrap(await vault.wrap(chainKey)), chainKey)).toBe(true)
  })

  it('refuses to open a blob wrapped under a different vault key', async () => {
    // The point of the whole exercise: a profile directory copied to another machine is
    // useless, because the key that opens it stayed in that machine's keychain.
    const mine = vaultFromKey(await generateAesKey())
    const theirs = vaultFromKey(await generateAesKey())

    const wrapped = await mine.wrap(randomBytes(32))

    await expect(theirs.unwrap(wrapped)).rejects.toThrow()
  })

  it('refuses a blob that has been tampered with', async () => {
    const vault = vaultFromKey(await generateAesKey())
    const wrapped = await vault.wrap(randomBytes(32))

    wrapped[wrapped.length - 1] = wrapped[wrapped.length - 1]! ^ 0xff

    await expect(vault.unwrap(wrapped)).rejects.toThrow()
  })

  it('never produces the same blob twice for the same key', async () => {
    // A fresh IV per wrap. Without it, two channels whose chains happened to share a key
    // would be visibly identical rows.
    const vault = vaultFromKey(await generateAesKey())
    const chainKey = randomBytes(32)

    expect(equalBytes(await vault.wrap(chainKey), await vault.wrap(chainKey))).toBe(false)
  })

  it('passes bytes straight through where there is no keychain', async () => {
    // A browser tab. Inventing a key and hiding it elsewhere in IndexedDB would be theatre —
    // anything the page can find, so can anyone reading the profile — so the honest answer is
    // to do nothing and say so.
    const chainKey = randomBytes(32)

    expect(noVault.protecting).toBe(false)
    expect(equalBytes(await noVault.wrap(chainKey), chainKey)).toBe(true)
    expect(equalBytes(await noVault.unwrap(chainKey), chainKey)).toBe(true)
  })
})

describe('key store with a vault', () => {
  /**
   * The memory store doesn't wrap — it is for tests and for runtimes with no persistence at
   * all, where there is no disk to protect. What matters is that the *interface* is
   * unchanged, so nothing above the store has to know which kind it got.
   */
  it('hands back usable chain keys either way', async () => {
    const store = new MemoryKeyStore()
    const chainKey = randomBytes(32)

    await store.saveChain({
      id: chainKeyFor(1, 1, 'ana-laptop'),
      channelId: 1,
      epoch: 1,
      deviceId: 'ana-laptop',
      chainKey,
      index: 0,
    })

    const loaded = await store.loadChain(1, 1, 'ana-laptop')

    expect(equalBytes(loaded!.chainKey, chainKey)).toBe(true)
  })

  it('reports every chain for backup, whatever the store', async () => {
    // allChains feeds the passphrase backup, which must see usable bytes rather than blobs
    // wrapped under a vault key that only exists on this one machine — otherwise the backup
    // would restore nothing anywhere else.
    const store = new MemoryKeyStore()

    for (const epoch of [1, 2]) {
      await store.saveChain({
        id: chainKeyFor(3, epoch, 'a'),
        channelId: 3,
        epoch,
        deviceId: 'a',
        chainKey: randomBytes(32),
        index: 0,
      })
    }

    const all = await store.allChains()

    expect(all).toHaveLength(2)
    expect(all.every(chain => chain.chainKey.length === 32)).toBe(true)
  })
})
