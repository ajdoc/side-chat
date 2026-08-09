import {
  generateRecoveryCode,
  normaliseRecoveryCode,
  unwrapBackup,
  wrapBackup,
  type BackupBlob,
  type BackupChain,
} from '~/lib/crypto/backup'
import { fromBase64, toBase64 } from '~/lib/crypto/primitives'
import { chainKeyFor, openKeyStore, type KeyStore } from '~/lib/crypto/keyStore'

/**
 * Keeping — or deliberately not keeping — a way back into your own history.
 *
 * Two modes, one piece of machinery. Both wrap the same chains with the same code; they
 * differ only in where the wrapped blob goes and what the secret is:
 *
 *  - **Escrow** (the default). Wrapped under a passphrase, stored on the server. A new device
 *    asks for the blob, the person types the passphrase, the history comes back. The server
 *    never sees the passphrase, but it does hold the blob — so an attacker who takes the
 *    database gets unlimited offline guesses, which is what the 600,000 PBKDF2 iterations are
 *    there to make expensive.
 *  - **A recovery file.** The same blob under a generated code, downloaded and kept by the
 *    person. Nothing is stored, so there is nothing to steal — and nothing to fall back on if
 *    they lose the file. This is the honest option for somebody who means it.
 *
 * What is *not* backed up: the device identity keys. They are non-extractable and cannot be,
 * which is a feature — see `backup.ts`. A restored device is a new device with a new identity;
 * what it recovers is the ability to read what was already said.
 */

/** A restore's outcome, so the UI can say something true about a partial one. */
export interface RestoreResult {
  /** Chains written into this device's store. */
  restored: number
  /** Chains skipped because this device already had them, wound further forward. */
  skipped: number
}

export function useKeyBackup() {
  const api = useApi()
  const { user } = useAuth()

  /** When the server last accepted a backup, or null if there isn't one. */
  const lastBackupAt = useState<string | null>('encryption:backup-at', () => null)
  const busy = useState<boolean>('encryption:backup-busy', () => false)

  let store: KeyStore | null = null

  function requireStore(): KeyStore {
    if (!user.value) throw new Error('key backup needs a signed-in account')
    store ??= openKeyStore(user.value.id)

    return store
  }

  /** Everything this device could restore elsewhere. */
  async function collectChains(): Promise<BackupChain[]> {
    const chains = await requireStore().allChains()

    return chains.map(chain => ({
      channelId: chain.channelId,
      epoch: chain.epoch,
      deviceId: chain.deviceId,
      chainKey: toBase64(chain.chainKey),
      index: chain.index,
    }))
  }

  /**
   * Wrap this device's chains and push them to the server.
   *
   * Called when escrow is first switched on, and again whenever the set of chains has grown
   * — joining an encrypted channel, or a new era starting. Cheap to repeat: it replaces the
   * single stored row rather than adding to it.
   *
   * The passphrase is asked for every time rather than remembered. Holding it in memory for
   * the session would make re-syncing silent and pleasant, and would also mean a passphrase
   * sitting in a JavaScript heap for hours — which is precisely the thing the escrow design
   * is trying not to do.
   */
  async function enableEscrow(passphrase: string): Promise<void> {
    busy.value = true
    try {
      const blob = await wrapBackup(await collectChains(), passphrase)

      const res = await api<{ data: { updated_at: string } }>('/api/encryption/backup', {
        method: 'PUT',
        body: { blob: JSON.stringify(blob), kdf: blob.kdf, iterations: blob.iterations },
      })

      lastBackupAt.value = res.data.updated_at
    } finally {
      busy.value = false
    }
  }

  /**
   * Restore chains from the escrowed backup.
   *
   * Existing chains win. A chain already on this device may have been wound forward past the
   * point the backup was taken, and overwriting it with an older copy would rewind the
   * ratchet — which for a *sending* chain means handing the same message key to two different
   * messages, the one failure AES-GCM cannot survive. Skipping is always safe; overwriting
   * is not.
   */
  async function restoreFromEscrow(passphrase: string): Promise<RestoreResult> {
    busy.value = true
    try {
      const res = await api<{ data: { blob: string } }>('/api/encryption/backup')

      return await applyBackup(JSON.parse(res.data.blob) as BackupBlob, passphrase)
    } finally {
      busy.value = false
    }
  }

  /** Whether an escrowed backup exists, and when it was last written. */
  async function checkEscrow(): Promise<boolean> {
    try {
      const res = await api<{ data: { updated_at: string } }>('/api/encryption/backup')
      lastBackupAt.value = res.data.updated_at

      return true
    } catch {
      // A 404 is the ordinary answer for anybody who opted out — not a failure.
      lastBackupAt.value = null

      return false
    }
  }

  /**
   * Stop escrowing, and delete what is stored.
   *
   * The destructive one. Any device that hasn't already collected these chains will never be
   * able to, and that history is gone from every future machine. The UI must say so plainly
   * before calling this — it does not ask.
   */
  async function disableEscrow(): Promise<void> {
    await api('/api/encryption/backup', { method: 'DELETE' })
    lastBackupAt.value = null
  }

  /**
   * The opt-out path: a downloadable file, wrapped under a code the person keeps.
   *
   * Returns the code so it can be shown *once*. It is not stored anywhere — not on the
   * server, not in the key store, not in this composable — because a recovery code kept
   * beside the thing it recovers is not a recovery code. If they lose it, the file is
   * scrap, and that is the deal they chose.
   */
  async function exportRecoveryFile(): Promise<{ code: string, file: Blob }> {
    const code = generateRecoveryCode()
    const blob = await wrapBackup(await collectChains(), normaliseRecoveryCode(code))

    return {
      code,
      file: new Blob([JSON.stringify(blob, null, 2)], { type: 'application/json' }),
    }
  }

  /** Read a recovery file back, given the code that was shown when it was made. */
  async function importRecoveryFile(file: File, code: string): Promise<RestoreResult> {
    const blob = JSON.parse(await file.text()) as BackupBlob

    return applyBackup(blob, normaliseRecoveryCode(code))
  }

  /** Unwrap a blob and fold its chains into this device's store. */
  async function applyBackup(blob: BackupBlob, secret: string): Promise<RestoreResult> {
    const chains = await unwrapBackup(blob, secret)
    const keyStore = requireStore()

    let restored = 0
    let skipped = 0

    for (const chain of chains) {
      const existing = await keyStore.loadChain(chain.channelId, chain.epoch, chain.deviceId)

      // See the note on restoreFromEscrow: never rewind a chain we already hold.
      if (existing) {
        skipped++
        continue
      }

      await keyStore.saveChain({
        id: chainKeyFor(chain.channelId, chain.epoch, chain.deviceId),
        channelId: chain.channelId,
        epoch: chain.epoch,
        deviceId: chain.deviceId,
        chainKey: fromBase64(chain.chainKey),
        index: chain.index,
      })
      restored++
    }

    return { restored, skipped }
  }

  return {
    lastBackupAt,
    busy,
    enableEscrow,
    restoreFromEscrow,
    checkEscrow,
    disableEscrow,
    exportRecoveryFile,
    importRecoveryFile,
  }
}
