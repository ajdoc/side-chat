<script setup lang="ts">
import { ArrowRight, CheckCircle2, MessageSquare, Pin, Rocket, Users } from 'lucide-vue-next'
import type { SideChat } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The living-object card: a side chat as it appears in the main timeline, on the message it
 * was spun off. Not an inline reply — a standing object with its own pulse (members,
 * messages, pinned, decisions, last-active) that updates in place over the channel stream.
 */
const props = defineProps<{
  sideChat: SideChat
  currentUserId: number | null
}>()

const emit = defineEmits<{ open: [] }>()

const { join } = useSideChats()
const joining = ref(false)

const joined = computed(() =>
  props.currentUserId != null && (props.sideChat.participant_ids?.includes(props.currentUserId) ?? false),
)

async function onJoin() {
  if (joining.value) return
  joining.value = true
  try {
    await join(props.sideChat.id)
    // The refreshed roster arrives over the stream; step straight into the room.
    emit('open')
  } finally {
    joining.value = false
  }
}

function timeAgo(iso: string) {
  const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (secs < 60) return 'just now'
  const mins = Math.round(secs / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.round(hrs / 24)}d ago`
}
</script>

<template>
  <div class="mt-1.5 max-w-sm rounded-lg border bg-muted/30 p-3">
    <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Rocket class="h-3.5 w-3.5" /> Side Chat
    </div>
    <p class="mt-0.5 truncate font-semibold">{{ sideChat.name }}</p>

    <!-- The pulse: 👥 members · 💬 messages · 📌 pinned · ✅ decisions -->
    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
      <span class="flex items-center gap-1" :title="`${sideChat.participants_count ?? 0} members`">
        <Users class="h-3.5 w-3.5" /> {{ sideChat.participants_count ?? 0 }}
      </span>
      <span class="flex items-center gap-1" :title="`${sideChat.messages_count ?? 0} messages`">
        <MessageSquare class="h-3.5 w-3.5" /> {{ sideChat.messages_count ?? 0 }}
      </span>
      <span v-if="(sideChat.pinned_count ?? 0) > 0" class="flex items-center gap-1" title="Pinned">
        <Pin class="h-3.5 w-3.5" /> {{ sideChat.pinned_count }}
      </span>
      <span v-if="(sideChat.decisions_count ?? 0) > 0" class="flex items-center gap-1" title="Decisions">
        <CheckCircle2 class="h-3.5 w-3.5" /> {{ sideChat.decisions_count }}
      </span>
    </div>

    <p class="mt-1 text-[11px] text-muted-foreground">
      Last active {{ timeAgo(sideChat.last_active_at) }}
    </p>

    <div class="mt-2.5 flex items-center gap-2">
      <template v-if="joined">
        <Button size="sm" class="gap-1" @click="emit('open')">
          Open Side Chat <ArrowRight class="h-3.5 w-3.5" />
        </Button>
      </template>
      <template v-else>
        <Button size="sm" :disabled="joining" @click="onJoin">
          {{ joining ? 'Joining…' : 'Join' }}
        </Button>
        <button class="text-xs text-muted-foreground hover:text-foreground hover:underline" @click="emit('open')">
          Peek in
        </button>
      </template>
    </div>
  </div>
</template>
