<script setup lang="ts">
import { Hash, Loader2, Map as MapIcon, Volume2 } from 'lucide-vue-next'
import type { Channel } from '~/types'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'

/**
 * Turning a channel into a different kind of channel.
 *
 * ## What it promises, in the copy
 *
 * That nothing is lost. A conversion moves the *lid*: a Side Space is a timeline with a map over
 * it, a voice channel a timeline with a call over it, and the messages, threads, side chats,
 * pins and every Side Desk app hang off the channel either way. People will not press this button
 * unless the screen says so, and the screen can say so because it's true.
 *
 * The two consequences that aren't reversible-by-symmetry are stated too: a call in progress ends
 * (a seat in a room the UI no longer draws is a ghost), and becoming a Side Space makes a map —
 * once. Converting away and back keeps the furniture somebody placed.
 */
const props = defineProps<{ channel: Channel }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()
const { patchChannel } = useServer()

const KINDS = [
  { id: 'text', label: 'Text', icon: Hash, hint: 'A timeline. No call.' },
  { id: 'voice', label: 'Voice', icon: Volume2, hint: 'A call, over the same timeline.' },
  { id: 'space', label: 'Side Space', icon: MapIcon, hint: 'A room you walk around in.' },
] as const

const picked = ref<'text' | 'voice' | 'space'>(props.channel.type as any)
const saving = ref(false)
const error = ref('')

const open = ref(true)
watch(open, value => { if (!value) emit('close') })

const changed = computed(() => picked.value !== props.channel.type)

async function save() {
  if (!changed.value || saving.value) return
  saving.value = true
  error.value = ''

  try {
    const res = await api<{ data: Channel }>(`/api/channels/${props.channel.id}/type`, {
      method: 'PATCH',
      body: { type: picked.value },
    })
    // Straight into the sidebar's copy, so the row redraws as the kind it now is without a
    // reload — the same path a rename takes.
    patchChannel(props.channel.id, res.data)
    emit('close')
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'That channel couldn’t be converted.'
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>What kind of channel is “{{ channel.name }}”?</DialogTitle>
        <DialogDescription>
          Nothing is lost either way — the messages, threads, side chats and Side Desk apps belong
          to the channel, not to its kind.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-2">
        <button
          v-for="kind in KINDS"
          :key="kind.id"
          type="button"
          class="flex w-full items-start gap-2.5 rounded-md border p-2.5 text-left transition-colors"
          :class="picked === kind.id ? 'border-primary bg-primary/5' : 'hover:bg-muted'"
          @click="picked = kind.id"
        >
          <component :is="kind.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
          <span class="min-w-0">
            <span class="block text-sm font-medium">
              {{ kind.label }}
              <span v-if="channel.type === kind.id" class="text-xs font-normal text-muted-foreground">· now</span>
            </span>
            <span class="block text-xs text-muted-foreground">{{ kind.hint }}</span>
          </span>
        </button>
      </div>

      <!-- The two consequences symmetry doesn't cover. -->
      <p v-if="changed" class="text-xs text-muted-foreground">
        <template v-if="picked === 'text' && channel.type !== 'text'">
          Anybody in the call will be disconnected.
        </template>
        <template v-else-if="picked === 'space'">
          It gets a blank map to walk around, if it hasn’t got one already — an existing map is kept.
        </template>
      </p>

      <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

      <div class="flex gap-2">
        <Button size="sm" :disabled="!changed || saving" class="gap-1.5" @click="save">
          <Loader2 v-if="saving" class="h-3.5 w-3.5 animate-spin" />
          Convert
        </Button>
        <Button size="sm" variant="ghost" @click="emit('close')">Cancel</Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
