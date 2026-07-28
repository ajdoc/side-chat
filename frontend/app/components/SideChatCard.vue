<script setup lang="ts">
import { ArrowRight, CheckCircle2, MessageSquare, Pencil, Pin, Reply, Rocket, Trash2, Users } from 'lucide-vue-next'
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

const emit = defineEmits<{
  open: []
  /** Open the side chat *and* its reply box — "Reply" from out here in the timeline. */
  reply: []
}>()

const { join, react, removeSideChat } = useSideChats()
const { nameFor } = useNicknames()
const joining = ref(false)
const editing = ref(false)
const showComments = ref(false)
const confirmDelete = ref(false)
const deleting = ref(false)

/**
 * Whether the person reading this is the one who started it.
 *
 * Drawn as an "OP" badge next to the author, the forum convention: in a list of posts the
 * useful thing to know about a name is whether it's the person whose post you're reading.
 */
const op = computed(() => props.sideChat.creator ?? null)

async function onReact(emoji: string) {
  await react(props.sideChat.id, emoji)
}

async function onDelete() {
  if (deleting.value) return
  deleting.value = true
  try {
    await removeSideChat(props.sideChat.id)
  } finally {
    deleting.value = false
    confirmDelete.value = false
  }
}

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
      <!-- The OP's own controls (or a server admin's). `can_manage` is the server's answer,
           so nothing here is a button that would come back 403. -->
      <span v-if="sideChat.can_manage" class="ml-auto flex items-center gap-0.5">
        <button class="p-0.5 text-muted-foreground hover:text-foreground" title="Edit title and tags" aria-label="Edit side chat" @click="editing = true">
          <Pencil class="h-3.5 w-3.5" />
        </button>
        <button class="p-0.5 text-muted-foreground hover:text-destructive" title="Delete side chat" aria-label="Delete side chat" @click="confirmDelete = true">
          <Trash2 class="h-3.5 w-3.5" />
        </button>
      </span>
    </div>
    <p class="mt-0.5 truncate font-semibold">{{ sideChat.name }}</p>

    <!-- Who started it, badged OP the way a forum does. -->
    <p v-if="op" class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
      <span class="truncate">{{ nameFor(op) }}</span>
      <span class="rounded bg-primary/10 px-1 py-px text-[9px] font-bold uppercase tracking-wide text-primary" title="Original poster — started this side chat">OP</span>
    </p>

    <!-- Tags: what the post is about, and the handle the list filters on. -->
    <div v-if="sideChat.tags?.length" class="mt-1.5 flex flex-wrap gap-1">
      <span
        v-for="tag in sideChat.tags"
        :key="tag"
        class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
      >{{ tag }}</span>
    </div>

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

    <!-- Reactions to the *post*. Always shown, picker included: the card is the only
         surface here, so without it the first reaction would be unreachable. -->
    <ReactionBar
      :reactions="sideChat.reactions ?? []"
      :current-user-id="currentUserId"
      always-show
      @toggle="onReact"
    />
    <!-- And word-reactions: short feedback *about* the topic. Replying is the button
         below — that opens the room and posts into its timeline. -->
    <CommentBar
      :subject="{ kind: 'sideChat', id: sideChat.id }"
      :comments="sideChat.comments ?? []"
      :current-user-id="currentUserId"
      always-show
      @open="showComments = true"
    />

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

      <!-- Replying happens inside, against the title — so this opens the room with the
           reply box already up, rather than dropping you in to find it. -->
      <button
        class="ml-auto flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
        title="Reply to this side chat"
        @click="emit('reply')"
      >
        <Reply class="h-3.5 w-3.5" /> Reply
      </button>
    </div>

    <!-- Deleting takes the whole room — timeline, threads, board — so it's confirmed inline
         rather than behind a modal that would cover the card you're deleting. -->
    <div v-if="confirmDelete" class="mt-2 rounded-lg border border-destructive/40 bg-destructive/10 p-2 text-xs">
      <p>Delete this side chat and everything in it? This can't be undone.</p>
      <div class="mt-2 flex gap-2">
        <Button size="sm" variant="destructive" :disabled="deleting" @click="onDelete">
          {{ deleting ? 'Deleting…' : 'Delete' }}
        </Button>
        <Button size="sm" variant="ghost" @click="confirmDelete = false">Cancel</Button>
      </div>
    </div>

    <SideChatEditDialog v-if="editing" :side-chat="sideChat" @close="editing = false" />
    <CommentDialog v-if="showComments" v-model:open="showComments" :subject="{ kind: 'sideChat', id: sideChat.id }" />
  </div>
</template>
