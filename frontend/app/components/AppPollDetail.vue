<script setup lang="ts">
import { Lock, Trash2, Unlock } from 'lucide-vue-next'
import type { AppPoll } from '~/types'

/**
 * One poll, opened: the question, the ballot, the results, reactions and a comment thread.
 *
 * Ballot and results are the same list rather than two views of it. Hiding results until you've
 * voted is the usual instinct and it's wrong here — this is a team poll, not a survey, and
 * people ask "what did everyone say" far more often than they cast a first vote. So every row
 * is both a button and a bar.
 */
const props = defineProps<{
  poll: AppPoll
  canEdit: boolean
}>()

const emit = defineEmits<{
  vote: [number[]]
  patch: [{ question?: string, description?: string | null, closed?: boolean }]
  react: [string]
  remove: []
}>()

const { user } = useAuth()

/** The emoji offered under the question. A short fixed row, not a full picker. */
const QUICK_REACTIONS = ['👍', '❤️', '🔥', '💡', '👀']

const mine = computed(() => new Set(props.poll.my_option_ids ?? []))

const total = computed(() =>
  (props.poll.options ?? []).reduce((sum, o) => sum + o.votes, 0))

/**
 * The share of the vote a row drew.
 *
 * Out of total *rows*, not voters — on a multiple-choice poll the percentages then sum past
 * 100, which is correct and is what "94% picked Yes" has to mean when people pick several.
 */
function share(votes: number) {
  return total.value === 0 ? 0 : Math.round((votes / total.value) * 100)
}

/**
 * Click a row to vote.
 *
 * Single-choice replaces; multiple toggles. Clicking the option you already chose clears it —
 * un-voting has no other affordance, and a poll you can't withdraw from is a poll people are
 * reluctant to answer.
 */
function pick(optionId: number) {
  if (props.poll.closed || !props.canEdit) return

  if (props.poll.type === 'multiple') {
    const next = new Set(mine.value)
    next.has(optionId) ? next.delete(optionId) : next.add(optionId)
    emit('vote', [...next])
    return
  }

  emit('vote', mine.value.has(optionId) ? [] : [optionId])
}

const typeLabel = computed(() => ({
  yes_no: 'Yes/No',
  single: 'Single choice',
  multiple: 'Multiple choice',
}[props.poll.type] ?? props.poll.type))

const isAuthor = computed(() => props.poll.creator?.id === user.value?.id)

function when(iso: string) {
  return new Date(iso).toLocaleString(undefined, {
    day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto">
    <div class="mx-auto max-w-3xl space-y-4 p-4">
      <!-- The question, and who asked it. -->
      <section class="space-y-3 rounded-xl border p-4">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-500">
            {{ typeLabel }}
          </span>
          <span
            class="rounded-full border px-2 py-0.5 text-[11px] font-medium"
            :class="poll.closed ? 'border-border text-muted-foreground' : 'border-emerald-500/40 text-emerald-500'"
          >{{ poll.closed ? 'Closed' : 'Active' }}</span>

          <span class="flex-1" />

          <!-- Closing is the author's or staff's call, and it's reversible: a poll reopened
               keeps every vote it already had. -->
          <button
            v-if="canEdit && isAuthor"
            type="button"
            class="flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors hover:bg-muted"
            @click="emit('patch', { closed: !poll.closed })"
          >
            <component :is="poll.closed ? Unlock : Lock" class="h-3.5 w-3.5" />
            {{ poll.closed ? 'Reopen' : 'Close' }}
          </button>
          <button
            v-if="canEdit && isAuthor"
            type="button"
            class="grid h-7 w-7 place-items-center rounded-md border text-red-500 transition-colors hover:bg-red-500/10"
            title="Delete this poll"
            @click="emit('remove')"
          >
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </div>

        <h2 class="text-2xl font-bold leading-tight">{{ poll.question }}</h2>
        <p v-if="poll.description" class="text-sm text-muted-foreground">{{ poll.description }}</p>

        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 border-t pt-3 text-sm">
          <dt class="text-muted-foreground">By:</dt>
          <dd>{{ poll.creator?.name ?? 'Someone' }}</dd>
          <dt class="text-muted-foreground">Created:</dt>
          <dd>{{ when(poll.created_at) }}</dd>
        </dl>
      </section>

      <!-- Reactions: a fixed row of five, always all shown, so the counts sit in stable
           positions instead of the row reflowing every time somebody picks a new one. -->
      <section class="flex flex-wrap items-center gap-2 rounded-xl border p-3">
        <button
          v-for="emoji in QUICK_REACTIONS"
          :key="emoji"
          type="button"
          class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm transition-colors"
          :class="(poll.reactions ?? []).find(r => r.emoji === emoji)?.reacted
            ? 'border-primary bg-primary/15'
            : 'hover:bg-muted'"
          @click="emit('react', emoji)"
        >
          {{ emoji }}
          <span v-if="(poll.reactions ?? []).find(r => r.emoji === emoji)?.count" class="text-xs text-muted-foreground">
            {{ (poll.reactions ?? []).find(r => r.emoji === emoji)!.count }}
          </span>
        </button>
      </section>

      <section class="space-y-3 rounded-xl border p-4">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold">Results</h3>
          <span class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
            {{ poll.type === 'multiple' ? `${poll.voter_count ?? 0} voters` : `${total} total votes` }}
          </span>
        </div>

        <button
          v-for="option in poll.options ?? []"
          :key="option.id"
          type="button"
          :disabled="poll.closed || !canEdit"
          class="w-full space-y-1.5 rounded-lg border p-3 text-left transition-colors disabled:cursor-default"
          :class="mine.has(option.id)
            ? 'border-primary bg-primary/10'
            : !poll.closed && canEdit && 'hover:bg-muted/50'"
          @click="pick(option.id)"
        >
          <div class="flex items-baseline justify-between gap-3">
            <span class="font-medium">{{ option.label }}</span>
            <span class="shrink-0 text-sm text-muted-foreground">
              {{ option.votes }} vote{{ option.votes === 1 ? '' : 's' }} ({{ share(option.votes) }}%)
            </span>
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-muted">
            <div
              class="h-full rounded-full bg-primary transition-all"
              :style="{ width: `${share(option.votes)}%` }"
            />
          </div>
        </button>

        <p v-if="poll.anonymous" class="text-[11px] text-muted-foreground">
          Answers are counted but never attributed.
        </p>
        <p v-else-if="poll.closed" class="text-[11px] text-muted-foreground">
          This poll is closed — the results stand.
        </p>
      </section>

      <!-- The thread, from the same component every other app uses. -->
      <section class="rounded-xl border p-4">
        <slot name="comments" />
      </section>
    </div>
  </div>
</template>
