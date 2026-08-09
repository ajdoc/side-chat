<script setup lang="ts">
import { AlertTriangle, Check, Download, KeyRound, Loader2, Upload, X } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { vaultAvailable } from '~/lib/crypto/vault'

/**
 * What happens to your encrypted history when you use a different device.
 *
 * The question this screen exists to force, once, rather than let somebody discover the
 * answer by clearing their browser data. Both answers are legitimate and the screen says so:
 *
 *  - **Escrow.** A copy of your keys, locked with a passphrase, kept on the server. Type the
 *    passphrase on a new device and your history comes back. The trade is stated rather than
 *    buried: the server holds the locked copy, so anyone who takes the database can guess at
 *    the passphrase for as long as they like.
 *  - **A recovery file.** The same locked copy, downloaded, with a code we generate. Nothing
 *    is stored anywhere. Lose either and the history is gone for good.
 *
 * Deliberately not a "recommended" badge on one of them. The right answer depends on what the
 * person is protecting against, and a badge would make the decision look already made.
 */
const emit = defineEmits<{ close: [] }>()

const backup = useKeyBackup()

type Mode = 'choose' | 'escrow' | 'file' | 'restore'
const mode = ref<Mode>('choose')

const passphrase = ref('')
const confirmation = ref('')
const error = ref<string | null>(null)
const done = ref<string | null>(null)

/** The generated code, shown exactly once — see exportRecoveryFile. */
const recoveryCode = ref<string | null>(null)

const restoreFile = ref<File | null>(null)
const restoreCode = ref('')

const hasEscrow = computed(() => backup.lastBackupAt.value !== null)

/**
 * Whether this device's keys are protected at rest by the OS.
 *
 * True in the desktop and phone apps, false in a browser tab — there is nowhere for a web
 * page to keep a secret that someone reading the profile directory can't also read. Said
 * plainly rather than left out, because "my messages are encrypted" and "the keys on this
 * laptop are safe if it's stolen" are different claims and people conflate them.
 */
const vaultProtecting = ref(false)

onMounted(async () => {
  backup.checkEscrow()
  vaultProtecting.value = await vaultAvailable()
})

/**
 * Long enough to be worth the 600,000 iterations behind it.
 *
 * A length floor rather than a character-class rule: "must contain a symbol" pushes people
 * towards `Password1!`, which is worse than four random words and much harder to remember.
 */
const passphraseTooShort = computed(() => passphrase.value.length > 0 && passphrase.value.length < 12)
const passphraseMismatch = computed(() => confirmation.value.length > 0 && confirmation.value !== passphrase.value)
const canSubmitEscrow = computed(() =>
  passphrase.value.length >= 12 && passphrase.value === confirmation.value && !backup.busy.value,
)

async function enableEscrow() {
  if (!canSubmitEscrow.value) return
  error.value = null

  try {
    await backup.enableEscrow(passphrase.value)
    done.value = 'Your keys are backed up. You’ll need that passphrase on a new device.'
    // Out of memory the moment it has been used — see the note in useKeyBackup.
    passphrase.value = ''
    confirmation.value = ''
  } catch {
    error.value = "Couldn't save the backup. Nothing was stored."
  }
}

async function downloadRecoveryFile() {
  error.value = null

  try {
    const { code, file } = await backup.exportRecoveryFile()
    recoveryCode.value = code

    const url = URL.createObjectURL(file)
    const link = document.createElement('a')
    link.href = url
    link.download = 'side-chat-recovery.json'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    error.value = "Couldn't build the recovery file."
  }
}

async function restore() {
  error.value = null
  done.value = null

  try {
    const result = restoreFile.value
      ? await backup.importRecoveryFile(restoreFile.value, restoreCode.value)
      : await backup.restoreFromEscrow(passphrase.value)

    // Reports both numbers, because a restore that skipped everything is not a failure —
    // it means this device already had the keys, which somebody should be told rather than
    // left to wonder about.
    done.value = result.restored === 0 && result.skipped > 0
      ? 'Nothing to restore — this device already has all of those keys.'
      : `Restored ${result.restored} key${result.restored === 1 ? '' : 's'}.`
      + (result.skipped ? ` ${result.skipped} were already here.` : '')

    passphrase.value = ''
    restoreCode.value = ''
  } catch (e: any) {
    // useKeyBackup distinguishes "wrong passphrase" from "not a backup", and both are things
    // the person can act on — so the message is passed through rather than flattened.
    error.value = e?.message ?? "Couldn't restore from that backup."
  }
}

async function turnOffEscrow() {
  error.value = null
  try {
    await backup.disableEscrow()
    done.value = 'Backup deleted. A new device won’t be able to read your encrypted history.'
  } catch {
    error.value = "Couldn't delete the backup."
  }
}

function onFilePicked(event: Event) {
  restoreFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[85vh] w-full max-w-md flex-col overflow-y-auto rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="flex items-center gap-2 font-semibold">
          <KeyRound class="size-4" />
          Encryption keys
        </h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <p class="mb-3 text-xs text-muted-foreground">
        Encrypted messages can only be read by a device that has the keys. If you clear your
        browser data or move to a new device, you need one of these to get your history back.
      </p>

      <!-- Where things currently stand, so nobody has to guess whether they set this up. -->
      <p
        class="mb-3 flex items-start gap-2 rounded-lg border p-2.5 text-xs"
        :class="hasEscrow ? 'text-muted-foreground' : 'border-amber-500/40 bg-amber-500/5 text-amber-700 dark:text-amber-400'"
      >
        <component :is="hasEscrow ? Check : AlertTriangle" class="mt-0.5 size-3.5 shrink-0" />
        <span v-if="hasEscrow">
          Backed up to your account, last updated
          {{ new Date(backup.lastBackupAt.value!).toLocaleDateString() }}.
        </span>
        <span v-else>
          No backup. If you lose this device, its encrypted history goes with it.
        </span>
      </p>

      <!--
        Where the keys on *this* device stand, as distinct from whether a backup exists. The
        two get conflated constantly, and only one of them survives the laptop being stolen.
      -->
      <p class="mb-3 text-xs text-muted-foreground">
        <template v-if="vaultProtecting">
          On this device, your keys are locked with your system keychain — copying this app's
          data to another machine won't give anyone your messages.
        </template>
        <template v-else>
          In a browser, your keys are stored in this profile without extra protection. The
          desktop and phone apps lock them with your system keychain.
        </template>
      </p>

      <template v-if="mode === 'choose'">
        <div class="space-y-2">
          <button class="w-full rounded-lg border p-3 text-left hover:bg-muted" @click="mode = 'escrow'">
            <span class="text-sm font-medium">Back up with a passphrase</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">
              We keep a locked copy of your keys. Type your passphrase on a new device to get
              your history back. We never see the passphrase — but we do hold the locked copy,
              so a strong one matters.
            </span>
          </button>
          <button class="w-full rounded-lg border p-3 text-left hover:bg-muted" @click="mode = 'file'">
            <span class="text-sm font-medium">Download a recovery file instead</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">
              Nothing is stored on our servers at all. You keep a file and a code. Lose either
              and your encrypted history is gone permanently.
            </span>
          </button>
          <button class="w-full rounded-lg border p-3 text-left hover:bg-muted" @click="mode = 'restore'">
            <span class="text-sm font-medium">Restore on this device</span>
            <span class="mt-0.5 block text-xs text-muted-foreground">
              Bring your history back from a passphrase backup or a recovery file.
            </span>
          </button>
        </div>
      </template>

      <template v-else-if="mode === 'escrow'">
        <label class="text-xs font-medium">Passphrase</label>
        <input
          v-model="passphrase"
          type="password"
          autocomplete="new-password"
          class="mt-1 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
          placeholder="At least 12 characters"
        >
        <p v-if="passphraseTooShort" class="mt-1 text-xs text-muted-foreground">
          A few random words beats a short complicated one — it's easier to remember and much
          harder to guess.
        </p>

        <label class="mt-3 block text-xs font-medium">Confirm passphrase</label>
        <input
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          class="mt-1 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
        >
        <p v-if="passphraseMismatch" class="mt-1 text-xs text-destructive">Those don't match.</p>

        <p class="mt-3 rounded-lg border bg-muted/40 p-2.5 text-xs text-muted-foreground">
          There is no way to reset this. We can't read your backup and we can't help you get
          into it — if you forget the passphrase, the history it protects is gone.
        </p>

        <div v-if="hasEscrow" class="mt-3">
          <Button variant="ghost" class="text-destructive" :disabled="backup.busy.value" @click="turnOffEscrow">
            Delete my backup
          </Button>
        </div>
      </template>

      <template v-else-if="mode === 'file'">
        <p class="text-xs text-muted-foreground">
          We'll generate a code and download a file locked with it. Keep them somewhere
          separate from each other, and somewhere you'll still have in a year.
        </p>

        <div v-if="recoveryCode" class="mt-3 rounded-lg border bg-muted/40 p-3">
          <p class="text-xs font-medium">Your recovery code — shown once</p>
          <p class="mt-1.5 select-all break-all font-mono text-sm">{{ recoveryCode }}</p>
          <p class="mt-2 text-xs text-muted-foreground">
            Write this down now. It isn't stored anywhere, including here — closing this
            dialog loses it, and the file is useless without it.
          </p>
        </div>

        <Button class="mt-3 self-start" :disabled="backup.busy.value" @click="downloadRecoveryFile">
          <Download class="mr-1.5 size-3.5" />
          {{ recoveryCode ? 'Download again' : 'Generate and download' }}
        </Button>
      </template>

      <template v-else-if="mode === 'restore'">
        <p class="text-xs text-muted-foreground">
          Restoring only adds keys this device is missing. Anything it already has is left
          alone.
        </p>

        <label class="mt-3 block text-xs font-medium">From your passphrase backup</label>
        <input
          v-model="passphrase"
          type="password"
          autocomplete="current-password"
          class="mt-1 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
          :disabled="!hasEscrow || !!restoreFile"
          :placeholder="hasEscrow ? 'Your passphrase' : 'No backup stored on this account'"
        >

        <p class="my-3 text-center text-xs text-muted-foreground">or</p>

        <label class="block text-xs font-medium">From a recovery file</label>
        <label class="mt-1 flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted">
          <Upload class="size-3.5 shrink-0" />
          <span class="truncate">{{ restoreFile?.name ?? 'Choose file…' }}</span>
          <input type="file" accept="application/json" class="hidden" @change="onFilePicked">
        </label>
        <input
          v-if="restoreFile"
          v-model="restoreCode"
          class="mt-2 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
          placeholder="Recovery code"
        >
      </template>

      <p v-if="error" class="mt-3 text-xs text-destructive">{{ error }}</p>
      <p v-if="done" class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ done }}</p>

      <div class="mt-4 flex justify-end gap-2">
        <Button v-if="mode !== 'choose'" variant="ghost" @click="mode = 'choose'; error = null; done = null">
          Back
        </Button>
        <Button v-if="mode === 'escrow'" :disabled="!canSubmitEscrow" @click="enableEscrow">
          <Loader2 v-if="backup.busy.value" class="mr-1.5 size-3.5 animate-spin" />
          {{ backup.busy.value ? 'Encrypting…' : 'Back up my keys' }}
        </Button>
        <Button
          v-else-if="mode === 'restore'"
          :disabled="backup.busy.value || (!restoreFile && !passphrase)"
          @click="restore"
        >
          <Loader2 v-if="backup.busy.value" class="mr-1.5 size-3.5 animate-spin" />
          Restore
        </Button>
        <Button v-else-if="mode === 'choose'" variant="ghost" @click="emit('close')">Close</Button>
      </div>
    </div>
  </div>
</template>
