<script setup lang="ts">
import { MessageSquareText } from 'lucide-vue-next'
import type { CommentSubject } from '~/composables/useComments'
import type { CommentSummary } from '~/types'

/**
 * The "popular comments" strip: word-reactions shown as chips, most
 * co-signed first — `✓ Looks good (18)`. Clicking a chip co-signs the phrase (or takes
 * your co-sign back), exactly like a reaction; the "Comment" pill opens the full composer.
 *
 * Both actions go straight to the API through useComments — the refreshed chips arrive back
 * over the same real-time stream that carries reactions, so nothing here holds local state.
 */
const props = defineProps<{
  /**
   * What these chips are about — a message in a timeline, or a side chat *post*. The two
   * are stored separately and read differently ("this line" vs "this topic"), so the bar
   * is told which rather than inferring it from an id.
   */
  subject: CommentSubject
  comments: CommentSummary[]
  currentUserId: number | null
  /**
   * Keep the strip (and so the "+" pill) on screen with nothing commented yet.
   *
   * A message doesn't need it: MessageReactPopover is the hover affordance for the first
   * comment, and an empty row under every line would be noise. A post has no hover
   * affordance — the card *is* the surface — so without this the first comment would be
   * unreachable. Same argument as ReactionBar's.
   */
  alwaysShow?: boolean
}>()

const emit = defineEmits<{ open: [] }>()

const { toggle } = useComments()

/** The API ships the co-signers, not an "is this mine" flag — one broadcast, many viewers. */
function isMine(comment: CommentSummary) {
  return props.currentUserId != null && comment.users.some(u => u.id === props.currentUserId)
}

function tooltip(comment: CommentSummary) {
  const names = comment.users.map(u => (u.id === props.currentUserId ? 'You' : u.name))
  const shown = names.length <= 3 ? names : [...names.slice(0, 3), `${names.length - 3} more`]
  return `${listToText(shown)} said “${comment.body}”`
}

function listToText(names: string[]) {
  if (names.length <= 1) return names[0] ?? ''
  return `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
}

function onToggle(comment: CommentSummary) {
  // Fire-and-forget: the refreshed summary lands over the stream (we receive our own
  // broadcast too), so there's nothing to await here.
  toggle(props.subject, comment.body, comment.emoji)
}
</script>

<template>
  <div v-if="comments.length || alwaysShow" class="mt-1 flex flex-wrap items-center gap-1">
    <MessageSquareText class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    <button
      v-for="comment in comments"
      :key="comment.key"
      type="button"
      class="flex h-6 max-w-[16rem] items-center gap-1 rounded-full border px-2 text-xs transition"
      :class="isMine(comment)
        ? 'border-primary bg-primary/10 font-medium text-foreground'
        : 'bg-muted/40 text-muted-foreground hover:bg-muted'"
      :title="tooltip(comment)"
      :aria-pressed="isMine(comment)"
      @click="onToggle(comment)"
    >
      <span v-if="comment.emoji" class="text-sm leading-none">{{ comment.emoji }}</span>
      <span class="truncate">{{ comment.body }}</span>
      <span class="shrink-0 tabular-nums opacity-70">{{ comment.count }}</span>
    </button>

    <button
      type="button"
      class="flex h-6 items-center rounded-full border border-dashed px-2 text-xs text-muted-foreground transition hover:border-solid hover:bg-muted hover:text-foreground"
      title="Add a comment"
      @click="emit('open')"
    >
      +
    </button>
  </div>
</template>
