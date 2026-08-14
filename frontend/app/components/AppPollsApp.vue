<script setup lang="ts">
import { ChevronLeft, Plus, X } from 'lucide-vue-next'
import type { AppPoll, AppPollType } from '~/types'
import { Input } from '~/components/ui/input'

/**
 * The Polls app — a channel's wall of polls, and one of them open.
 *
 * Two screens and one Back button, the same shape the Tracker uses and for the same reason: the
 * app can be a channel, a Side Desk tab or a floating window, and only one of those has a URL
 * to navigate.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
}>()

const { polls, loaded, open, loadOne, add, patch, vote, remove, react } = useAppPolls(
  props.basePath, props.streamName,
)

open()

const openId = ref<number | null>(null)
const current = computed(() => polls.value.find((p: AppPoll) => p.id === openId.value) ?? null)

const active = computed(() => polls.value.filter((p: AppPoll) => !p.closed))
const closed = computed(() => polls.value.filter((p: AppPoll) => p.closed))

// Opening a poll fetches its comment thread, which the wall listing leaves out.
watch(openId, (id) => {
  if (id != null) void loadOne(id)
})

// --- composing a poll -------------------------------------------------------------------

const composing = ref(false)
const draft = ref<{ type: AppPollType, question: string, description: string, anonymous: boolean, options: string[] }>({
  type: 'single',
  question: '',
  description: '',
  anonymous: false,
  // Two blanks up front: a poll needs at least two options, so starting with the minimum is
  // one less thing to discover.
  options: ['', ''],
})

function resetDraft() {
  draft.value = { type: 'single', question: '', description: '', anonymous: false, options: ['', ''] }
}

/** Yes/No writes its own options, so the editor hides them entirely for that type. */
const needsOptions = computed(() => draft.value.type !== 'yes_no')

const canSubmit = computed(() => {
  if (!draft.value.question.trim()) return false
  if (!needsOptions.value) return true
  return draft.value.options.filter(o => o.trim()).length >= 2
})

async function submit() {
  if (!canSubmit.value) return
  await add({
    type: draft.value.type,
    question: draft.value.question.trim(),
    description: draft.value.description.trim() || null,
    anonymous: draft.value.anonymous,
    ...(needsOptions.value ? { options: draft.value.options.map(o => o.trim()).filter(Boolean) } : {}),
  })
  resetDraft()
  composing.value = false
}

async function onRemove() {
  const id = openId.value
  if (id == null) return
  // eslint-disable-next-line no-alert
  if (!window.confirm('Delete this poll?\n\nIts votes, reactions and comments go with it. This cannot be undone.')) return
  openId.value = null
  await remove(id)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-3">
      <button
        v-if="openId !== null"
        type="button"
        class="flex items-center gap-1 rounded-md border px-2 py-1.5 text-sm transition-colors hover:bg-muted"
        @click="openId = null"
      >
        <ChevronLeft class="h-4 w-4" /> Back
      </button>
      <p class="truncate font-semibold">{{ current ? current.question : 'Polls' }}</p>
      <span class="flex-1" />
      <button
        v-if="canEdit && openId === null"
        type="button"
        class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-muted"
        @click="composing = !composing"
      >
        <Plus class="h-3.5 w-3.5" /> New poll
      </button>
    </header>

    <div v-if="!loaded" class="grid flex-1 place-items-center text-sm text-muted-foreground">
      Loading…
    </div>

    <AppPollDetail
      v-else-if="current"
      :poll="current"
      :can-edit="canEdit"
      @vote="vote(current!.id, $event)"
      @patch="patch(current!.id, $event)"
      @react="react(current!.id, $event)"
      @remove="onRemove"
    >
      <!-- The same thread component the Calendar uses. Nothing about it knows what a poll is —
           that's the payoff of the polymorphic tables. -->
      <template #comments>
        <AppItemDiscussion
          :base-path="basePath"
          subject="app_poll"
          :item-id="current!.id"
          :can-edit="canEdit"
          :show-tags="false"
        />
      </template>
    </AppPollDetail>

    <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
      <!-- The composer, inline above the wall rather than in a dialog: a poll is several fields
           and a variable list, which a modal would have to scroll anyway. -->
      <form v-if="composing" class="mb-6 space-y-3 rounded-xl border p-4" @submit.prevent="submit">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="t in (['yes_no', 'single', 'multiple'] as AppPollType[])"
            :key="t"
            type="button"
            class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
            :class="draft.type === t ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
            @click="draft.type = t"
          >
            {{ { yes_no: 'Yes/No', single: 'Single choice', multiple: 'Multiple choice' }[t] }}
          </button>
        </div>

        <Input v-model="draft.question" placeholder="Ask a question…" class="text-sm" />
        <Input v-model="draft.description" placeholder="Add context (optional)" class="text-sm" />

        <div v-if="needsOptions" class="space-y-2">
          <div v-for="(_, i) in draft.options" :key="i" class="flex items-center gap-2">
            <Input v-model="draft.options[i]" :placeholder="`Option ${i + 1}`" class="h-8 text-sm" />
            <button
              v-if="draft.options.length > 2"
              type="button"
              class="grid h-8 w-8 shrink-0 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted"
              title="Remove option"
              @click="draft.options.splice(i, 1)"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>
          <button
            v-if="draft.options.length < 20"
            type="button"
            class="text-xs text-muted-foreground transition-colors hover:text-foreground"
            @click="draft.options.push('')"
          >
            + Add option
          </button>
        </div>

        <label class="flex cursor-pointer items-center gap-2 text-sm">
          <input v-model="draft.anonymous" type="checkbox" class="h-3.5 w-3.5 rounded border-border">
          Count answers without attributing them
        </label>

        <div class="flex gap-2">
          <button
            type="submit"
            class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
            :disabled="!canSubmit"
          >
            Create poll
          </button>
          <button type="button" class="px-3 py-1.5 text-sm text-muted-foreground" @click="composing = false">
            Cancel
          </button>
        </div>
      </form>

      <section v-if="active.length" class="space-y-3">
        <h2 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Active polls</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <AppPollCard v-for="p in active" :key="p.id" :poll="p" @open="openId = $event.id" />
        </div>
      </section>

      <!-- Closed polls stay, under their own heading. A poll's answer is often the reason it
           was asked, so hiding it once voting ends would throw away the result. -->
      <section v-if="closed.length" class="mt-8 space-y-3">
        <h2 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Closed</h2>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <AppPollCard v-for="p in closed" :key="p.id" :poll="p" @open="openId = $event.id" />
        </div>
      </section>

      <p v-if="!polls.length && !composing" class="rounded-xl border border-dashed px-3 py-10 text-center text-sm text-muted-foreground">
        No polls yet.
      </p>
    </div>
  </div>
</template>
