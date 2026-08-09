/**
 * Where a device's keys live between sessions.
 *
 * IndexedDB, in all three shells. Not a placeholder for something better: IndexedDB can
 * store a live `CryptoKey` object, and a non-extractable key put in and taken back out is
 * *still* non-extractable. The private half of a device identity is therefore never bytes —
 * not in memory, not on disk, not reachable by any script that later runs on the page. It
 * can be used and it can be deleted, and that is all. No amount of OS keychain wrapping
 * around an exportable key would be as strong as a key that cannot be exported at all.
 *
 * What the native shells would still add is protection at rest against someone with the
 * *disk* rather than the page: Electron's `safeStorage` and the iOS/Android keychains
 * encrypt under a key the OS holds. That is worth having and is deliberately not here yet —
 * neither bridge exists (the Electron preload exposes no such channel and there is no
 * Capacitor plugin for it), and inventing both is its own piece of work. {@link KeyStore} is
 * the seam it lands on: implement the interface, register it in {@link openKeyStore}, and
 * nothing above this file changes.
 *
 * Everything is scoped by account id. Two people sharing a laptop and one browser profile
 * get separate stores, and signing out of one cannot hand its keys to the other.
 */

import { openVault, type Vault } from './vault'

/** Bumped only for a schema change; the browser runs `onupgradeneeded` when it moves. */
const DB_VERSION = 1

const DB_NAME = 'side-chat-keys'

/** Object stores. Split by lifetime, which is also how they are cleared. */
const IDENTITY_STORE = 'identity'
const CHAIN_STORE = 'chains'
const PREKEY_STORE = 'prekeys'

/**
 * A device's own identity: the keypairs that make it this device and no other.
 *
 * The private keys are non-extractable `CryptoKey`s and stay that way — this record is
 * written and read whole, and nothing ever exports the private halves. `deviceId` is the
 * server-issued name for this device, and the value that travels in every envelope.
 */
export interface StoredIdentity {
  deviceId: string
  identity: CryptoKeyPair
  signing: CryptoKeyPair
  createdAt: number
  /**
   * Signed prekeys, newest first — the current one and the one it replaced.
   *
   * More than one, and this is not an optimisation. A signed prekey is rotated periodically,
   * but somebody may have fetched the old bundle minutes before the rotation and wrapped a
   * sender key against it; that key arrives here afterwards and must still open. Keeping only
   * the current key would make every distribution made across a rotation boundary silently
   * undecryptable — messages that arrive, look normal, and can never be read.
   *
   * Two is enough because the rotation interval is much longer than the time between fetching
   * a bundle and posting the wrapped key. Retiring the third means an attacker who takes the
   * device still can't open sessions from two rotations ago, which is the point of rotating.
   */
  signedPrekeys: StoredSignedPrekey[]
}

/** One signed prekey, kept until it has been superseded twice. */
export interface StoredSignedPrekey {
  /** Base64 of the public half — how a wrapped key says which one it used. */
  publicKey: string
  keyPair: CryptoKeyPair
  createdAt: number
}

/**
 * A sender chain, as persisted.
 *
 * Chain keys *are* bytes — the ratchet has to derive from them, so they cannot be
 * non-extractable keys. They are the one genuinely sensitive thing on disk here, and the
 * reason the native-keychain work above is worth doing eventually.
 */
export interface StoredChain {
  /** `${channelId}:${epoch}:${deviceId}` — see {@link chainKeyFor}. */
  id: string
  channelId: number
  epoch: number
  deviceId: string
  chainKey: Uint8Array
  index: number
  /**
   * Whether `chainKey` is wrapped with the OS-held vault key *as stored on disk*.
   *
   * Per row, because a profile can hold both — keys written in a browser tab and later opened
   * in the desktop app, or written before a keychain became available. Always false on a
   * chain handed out by the store: callers get the usable bytes, and the flag describes the
   * row it came from. See `vault.ts`.
   */
  sealed?: boolean
  /**
   * This row's `chainKey` is the chain's **root**, as the format now requires.
   *
   * A repair marker, and the only way to tell a good row from a damaged one. An early build
   * persisted the ratcheted-forward key for chains this device sends on, which made its own
   * messages unreadable and — worse — meant anything sent afterwards was derived from a key
   * the recipients did not have. There is no way to inspect 32 bytes and tell whether they
   * are a root or a descendant of one, so the honest fix is to mark the rows written since
   * and treat an unmarked *sending* chain as unusable: {@see useEncryption.chainFor} discards
   * it, mints a fresh chain and redistributes.
   *
   * Chains received from other people were always stored as roots and are left alone —
   * which matters, because they cannot be recovered a second time: unwrapping one consumes a
   * one-time prekey, and that prekey is gone.
   */
  rooted?: boolean
  /**
   * The `device_key_id`s this chain has already been wrapped for — sending chains only.
   *
   * Distribution used to happen exactly once, when a chain was created, which quietly made
   * the system deaf to anybody who arrived afterwards: a new device — a fresh browser
   * profile, a phone, an incognito window — could never read a word said before it existed,
   * because no sender ever handed it a key. Recording who has been reached turns that into a
   * diff the sender can top up.
   *
   * Device *key* ids rather than device ids: they are what a distribution is addressed by, and
   * they are stable per device per account.
   */
  distributedTo?: number[]
}

/** A one-time prekey we published and must be able to answer for until it's claimed. */
export interface StoredPrekey {
  id: string
  keyPair: CryptoKeyPair
  createdAt: number
}

/**
 * Everything the app above needs from storage.
 *
 * Deliberately narrow, and deliberately not a key-value bag: each method is a thing the
 * protocol actually does, so a native implementation has a short and obvious contract to
 * meet rather than a general-purpose store to reimplement.
 */
export interface KeyStore {
  loadIdentity(): Promise<StoredIdentity | null>
  saveIdentity(identity: StoredIdentity): Promise<void>

  loadChain(channelId: number, epoch: number, deviceId: string): Promise<StoredChain | null>
  saveChain(chain: StoredChain): Promise<void>
  /** Every chain for a channel — what a client needs to read a striped timeline. */
  chainsForChannel(channelId: number): Promise<StoredChain[]>
  /**
   * Every chain this device holds, for backup.
   *
   * The only method that reads across channels, and the only one whose result is meant to
   * leave the device — wrapped under a passphrase. See `backup.ts` for why chains are the
   * thing worth backing up and identity keys are not.
   */
  allChains(): Promise<StoredChain[]>

  savePrekey(prekey: StoredPrekey): Promise<void>
  takePrekey(id: string): Promise<StoredPrekey | null>
  countPrekeys(): Promise<number>

  /** Signing out. Everything for this account goes, and the messages become unreadable. */
  clear(): Promise<void>
}

/** The composite id a chain is stored under — one chain per sender, per era, per channel. */
export function chainKeyFor(channelId: number, epoch: number, deviceId: string): string {
  return `${channelId}:${epoch}:${deviceId}`
}

/**
 * IndexedDB, promisified.
 *
 * The `IDBRequest` event dance is wrapped once here rather than at each call site, because
 * the failure mode of getting it subtly wrong — a transaction that commits before a write
 * lands — is a key that silently isn't there next time the app starts.
 */
function promisify<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

class IndexedDbKeyStore implements KeyStore {
  private db: Promise<IDBDatabase>

  /**
   * The OS-held wrapping key for chain keys, resolved once per store.
   *
   * A promise rather than a value because opening it is a native round trip, and every
   * chain read and write has to wait on the same one rather than racing to start its own.
   * On the web it resolves to the passthrough vault immediately — see `vault.ts`.
   */
  private vault: Promise<Vault>

  constructor(private readonly accountId: number) {
    this.db = this.connect()
    this.vault = openVault()
  }

  private connect(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(`${DB_NAME}-${this.accountId}`, DB_VERSION)

      request.onupgradeneeded = () => {
        const db = request.result
        if (!db.objectStoreNames.contains(IDENTITY_STORE)) db.createObjectStore(IDENTITY_STORE)
        if (!db.objectStoreNames.contains(CHAIN_STORE)) {
          const chains = db.createObjectStore(CHAIN_STORE, { keyPath: 'id' })
          // The timeline is read per channel, so that's the index that earns its keep.
          chains.createIndex('channelId', 'channelId')
        }
        if (!db.objectStoreNames.contains(PREKEY_STORE)) {
          db.createObjectStore(PREKEY_STORE, { keyPath: 'id' })
        }
      }

      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
  }

  private async store(name: string, mode: IDBTransactionMode): Promise<IDBObjectStore> {
    const db = await this.db

    return db.transaction(name, mode).objectStore(name)
  }

  async loadIdentity(): Promise<StoredIdentity | null> {
    const store = await this.store(IDENTITY_STORE, 'readonly')

    return (await promisify(store.get('self'))) ?? null
  }

  async saveIdentity(identity: StoredIdentity): Promise<void> {
    const store = await this.store(IDENTITY_STORE, 'readwrite')
    await promisify(store.put(identity, 'self'))
  }

  async loadChain(
    channelId: number,
    epoch: number,
    deviceId: string,
  ): Promise<StoredChain | null> {
    const store = await this.store(CHAIN_STORE, 'readonly')
    const row = (await promisify(store.get(chainKeyFor(channelId, epoch, deviceId)))) ?? null

    return row ? this.reveal(row) : null
  }

  async saveChain(chain: StoredChain): Promise<void> {
    const store = await this.store(CHAIN_STORE, 'readwrite')
    await promisify(store.put(await this.conceal(chain)))
  }

  async chainsForChannel(channelId: number): Promise<StoredChain[]> {
    const store = await this.store(CHAIN_STORE, 'readonly')
    const rows: StoredChain[] = await promisify(store.index('channelId').getAll(channelId))

    return Promise.all(rows.map(row => this.reveal(row)))
  }

  async allChains(): Promise<StoredChain[]> {
    const store = await this.store(CHAIN_STORE, 'readonly')
    const rows: StoredChain[] = await promisify(store.getAll())

    return Promise.all(rows.map(row => this.reveal(row)))
  }

  /**
   * Wrap a chain key for storage, if this runtime can.
   *
   * `sealed` records what was actually done, per row rather than globally. A profile can
   * genuinely contain both kinds — keys written on the web and later opened in the desktop
   * app, or written before the user's keychain became available — and a store that assumed
   * one answer for all of them would fail to read half its own rows.
   */
  private async conceal(chain: StoredChain): Promise<StoredChain> {
    const vault = await this.vault
    if (!vault.protecting) return { ...chain, sealed: false }

    return { ...chain, chainKey: await vault.wrap(chain.chainKey), sealed: true }
  }

  /**
   * The inverse, tolerating a row this runtime cannot unwrap.
   *
   * A sealed row met on a machine whose keychain has gone (a restored profile, a reset
   * keyring) is unreadable and stays that way — but it must not take the rest of the store
   * with it, or one bad row would make every conversation on the device fail to open. The
   * chain comes back empty, decryption of those messages fails, and the timeline draws
   * "can't read this" on them exactly as it does for a missing key.
   */
  private async reveal(chain: StoredChain): Promise<StoredChain> {
    if (!chain.sealed) return chain

    const vault = await this.vault

    try {
      return { ...chain, chainKey: await vault.unwrap(chain.chainKey), sealed: false }
    } catch {
      return { ...chain, chainKey: new Uint8Array(0), sealed: false }
    }
  }

  async savePrekey(prekey: StoredPrekey): Promise<void> {
    const store = await this.store(PREKEY_STORE, 'readwrite')
    await promisify(store.put(prekey))
  }

  /**
   * Fetch a one-time prekey and delete it in the same breath.
   *
   * One transaction, not two: a prekey used twice is a prekey that no longer provides the
   * forward secrecy it exists for, and a read-then-delete could be interleaved by two tabs
   * of the same account answering the same session at once.
   */
  async takePrekey(id: string): Promise<StoredPrekey | null> {
    const db = await this.db
    const transaction = db.transaction(PREKEY_STORE, 'readwrite')
    const store = transaction.objectStore(PREKEY_STORE)

    const prekey = (await promisify(store.get(id))) ?? null
    if (prekey) await promisify(store.delete(id))

    return prekey
  }

  async countPrekeys(): Promise<number> {
    const store = await this.store(PREKEY_STORE, 'readonly')

    return promisify(store.count())
  }

  async clear(): Promise<void> {
    const db = await this.db
    const transaction = db.transaction([IDENTITY_STORE, CHAIN_STORE, PREKEY_STORE], 'readwrite')

    await Promise.all([
      promisify(transaction.objectStore(IDENTITY_STORE).clear()),
      promisify(transaction.objectStore(CHAIN_STORE).clear()),
      promisify(transaction.objectStore(PREKEY_STORE).clear()),
    ])
  }
}

/**
 * The same contract, held only in memory.
 *
 * Two uses, and both are real. Tests get a store with no IndexedDB and no cleanup. And a
 * runtime with no persistent storage at all — a private window, a locked-down WebView —
 * gets a session that works and forgets everything when the tab closes, which is a better
 * answer than refusing to load. It is *not* a fallback for "IndexedDB threw once": losing
 * keys silently would lose messages, so {@link openKeyStore} only picks this when there is
 * genuinely nowhere to persist.
 */
export class MemoryKeyStore implements KeyStore {
  private identity: StoredIdentity | null = null
  private chains = new Map<string, StoredChain>()
  private prekeys = new Map<string, StoredPrekey>()

  async loadIdentity(): Promise<StoredIdentity | null> {
    return this.identity
  }

  async saveIdentity(identity: StoredIdentity): Promise<void> {
    this.identity = identity
  }

  async loadChain(
    channelId: number,
    epoch: number,
    deviceId: string,
  ): Promise<StoredChain | null> {
    return this.chains.get(chainKeyFor(channelId, epoch, deviceId)) ?? null
  }

  async saveChain(chain: StoredChain): Promise<void> {
    this.chains.set(chain.id, chain)
  }

  async chainsForChannel(channelId: number): Promise<StoredChain[]> {
    return [...this.chains.values()].filter(chain => chain.channelId === channelId)
  }

  async allChains(): Promise<StoredChain[]> {
    return [...this.chains.values()]
  }

  async savePrekey(prekey: StoredPrekey): Promise<void> {
    this.prekeys.set(prekey.id, prekey)
  }

  async takePrekey(id: string): Promise<StoredPrekey | null> {
    const prekey = this.prekeys.get(id) ?? null
    this.prekeys.delete(id)

    return prekey
  }

  async countPrekeys(): Promise<number> {
    return this.prekeys.size
  }

  async clear(): Promise<void> {
    this.identity = null
    this.chains.clear()
    this.prekeys.clear()
  }
}

/**
 * The store for an account, on whatever this is running on.
 *
 * The place a native backend gets registered: Electron and Capacitor both reach here, and
 * both currently get IndexedDB, which works correctly in each. See the note at the top of
 * the file for what a keychain-backed implementation would add on top.
 */
export function openKeyStore(accountId: number): KeyStore {
  if (typeof indexedDB === 'undefined') return new MemoryKeyStore()

  return new IndexedDbKeyStore(accountId)
}
