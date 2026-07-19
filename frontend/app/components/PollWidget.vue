<script setup lang="ts">
import { BarChart3, Check, Lock, LockOpen, Pencil, Plus, X } from 'lucide-vue-next'
import type { PollOption, PollState, Widget } from '~/types'

/**
 * The shared poll card — one question, a live tally everyone sees.
 *
 * Like the board, every mutation (vote, add, close) goes through a widget action, lands in
 * the server's state and comes back as `WidgetUpdated`; the card never edits its own copy
 * locally, so two people voting at once can't stomp each other. A vote records *who* cast
 * it, so we can highlight your own pick and toggle it off, and single-choice mode replaces
 * your previous pick server-side. Options keep a stable number (`#id`) so a `p!vote 3` typed
 * in chat and a tap here hit the same option.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()
const { user } = useAuth()

// `v-focus`: drop the cursor straight into an option when it flips to edit mode.
const vFocus = { mounted: (el: HTMLInputElement) => el.focus() }

const state = computed(() => props.widget.state as PollState)
const options = computed(() => state.value.options ?? [])
const totalVotes = computed(() => options.value.reduce((n, o) => n + (o.voters?.length ?? 0), 0))

/** The winning tally, so leading option(s) can be marked once anyone's voted. */
const topCount = computed(() => options.value.reduce((max, o) => Math.max(max, o.voters?.length ?? 0), 0))

function votedByMe(option: PollOption): boolean {
  return user.value != null && (option.voters ?? []).some(v => v.id === user.value!.id)
}

function pct(option: PollOption): number {
  if (totalVotes.value === 0) return 0
  return Math.round(((option.voters?.length ?? 0) / totalVotes.value) * 100)
}

function vote(option: PollOption) {
  if (state.value.closed) return
  action(props.widget.id, 'vote', { id: option.id })
}

// --- add ---
const draft = ref('')
async function add() {
  const text = draft.value.trim()
  if (!text) return
  draft.value = ''
  await action(props.widget.id, 'add', { text })
}

// --- inline edit ---
const editingId = ref<number | null>(null)
const editText = ref('')
function beginEdit(option: PollOption) {
  editingId.value = option.id
  editText.value = option.text
}
async function commitEdit(option: PollOption) {
  const text = editText.value.trim()
  editingId.value = null
  if (text && text !== option.text) await action(props.widget.id, 'edit', { id: option.id, text })
}

const remove = (option: PollOption) => action(props.widget.id, 'remove', { id: option.id })
const toggleClose = () => action(props.widget.id, state.value.closed ? 'open' : 'close')
</script>

<template>
  <div class="mt-1.5 w-full max-w-md rounded-lg border bg-muted/30 p-3">
    <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <BarChart3 class="h-3.5 w-3.5" /> Poll
      <span v-if="state.multi" class="rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case">multi</span>
      <button
        class="ml-auto flex items-center gap-1 rounded px-1 py-px text-[10px] font-medium normal-case text-muted-foreground hover:bg-muted hover:text-foreground"
        :title="state.closed ? 'Reopen voting' : 'Close voting'"
        @click="toggleClose"
      >
        <component :is="state.closed ? Lock : LockOpen" class="h-3 w-3" />
        {{ state.closed ? 'Closed' : 'Open' }}
      </button>
    </div>

    <p class="mt-2 break-words text-sm font-semibold">
      {{ state.question || 'Untitled poll' }}
    </p>

    <ul class="mt-2 space-y-1.5">
      <li
        v-for="option in options"
        :key="option.id"
        class="group relative overflow-hidden rounded-md border bg-card"
      >
        <!-- The tally bar fills behind the label, so the result reads at a glance. -->
        <div
          class="absolute inset-y-0 left-0 transition-all"
          :class="votedByMe(option) ? 'bg-primary/20' : 'bg-primary/5'"
          :style="{ width: `${pct(option)}%` }"
        />

        <div class="relative flex items-center gap-2 px-2 py-1.5 text-xs">
          <button
            class="flex h-4 w-4 flex-none items-center justify-center rounded-full border"
            :class="[
              votedByMe(option) ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40',
              state.closed ? 'cursor-not-allowed opacity-60' : 'hover:border-primary',
              state.multi ? 'rounded-sm' : 'rounded-full',
            ]"
            :disabled="state.closed"
            :title="state.closed ? 'Voting is closed' : 'Vote'"
            @click="vote(option)"
          >
            <Check v-if="votedByMe(option)" class="h-3 w-3" />
          </button>

          <input
            v-if="editingId === option.id"
            v-model="editText"
            class="min-w-0 flex-1 rounded border bg-background px-1 py-0.5 text-xs"
            @keyup.enter="commitEdit(option)"
            @keyup.esc="editingId = null"
            @blur="commitEdit(option)"
            v-focus
          >
          <span
            v-else
            class="min-w-0 flex-1 cursor-pointer break-words"
            @click="vote(option)"
            @dblclick.stop="beginEdit(option)"
          >{{ option.text }}</span>

          <span class="flex-none tabular-nums text-[10px] font-medium text-muted-foreground">
            <span v-if="option.voters?.length && option.voters.length === topCount && topCount > 0" class="mr-0.5">🏆</span>
            {{ option.voters?.length ?? 0 }} · {{ pct(option) }}%
          </span>

          <button
            v-if="editingId !== option.id"
            class="flex-none text-muted-foreground opacity-0 focus:opacity-100 group-hover:opacity-100 hover:text-foreground"
            title="Edit option"
            @click.stop="beginEdit(option)"
          >
            <Pencil class="h-3.5 w-3.5" />
          </button>

          <button
            class="flex-none text-muted-foreground opacity-0 focus:opacity-100 group-hover:opacity-100 hover:text-destructive"
            title="Remove option"
            @click.stop="remove(option)"
          >
            <X class="h-3.5 w-3.5" />
          </button>
        </div>
      </li>
    </ul>

    <p v-if="!options.length" class="mt-2 text-xs text-muted-foreground">
      No options yet — add one below or with <code class="rounded bg-muted px-1">p!add</code>.
    </p>

    <!-- Add an option (allowed while open, so a poll can grow mid-vote). -->
    <div v-if="!state.closed" class="mt-2 flex items-center gap-1">
      <input
        v-model="draft"
        placeholder="Add an option…"
        class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs placeholder:text-muted-foreground"
        @keyup.enter="add"
      >
      <button
        class="flex-none rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Add option"
        @click="add"
      >
        <Plus class="h-4 w-4" />
      </button>
    </div>

    <p class="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
      <span>{{ totalVotes }} {{ totalVotes === 1 ? 'vote' : 'votes' }}</span>
      <span>Tap to vote · double-click to edit · <code class="rounded bg-muted px-1">p!help</code></span>
    </p>
  </div>
</template>
