import 'fake-indexeddb/auto'

import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { chainKeyFor, openKeyStore } from './keyStore'
import { equalBytes, randomBytes, toBase64 } from './primitives'

/*
 * The real IndexedDB store, against a real IndexedDB.
 *
 * Everything else in this directory is pure functions over bytes, which is why it was easy to
 * test and why it was all passing while the app did not work. The store is the opposite: its
 * bugs are about *timing* — when a transaction is open, what is awaited while it is — and none
 * of them are visible from the outside until the write silently throws.
 *
 * The case that matters is the one that shipped broken. An IndexedDB transaction lives only
 * for the task that created it; awaiting another IDB request keeps it alive, but awaiting
 * anything else lets it close. With no OS keychain the store awaits only resolved promises and
 * gets away with it, so the web and the phone worked perfectly while the desktop app — the one
 * runtime with a vault — could neither send nor read a single message.
 */

/**
 * An Electron-shaped secret bridge, so `openVault` takes the native path.
 *
 * The real WebCrypto behind it is what makes this a faithful reproduction: sealing a chain key
 * genuinely returns to the event loop, which is precisely what killed the transaction.
 */
function installFakeKeychain() {
  const secrets = new Map<string, string>()

  ;(globalThis as any).window = {
    sideChatDesktop: {
      secrets: {
        available: async () => true,
        get: async (name: string) => secrets.get(name) ?? null,
        set: async (name: string, value: string) => {
          secrets.set(name, value)

          return true
        },
      },
    },
  }
}

function removeFakeKeychain() {
  delete (globalThis as any).window
}

/** A chain row, of the shape the app writes. */
function chainRow(channelId = 1, epoch = 1, deviceId = 'ana-laptop') {
  return {
    id: chainKeyFor(channelId, epoch, deviceId),
    channelId,
    epoch,
    deviceId,
    chainKey: randomBytes(32),
    index: 0,
    rooted: true,
  }
}

describe('key store, with an OS vault active', () => {
  beforeEach(() => installFakeKeychain())
  afterEach(() => removeFakeKeychain())

  it('saves a chain even though sealing it takes a trip through the event loop', async () => {
    /*
     * The regression, exactly as reported:
     *
     *   TransactionInactiveError: Failed to execute 'put' on 'IDBObjectStore':
     *   The transaction has finished.
     *
     * Thrown from saveChain, which took down chainFor, encryptOutgoing and the whole send.
     */
    const store = openKeyStore(1)
    const chain = chainRow()

    await expect(store.saveChain(chain)).resolves.toBeUndefined()

    const loaded = await store.loadChain(1, 1, 'ana-laptop')
    expect(loaded).not.toBeNull()
    expect(equalBytes(loaded!.chainKey, chain.chainKey)).toBe(true)
  })

  it('actually encrypts what it writes, rather than only appearing to', async () => {
    // The other half: a fix that made the write succeed by skipping the sealing would pass
    // the test above and quietly remove the protection it was added for.
    const store = openKeyStore(2)
    const chain = chainRow(7, 1, 'ben-phone')

    await store.saveChain(chain)

    const raw: any = await new Promise((resolve, reject) => {
      const request = indexedDB.open('side-chat-keys-2')
      request.onsuccess = () => {
        const get = request.result.transaction('chains', 'readonly').objectStore('chains').get(chain.id)
        get.onsuccess = () => resolve(get.result)
        get.onerror = () => reject(get.error)
      }
      request.onerror = () => reject(request.error)
    })

    expect(raw.sealed).toBe(true)
    expect(toBase64(new Uint8Array(raw.chainKey))).not.toBe(toBase64(chain.chainKey))
  })

  it('survives a burst of writes on one store', async () => {
    // Collecting an inbox writes a chain per sender, back to back. Each one opens its own
    // transaction, and the first version failed on every single one of them.
    const store = openKeyStore(3)

    await Promise.all([
      store.saveChain(chainRow(9, 1, 'a')),
      store.saveChain(chainRow(9, 1, 'b')),
      store.saveChain(chainRow(9, 2, 'a')),
    ])

    expect(await store.chainsForChannel(9)).toHaveLength(3)
  })
})

describe('key store, with no vault', () => {
  it('stores chain keys as they are', async () => {
    // A browser tab: nothing to wrap with, and the rows say so rather than claiming a
    // protection that isn't there.
    const store = openKeyStore(4)
    const chain = chainRow(11, 1, 'web')

    await store.saveChain(chain)
    const loaded = await store.loadChain(11, 1, 'web')

    expect(equalBytes(loaded!.chainKey, chain.chainKey)).toBe(true)
  })

  it('round-trips prekeys and clears everything on sign-out', async () => {
    const store = openKeyStore(5)

    await store.saveChain(chainRow(12, 1, 'web'))
    await store.clear()

    expect(await store.loadChain(12, 1, 'web')).toBeNull()
    expect(await store.allChains()).toHaveLength(0)
  })
})
