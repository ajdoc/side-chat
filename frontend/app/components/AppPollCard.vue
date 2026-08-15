<script setup lang="ts">
import { MessageCircle, Vote } from 'lucide-vue-next'
import type { AppPoll } from '~/types'

/**
 * One poll, as a card on the wall.
 *
 * A summary, not a ballot: the type, the question, how many answered, and the traffic under it.
 * Voting happens in the detail view, because a card that could be voted on would need the
 * options — and four cards' worth of radio buttons is a form, not a wall.
 */
const props = defineProps<{ poll: AppPoll }>()

defineEmits<{ open: [AppPoll] }>()

/** The kind, in the words the card prints above the question. */
const typeLabel = computed(() => ({
  yes_no: 'Yes/No',
  single: 'Single choice',
  multiple: 'Multiple choice',
}[props.poll.type] ?? props.poll.type))

/**
 * "17 votes" or "27 voters".
 *
 * A multiple-choice poll gets several rows from one person, so counting rows would print a
 * number larger than the people in the channel. See AppPoll::voterCount.
 */
const tally = computed(() => {
  const p = props.poll
  return p.type === 'multiple'
    ? `${p.voter_count ?? 0} voter${(p.voter_count ?? 0) === 1 ? '' : 's'}`
    : `${p.vote_count ?? 0} vote${(p.vote_count ?? 0) === 1 ? '' : 's'}`
})
</script>

<template>
  <button
    type="button"
    class="flex w-full flex-col rounded-xl border bg-muted/20 p-4 text-left transition-colors hover:border-primary/50 hover:bg-muted/40"
    @click="$emit('open', poll)"
  >
    <div class="flex items-start justify-between gap-2">
      <span class="text-[11px] font-semibold uppercase tracking-wide text-sky-400">{{ typeLabel }}</span>
      <span
        class="shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium"
        :class="poll.closed
          ? 'border-border text-muted-foreground'
          : 'border-emerald-500/40 text-emerald-500'"
      >{{ poll.closed ? 'Closed' : 'Active' }}</span>
    </div>

    <p class="mt-2 text-lg font-semibold leading-snug">{{ poll.question }}</p>
    <p v-if="poll.description" class="mt-1 line-clamp-2 text-sm text-muted-foreground">
      {{ poll.description }}
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <span class="flex items-center gap-1.5">
        <Vote class="h-3.5 w-3.5" /> <span class="font-semibold text-foreground">{{ tally }}</span>
      </span>
      <span class="flex items-center gap-1.5">
        <MessageCircle class="h-3.5 w-3.5" /> {{ poll.comment_count ?? 0 }}
      </span>
      <!-- The reaction chips carry through to the card, so the wall shows which polls people
           actually responded to rather than only which were voted on. -->
      <span
        v-for="r in poll.reactions ?? []"
        :key="r.emoji"
        class="flex items-center gap-1 rounded-full px-1.5 py-0.5"
        :class="r.reacted && 'bg-primary/15'"
      >{{ r.emoji }} {{ r.count }}</span>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2 border-t pt-2 text-[11px] text-muted-foreground">
      <span>{{ poll.options?.length ?? 0 }} options</span>
      <!-- Tags on the card as well as in the detail, so the wall can be scanned by them —
           the same reason a tracker task row carries its own. -->
      <TrackerTagChips v-if="poll.tags?.length" :tags="poll.tags" class="ml-auto" />
    </div>
  </button>
</template>
