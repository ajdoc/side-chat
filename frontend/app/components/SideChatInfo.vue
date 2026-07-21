<script setup lang="ts">
import { CheckCircle2, LogOut, Pin, Rocket, UserPlus } from 'lucide-vue-next'
import type { Message, SideChat } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The Info tab of a side chat's workspace — the side chat *about itself*, distinct from the
 * channel's own Info. It gathers what the Chat tab scatters across its edges: who's in the
 * room, where it came from, what it has concluded (its decisions and pins), and the two
 * roster powers — bring people in, or leave. Everything here is a read of the live
 * `sideChat` the panel already keeps fresh over the stream; this tab only presents it.
 */
defineProps<{
  sideChat: SideChat | null
  highlights: { decisions: Message[], pinned: Message[] }
  joined: boolean
}>()

const emit = defineEmits<{
  jump: [messageId: number]
  'add-people': []
  leave: []
}>()

// Names here follow whatever people are called in this server or chat — see useNicknames.
// `origin_author` is deliberately left alone: it's a snapshot of a deleted message's
// author, kept as text with no id to resolve.
const { nameFor } = useNicknames()

function excerpt(body: string | null) {
  const text = (body ?? '').replace(/\s+/g, ' ').trim()
  return text.length > 80 ? `${text.slice(0, 80)}…` : text || '(no text)'
}
function relTime(iso: string) {
  const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (secs < 60) return 'just now'
  const mins = Math.round(secs / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  return hrs < 24 ? `${hrs}h ago` : `${Math.round(hrs / 24)}d ago`
}
</script>

<template>
  <div v-if="sideChat" class="flex-1 space-y-4 overflow-y-auto p-4 text-sm">
    <!-- Identity -->
    <div>
      <div class="flex items-center gap-2 font-semibold">
        <Rocket class="h-4 w-4 text-primary" />
        <span class="truncate">{{ sideChat.name }}</span>
      </div>
      <p class="mt-1 text-xs text-muted-foreground">
        <template v-if="sideChat.creator">Started by {{ nameFor(sideChat.creator) }} · </template>
        {{ relTime(sideChat.created_at) }}
      </p>
    </div>

    <!-- Started from -->
    <div v-if="sideChat.parent_message" class="rounded-lg border bg-muted/40 p-3">
      <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
      <span class="font-medium">{{ nameFor(sideChat.parent_message.user) }}</span>
      <MarkdownBody v-if="sideChat.parent_message.body" :source="sideChat.parent_message.body" />
    </div>
    <div v-else-if="sideChat.origin_author" class="rounded-lg border border-dashed bg-muted/20 p-3">
      <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
      <span class="font-medium">{{ sideChat.origin_author }}</span>
      <p v-if="sideChat.origin_excerpt" class="text-muted-foreground">{{ sideChat.origin_excerpt }}</p>
      <p class="mt-1 text-[11px] italic text-muted-foreground">The original message was deleted.</p>
    </div>

    <!-- Roster -->
    <div>
      <div class="mb-2 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase text-muted-foreground">
          Members · {{ sideChat.participants_count ?? sideChat.participants?.length ?? 0 }}
        </span>
        <button
          v-if="joined"
          class="flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          @click="emit('add-people')"
        >
          <UserPlus class="h-3.5 w-3.5" /> Add
        </button>
      </div>
      <ParticipantList :members="sideChat.participants ?? []" :creator-id="sideChat.creator?.id ?? null" />
    </div>

    <!-- Decisions & pins -->
    <div v-if="highlights.decisions.length || highlights.pinned.length" class="rounded-lg border bg-muted/30 p-2">
      <div v-if="highlights.decisions.length" class="mb-1.5">
        <div class="mb-1 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
          <CheckCircle2 class="h-3.5 w-3.5" /> Decisions · {{ highlights.decisions.length }}
        </div>
        <button
          v-for="d in highlights.decisions"
          :key="d.id"
          class="block w-full truncate rounded px-1.5 py-1 text-left text-xs hover:bg-muted"
          :title="d.body ?? ''"
          @click="emit('jump', d.id)"
        >
          <span class="font-medium">{{ nameFor(d.user) }}:</span> {{ excerpt(d.body) }}
        </button>
      </div>
      <div v-if="highlights.pinned.length">
        <div class="mb-1 flex items-center gap-1 text-xs font-semibold text-primary">
          <Pin class="h-3.5 w-3.5" /> Pinned · {{ highlights.pinned.length }}
        </div>
        <button
          v-for="p in highlights.pinned"
          :key="p.id"
          class="block w-full truncate rounded px-1.5 py-1 text-left text-xs hover:bg-muted"
          :title="p.body ?? ''"
          @click="emit('jump', p.id)"
        >
          <span class="font-medium">{{ nameFor(p.user) }}:</span> {{ excerpt(p.body) }}
        </button>
      </div>
    </div>

    <!-- Leave -->
    <Button v-if="joined" variant="outline" size="sm" class="w-full gap-1.5 text-muted-foreground" @click="emit('leave')">
      <LogOut class="h-4 w-4" /> Leave side chat
    </Button>
  </div>
</template>
