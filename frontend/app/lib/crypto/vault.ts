/**
 * Encrypting the one thing that has to sit on disk as bytes.
 *
 * The gap this closes: device identity keys are non-extractable `CryptoKey`s, so they are
 * never bytes anywhere and nothing — not this code, not a script injected into the page,
 * not somebody with the disk — can read them out. Chain keys cannot have that property,
 * because the ratchet has to *derive* from them, which means the raw bytes exist. Stored
 * plainly in IndexedDB they are readable by anyone who can read the profile directory: a
 * stolen laptop, a backup, a second account on a shared machine.
 *
 * So the chain keys are wrapped with a key the operating system holds:
 *
 *  - **Electron** — `safeStorage`, which is the OS keychain (Keychain on macOS, libsecret or
 *    kwallet on Linux, DPAPI on Windows).
 *  - **Capacitor** — the platform keystore: Android's hardware-backed Keystore, iOS's
 *    Keychain with `WhenUnlockedThisDeviceOnly`.
 *  - **A browser tab** — nothing. There is no OS-backed secret storage available to a web
 *    page, and pretending otherwise by inventing a key and hiding it somewhere else in
 *    IndexedDB would be theatre: anything the page can find, so can anyone reading the
 *    profile. On the web the chain keys stay as they were, and {@link vaultAvailable} says so
 *    honestly rather than the UI claiming a protection that isn't there.
 *
 * One key wraps everything, fetched once per launch. The alternative — a keychain round trip
 * per chain — would put a native IPC call in the path of every message decrypt, and on mobile
 * that is the difference between a timeline that scrolls and one that stutters.
 *
 * What this does *not* defend against: malware running as you, while you are logged in. The
 * OS will hand the vault key to this app because it is this app. It defends against the disk
 * — which is the realistic threat for a laptop that gets stolen or a phone that gets sold.
 */

import { generateAesKey, exportAesKey, importAesKey, open, randomBytes, seal, toBase64, fromBase64, type Sealed } from './primitives'

/** The name the vault key is filed under in the OS store. */
const VAULT_KEY_NAME = 'side-chat.vault-key'

/** Bound into every wrap, so a blob cannot be lifted into some other use of the same key. */
const VAULT_CONTEXT = new TextEncoder().encode('side-chat/vault/chain-key')

/**
 * The native secret store, as the shells expose it.
 *
 * Both bridges are the same three calls, so the app sees one interface and neither shell's
 * shape leaks past this file.
 */
export interface NativeSecrets {
  get(name: string): Promise<string | null>
  set(name: string, value: string): Promise<void>
  available(): Promise<boolean>
}

/**
 * Whichever bridge this runtime has, or null in a browser.
 *
 * Read off `window` rather than imported, exactly like the screen-share and voice plugins —
 * the frontend has no Capacitor or Electron dependency and this must stay true, because the
 * same bundle is what the web serves.
 */
function nativeSecrets(): NativeSecrets | null {
  if (typeof window === 'undefined') return null

  const electron = (window as any).sideChatDesktop?.secrets
  if (electron) return electron

  const capacitor = (window as any).Capacitor?.Plugins?.SecureStore
  if (capacitor) {
    return {
      get: async (name: string) => (await capacitor.get({ name }))?.value ?? null,
      set: async (name: string, value: string) => { await capacitor.set({ name, value }) },
      available: async () => (await capacitor.available())?.available === true,
    }
  }

  return null
}

/**
 * Wraps and unwraps chain keys, or passes them through untouched.
 *
 * A single object with both behaviours rather than a null check at every call site: the store
 * should not have to care, and a `if (vault)` scattered through it is how one branch ends up
 * saving a key the other can't read.
 */
export interface Vault {
  /** True when an OS-held key is actually protecting these bytes. */
  readonly protecting: boolean
  wrap(bytes: Uint8Array): Promise<Uint8Array>
  unwrap(bytes: Uint8Array): Promise<Uint8Array>
}

/** The no-op vault — a browser tab, where there is nothing to hide a key in. */
const passthrough: Vault = {
  protecting: false,
  async wrap(bytes) {
    return bytes
  },
  async unwrap(bytes) {
    return bytes
  },
}

/**
 * Open the vault for this runtime.
 *
 * Falls back to passthrough on *any* failure — a keychain the user declined, a Linux box with
 * no secret service running, a locked device. Losing the wrapping would be much worse than
 * not having it: an unreadable chain is unreadable history, whereas an unwrapped one is
 * merely no better protected than it was yesterday. Degrading quietly is right here, and it
 * is why {@link Vault.protecting} exists for the UI to be honest about which happened.
 */
export async function openVault(): Promise<Vault> {
  const native = nativeSecrets()
  if (!native) return passthrough

  try {
    if (!(await native.available())) return passthrough

    const key = await loadOrCreateVaultKey(native)

    /*
     * Prove the round trip before trusting it with anything.
     *
     * `available()` says the OS is *willing* to encrypt, which is not the same as this key
     * being usable — the store may have accepted a write and returned something else, or the
     * key may have come back mangled. Finding that out later is catastrophic in a way most
     * failures here are not: chains would be written wrapped under a key that cannot open
     * them, and every message on the device becomes unreadable *and* unsendable at once.
     *
     * A wrap-and-unwrap of eight random bytes costs microseconds and turns that whole class
     * of failure into "no protection this session", which is survivable and honest.
     */
    if (!(await roundTripWorks(key))) return passthrough

    return {
      protecting: true,
      async wrap(bytes) {
        const sealed = await seal(key, bytes, VAULT_CONTEXT)

        // IV in front, so a wrapped chain key is one self-contained buffer and the column it
        // lives in doesn't need a second field it might get separated from.
        const blob = new Uint8Array(sealed.iv.length + sealed.ciphertext.length)
        blob.set(sealed.iv, 0)
        blob.set(sealed.ciphertext, sealed.iv.length)

        return blob
      },
      async unwrap(bytes) {
        const sealed: Sealed = { iv: bytes.slice(0, 12), ciphertext: bytes.slice(12) }

        return open(key, sealed, VAULT_CONTEXT)
      },
    }
  } catch {
    return passthrough
  }
}

/** Does this key actually seal and open again? See the note at the call site. */
async function roundTripWorks(key: CryptoKey): Promise<boolean> {
  try {
    const probe = randomBytes(8)
    const sealed = await seal(key, probe, VAULT_CONTEXT)
    const opened = await open(key, sealed, VAULT_CONTEXT)

    return opened.length === probe.length && opened.every((byte, i) => byte === probe[i])
  } catch {
    return false
  }
}

/**
 * The vault key: read it back, or mint one and hand it to the OS.
 *
 * Imported non-extractable once it is in the page, so that having *used* the keychain doesn't
 * leave the key sitting in a readable JavaScript variable for the rest of the session.
 */
async function loadOrCreateVaultKey(native: NativeSecrets): Promise<CryptoKey> {
  const existing = await native.get(VAULT_KEY_NAME)

  if (existing) return importAesKey(fromBase64(existing))

  const fresh = await generateAesKey(true)
  const raw = await exportAesKey(fresh)
  await native.set(VAULT_KEY_NAME, toBase64(raw))

  return importAesKey(raw)
}

/** Whether this runtime can protect keys at rest at all — for the settings screen to say so. */
export async function vaultAvailable(): Promise<boolean> {
  const native = nativeSecrets()

  try {
    return native ? await native.available() : false
  } catch {
    return false
  }
}

/** Exported for the tests, which need a vault without a keychain to talk to. */
export function vaultFromKey(key: CryptoKey): Vault {
  return {
    protecting: true,
    async wrap(bytes) {
      const sealed = await seal(key, bytes, VAULT_CONTEXT)
      const blob = new Uint8Array(sealed.iv.length + sealed.ciphertext.length)
      blob.set(sealed.iv, 0)
      blob.set(sealed.ciphertext, sealed.iv.length)

      return blob
    },
    async unwrap(bytes) {
      return open(key, { iv: bytes.slice(0, 12), ciphertext: bytes.slice(12) }, VAULT_CONTEXT)
    },
  }
}

/** The unprotected vault, for a browser tab and for tests that want one. */
export const noVault = passthrough
