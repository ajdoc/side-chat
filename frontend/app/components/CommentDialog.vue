<script setup lang="ts">
import { Loader2, Trash2, X } from 'lucide-vue-next'
import type { Comment, Message } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * The full comment list behind a message's chips, with a composer to add one.
 *
 * A comment is short by design — feedback, not a conversation — so the composer is a single
 * line with a hard 120-character cap and room for one leading emoji, nothing else. Posting
 * the same phrase twice just toggles it off, which is exactly how a chip behaves; the two
 * are the same action seen from two places.
 */
const props = defineProps<{ message: Message }>()

const open = defineModel<boolean>('open', { default: false })

const { user } = useAuth()
const { list, toggle, remove } = useComments()

const MAX = 120
const EMOJIS = ['', '✅', '⚠️', '💡', '👍', '❤️', '🔥', '👀', '🎉', '🤔']

const comments = ref<Comment[]>([])
const loading = ref(false)
const body = ref('')
const emoji = ref('')
const posting = ref(false)

const remaining = computed(() => MAX - body.value.length)

async function refresh() {
  loading.value = true
  try {
    comments.value = await list(props.message.id)
  } finally {
    loading.value = false
  }
}

watch(open, (isOpen) => { if (isOpen) refresh() }, { immediate: true })

async function submit() {
  const text = body.value.trim()
  if (!text || text.length > MAX || posting.value) return
  posting.value = true
  try {
    await toggle(props.message.id, text, emoji.value || null)
    body.value = ''
    emoji.value = ''
    await refresh()
  } finally {
    posting.value = false
  }
}

async function onRemove(id: number) {
  await remove(id)
  comments.value = comments.value.filter(c => c.id !== id)
}

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Comments</DialogTitle>
        <DialogDescription>Leave a short piece of feedback on this message.</DialogDescription>
      </DialogHeader>

      <!-- Composer -->
      <form class="space-y-2" @submit.prevent="submit">
        <div class="flex items-center gap-2">
          <select
            v-model="emoji"
            class="h-9 shrink-0 rounded-md border bg-background px-2 text-base"
            aria-label="Optional emoji"
          >
            <option v-for="e in EMOJIS" :key="e" :value="e">{{ e || '—' }}</option>
          </select>
          <Input v-model="body" :maxlength="MAX" placeholder="Love this idea." autofocus />
        </div>
        <div class="flex items-center justify-between">
          <span class="text-xs tabular-nums" :class="remaining < 0 ? 'text-destructive' : 'text-muted-foreground'">
            {{ remaining }} left
          </span>
          <Button type="submit" size="sm" :disabled="!body.trim() || remaining < 0 || posting">
            {{ posting ? 'Posting…' : 'Comment' }}
          </Button>
        </div>
      </form>

      <!-- List -->
      <div class="max-h-72 space-y-2 overflow-y-auto">
        <div v-if="loading" class="flex justify-center py-4">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
        <p v-else-if="!comments.length" class="py-4 text-center text-sm text-muted-foreground">
          No comments yet.
        </p>
        <div v-for="c in comments" :key="c.id" class="group flex items-start gap-2 rounded p-1.5 hover:bg-muted/50">
          <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
            {{ initials(c.user.name) }}
          </div>
          <div class="min-w-0 flex-1">
            <div class="flex items-baseline gap-2">
              <span class="text-xs font-semibold">{{ c.user.name }}</span>
              <span class="text-[10px] text-muted-foreground">{{ formatTime(c.created_at) }}</span>
            </div>
            <p class="break-words text-sm">
              <span v-if="c.emoji" class="mr-1">{{ c.emoji }}</span>{{ c.body }}
            </p>
          </div>
          <button
            v-if="c.user.id === user?.id"
            class="rounded p-1 text-muted-foreground opacity-0 transition hover:text-destructive group-hover:opacity-100"
            title="Delete comment"
            @click="onRemove(c.id)"
          >
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
