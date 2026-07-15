<script setup lang="ts">
import { Check, Eye, EyeOff, Loader2 } from 'lucide-vue-next'
import type { Message, MessageInfo } from '~/types'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

const props = defineProps<{ message: Message }>()

const open = defineModel<boolean>('open', { default: false })

const api = useApi()
const { stripMarkdown } = useMarkdown()

const info = ref<MessageInfo | null>(null)
const loading = ref(false)

const preview = computed(() => {
  const body = props.message.body ? stripMarkdown(props.message.body) : ''
  if (body) return body

  const count = props.message.attachments?.length ?? 0
  return count ? `${count} attachment${count === 1 ? '' : 's'}` : 'No text'
})

// Fetched on open, not up front: this is a per-message request and most messages are
// never asked about. `immediate` because the parent mounts us with v-if — keeping a
// Dialog per message alive in a virtualised list of hundreds would be wasteful.
watch(open, async (isOpen) => {
  if (!isOpen) return

  loading.value = true
  info.value = null
  try {
    const res = await api<{ data: MessageInfo }>(`/api/messages/${props.message.id}/info`)
    info.value = res.data
  } finally {
    loading.value = false
  }
}, { immediate: true })

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

function formatSeenAt(iso: string) {
  const date = new Date(iso)
  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

  return date.toDateString() === new Date().toDateString()
    ? time
    : `${date.toLocaleDateString([], { month: 'short', day: 'numeric' })}, ${time}`
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Message info</DialogTitle>
        <DialogDescription class="line-clamp-2 border-l-2 pl-2 text-left italic">
          {{ preview }}
        </DialogDescription>
      </DialogHeader>

      <div v-if="loading" class="flex justify-center py-6">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <div v-else-if="info" class="space-y-5">
        <!-- Reactions -->
        <section v-if="info.reactions.length">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Reactions
          </h3>
          <div v-for="reaction in info.reactions" :key="reaction.emoji" class="mb-2 last:mb-0">
            <div class="flex items-center gap-2">
              <span class="text-base leading-none">{{ reaction.emoji }}</span>
              <span class="text-xs text-muted-foreground">{{ reaction.count }}</span>
            </div>
            <ul class="mt-1 space-y-1 pl-6">
              <li v-for="u in reaction.users" :key="u.id" class="flex items-center gap-2 text-sm">
                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-[10px] font-semibold text-secondary-foreground">
                  {{ initials(u.name) }}
                </span>
                {{ u.name }}
              </li>
            </ul>
          </div>
        </section>

        <!--
          Thread replies have no receipts to show: read markers are per channel and only
          ever advance to main-timeline messages, so "seen" would be a guess.
        -->
        <p v-if="!info.receipts_tracked" class="rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
          Read receipts aren’t tracked for thread replies — only for the main channel timeline.
        </p>

        <template v-else>
          <section>
            <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <Eye class="h-3.5 w-3.5" /> Seen by
              <span class="font-normal normal-case">({{ info.seen_by.length }})</span>
            </h3>
            <ul v-if="info.seen_by.length" class="space-y-1.5">
              <li v-for="entry in info.seen_by" :key="entry.user.id" class="flex items-center gap-2 text-sm">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                  {{ initials(entry.user.name) }}
                </span>
                <span class="min-w-0 flex-1 truncate">{{ entry.user.name }}</span>
                <Check class="h-3.5 w-3.5 shrink-0 text-green-600 dark:text-green-400" />
                <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                  {{ formatSeenAt(entry.read_at) }}
                </span>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Nobody has read this yet.</p>
          </section>

          <section v-if="info.not_seen_by.length">
            <h3 class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <EyeOff class="h-3.5 w-3.5" /> Not seen by
              <span class="font-normal normal-case">({{ info.not_seen_by.length }})</span>
            </h3>
            <ul class="space-y-1.5">
              <li v-for="member in info.not_seen_by" :key="member.id" class="flex items-center gap-2 text-sm text-muted-foreground">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-[10px] font-semibold">
                  {{ initials(member.name) }}
                </span>
                <span class="min-w-0 flex-1 truncate">{{ member.name }}</span>
              </li>
            </ul>
          </section>
        </template>
      </div>
    </DialogContent>
  </Dialog>
</template>
