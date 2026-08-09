<script setup lang="ts">
import { Loader2, LockKeyhole, X } from 'lucide-vue-next'
import type { Channel } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The encryption switch for one timeline.
 *
 * Almost all of this component is the explanation rather than the control, and deliberately.
 * The control is a radio pair; what somebody actually needs before touching it is the answer
 * to three questions they will otherwise find out the hard way:
 *
 *  1. **What stops working.** Search, bots, automations and link previews all go dark for
 *     encrypted messages. People turn this on expecting nothing to change, and then file a
 *     bug about search being broken.
 *  2. **What it does not protect.** Turning it on does not reach back. Everything already in
 *     this channel has been on the server in plain text for as long as it has existed, and a
 *     padlock that appeared to cover it would be actively misleading.
 *  3. **That turning it off doesn't undo it.** The encrypted era stays encrypted forever —
 *     the server never had those keys — so search never comes back for those messages even
 *     though it comes back for everything sent afterwards.
 *
 * Saying all three plainly costs a paragraph. Not saying them costs somebody their
 * understanding of what the padlock means, which is the entire value of the feature.
 */
const props = defineProps<{ channel: Channel }>()
const emit = defineEmits<{ close: [], saved: [Channel] }>()

const api = useApi()
const { patchChannel } = useServer()

const wanted = ref(!!props.channel.encrypted)
const saving = ref(false)
const error = ref<string | null>(null)

const current = computed(() => !!props.channel.encrypted)
const changed = computed(() => wanted.value !== current.value)

/**
 * Whether this channel has ever been encrypted before.
 *
 * A non-zero epoch with encryption off means there is an unreadable era in the history, which
 * is worth mentioning when somebody is deciding — it explains the "can't read this" rows they
 * may already have noticed.
 */
const hasPastEra = computed(() => (props.channel.encryption_epoch ?? 0) > 0)

async function save() {
  if (saving.value || !changed.value) return
  saving.value = true
  error.value = null

  try {
    const res = await api<{ data: Channel }>(`/api/channels/${props.channel.id}/encryption`, {
      method: 'PUT',
      body: { encrypted: wanted.value },
    })

    // Patch the sidebar now so the padlock doesn't wait for the broadcast to come back.
    patchChannel(props.channel.id, {
      encrypted: res.data.encrypted,
      encryption_epoch: res.data.encryption_epoch,
    })
    emit('saved', res.data)
    emit('close')
  } catch {
    error.value = "Couldn't change the encryption setting."
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[85vh] w-full max-w-md flex-col overflow-y-auto rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="flex items-center gap-2 font-semibold">
          <LockKeyhole class="size-4" />
          Encryption
        </h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <div class="space-y-2">
        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5" :class="!wanted && 'border-primary bg-primary/5'">
          <input type="radio" :checked="!wanted" class="mt-1 accent-primary" @change="wanted = false">
          <span class="text-sm">
            <span class="font-medium">Off</span>
            <span class="block text-xs text-muted-foreground">
              Messages are stored so the server can read them. Search, bots and link previews
              all work.
            </span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5" :class="wanted && 'border-primary bg-primary/5'">
          <input type="radio" :checked="wanted" class="mt-1 accent-primary" @change="wanted = true">
          <span class="text-sm">
            <span class="font-medium">End-to-end encrypted</span>
            <span class="block text-xs text-muted-foreground">
              Messages and files are encrypted on the sender's device. Only people in this
              conversation can read them.
            </span>
          </span>
        </label>
      </div>

      <!--
        The consequences, shown against whichever direction they're actually heading in.
        A single always-visible block would be half irrelevant either way, and people stop
        reading warnings that don't apply to them.
      -->
      <div v-if="changed && wanted" class="mt-3 space-y-2 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
        <p class="font-medium text-foreground">What changes from here on:</p>
        <ul class="list-disc space-y-1 pl-4">
          <li>Search won't look inside new messages, and will say how many it skipped.</li>
          <li>Bots and automations stop seeing what's said here.</li>
          <li>Links won't unfurl, and @mentions won't send mention notifications.</li>
          <li>Files are encrypted too — but a GIF from the picker is a link to its provider, not a file we can encrypt.</li>
        </ul>
        <p class="pt-1">
          <span class="font-medium text-foreground">Messages already here stay as they are.</span>
          They've been readable by the server since they were sent, and turning this on doesn't
          change that.
        </p>
      </div>

      <div v-if="changed && !wanted" class="mt-3 space-y-2 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
        <p>
          <span class="font-medium text-foreground">Messages sent from now on will be readable by the server again</span>
          — search, bots and previews come back for them.
        </p>
        <p>
          Everything sent while encryption was on
          <span class="font-medium text-foreground">stays encrypted permanently</span>.
          Nobody can undo that, including us — the server never had the keys.
        </p>
      </div>

      <p v-if="!changed && hasPastEra && !current" class="mt-3 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
        This conversation has an encrypted stretch in its history. Those messages stay
        unreadable to search and to any device without the keys.
      </p>

      <p v-if="error" class="mt-2 text-xs text-destructive">{{ error }}</p>

      <div class="mt-4 flex justify-end gap-2">
        <Button variant="ghost" @click="emit('close')">Cancel</Button>
        <Button :disabled="saving || !changed" @click="save">
          <Loader2 v-if="saving" class="mr-1.5 size-3.5 animate-spin" />
          {{ saving ? 'Saving…' : (wanted ? 'Turn on encryption' : 'Turn off encryption') }}
        </Button>
      </div>
    </div>
  </div>
</template>
