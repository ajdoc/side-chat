<script setup lang="ts">
import { BarChart3, Loader2 } from 'lucide-vue-next'
import type { AppPoll, Widget } from '~/types'

/**
 * The poll card — in the timeline, and on the Open Canvas.
 *
 * ## One poll, three places
 *
 * This used to render the poll out of the widget's own JSON state, which meant a `p!` poll and
 * a poll on the channel's Polls wall were different objects that couldn't see each other. They
 * are the same object now: the widget's state is `{poll_id}` and this fetches that
 * {@link AppPoll}. Voting here moves the wall; voting on the wall moves this. See PollWidget.php.
 *
 * ## Why it fetches rather than being handed the poll
 *
 * The card is rendered from a timeline message and from a canvas item, neither of which carries
 * poll data — and a widget's broadcast only says "this widget changed". So the card listens for
 * that and re-reads. One request per card, and only for cards actually on screen.
 */
const props = defineProps<{ widget: Widget }>()

const api = useApi()
const { user } = useAuth()

const poll = ref<AppPoll | null>(null)
const loading = ref(true)

const basePath = computed(() => `/api/channels/${props.widget.channel_id}`)
const pollId = computed<number | null>(() => (props.widget.state as any)?.poll_id ?? null)

async function load() {
  const id = pollId.value
  if (id == null) {
    poll.value = null
    loading.value = false
    return
  }
  try {
    const res = await api<{ data: AppPoll }>(`${basePath.value}/polls/${id}`)
    poll.value = res.data
  }
  catch {
    // The poll was deleted from the wall. The card says so rather than resurrecting it — the
    // deletion was deliberate.
    poll.value = null
  }
  finally {
    loading.value = false
  }
}

// The widget row changes on every `p!` command, and its id changes when `p!new` starts a fresh
// poll. Watching the state object covers both.
watch(() => props.widget.state, () => void load(), { immediate: true, deep: true })

const options = computed(() => poll.value?.options ?? [])
const total = computed(() => options.value.reduce((n, o) => n + o.votes, 0))
const mine = computed(() => new Set(poll.value?.my_option_ids ?? []))

/** The winning tally, so the leading option can be marked once anyone has voted. */
const topCount = computed(() => options.value.reduce((max, o) => Math.max(max, o.votes), 0))

function pct(votes: number) {
  return total.value === 0 ? 0 : Math.round((votes / total.value) * 100)
}

/**
 * Vote by writing the whole set you now stand behind — the same contract the wall uses, so the
 * two can't drift. Clicking your own pick withdraws it.
 */
async function vote(optionId: number) {
  if (!poll.value || poll.value.closed) return

  const next = new Set(mine.value)
  if (poll.value.type === 'multiple') {
    next.has(optionId) ? next.delete(optionId) : next.add(optionId)
  }
  else {
    next.clear()
    if (!mine.value.has(optionId)) next.add(optionId)
  }

  const res = await api<{ data: AppPoll }>(`${basePath.value}/polls/${poll.value.id}/vote`, {
    method: 'PUT',
    body: { option_ids: [...next] },
  })
  poll.value = res.data
}
</script>

<template>
  <div class="space-y-2 rounded-lg border p-3">
    <div v-if="loading" class="flex items-center gap-2 text-xs text-muted-foreground">
      <Loader2 class="h-3.5 w-3.5 animate-spin" /> Loading poll…
    </div>

    <p v-else-if="!poll" class="text-xs text-muted-foreground">
      This poll has been deleted.
    </p>

    <template v-else>
      <div class="flex items-start gap-2">
        <BarChart3 class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <p class="min-w-0 flex-1 text-sm font-semibold leading-snug">{{ poll.question }}</p>
        <span
          v-if="poll.closed"
          class="shrink-0 rounded-full border px-1.5 py-0.5 text-[10px] text-muted-foreground"
        >Closed</span>
      </div>

      <p v-if="!options.length" class="text-xs text-muted-foreground">
        No options yet — add one with <code>p!add &lt;text&gt;</code>.
      </p>

      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        :disabled="poll.closed"
        class="w-full space-y-1 rounded border p-2 text-left transition-colors disabled:cursor-default"
        :class="mine.has(option.id) ? 'border-primary bg-primary/10' : !poll.closed && 'hover:bg-muted/50'"
        @click="vote(option.id)"
      >
        <div class="flex items-baseline justify-between gap-2 text-xs">
          <span class="min-w-0 truncate" :class="option.votes === topCount && topCount > 0 && 'font-semibold'">
            {{ option.label }}
          </span>
          <span class="shrink-0 text-muted-foreground">{{ option.votes }} · {{ pct(option.votes) }}%</span>
        </div>
        <div class="h-1.5 overflow-hidden rounded-full bg-muted">
          <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${pct(option.votes)}%` }" />
        </div>
      </button>

      <p class="text-[11px] text-muted-foreground">
        {{ poll.type === 'multiple' ? `${poll.voter_count ?? 0} voters` : `${total} votes` }}
        · also on the Polls app
      </p>
    </template>
  </div>
</template>
