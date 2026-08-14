<script setup lang="ts">
import type { TrackerTask } from '~/types'
import { formatDueDate, isOverdue, priorityMeta, statusMeta } from '~/lib/tracker'

/**
 * One task, as a row.
 *
 * A row rather than a card, and that's the whole layout decision: the tracker's board groups by
 * status down the page instead of across it in columns. Columns are the right shape when you
 * drag between them all day and the wrong one here, where the common act is opening a task to
 * read it and where a phone would get five columns of one-word wrapping.
 *
 * Everything on the row comes from the task the listing already returned, so opening one is the
 * only request the board ever makes.
 */
const props = defineProps<{
  task: TrackerTask
  /** Hidden on a board already grouped by status; shown in a flat list like "Your tasks". */
  showStatus?: boolean
}>()

const emit = defineEmits<{ open: [TrackerTask] }>()

const status = computed(() => statusMeta(props.task.status))
const priority = computed(() => priorityMeta(props.task.priority))
const overdue = computed(() => isOverdue(props.task.due_date) && props.task.status !== 'done')
</script>

<template>
  <button
    type="button"
    class="group flex w-full items-center gap-2 rounded-md px-2 py-2 text-left transition-colors hover:bg-muted/60 sm:gap-3 sm:px-3"
    @click="emit('open', task)"
  >
    <!-- Priority first: it's the field you scan a list by, and a fixed-width pill keeps the
         keys beside it aligned into a readable column. -->
    <span
      class="hidden w-12 shrink-0 rounded px-1.5 py-0.5 text-center text-[10px] font-semibold uppercase leading-none sm:inline-block"
      :class="priority.chip"
    >{{ priority.label }}</span>

    <span class="w-16 shrink-0 truncate font-mono text-[11px] text-muted-foreground">{{ task.key }}</span>

    <component :is="status.icon" v-if="showStatus" class="h-3.5 w-3.5 shrink-0" :class="status.text" />

    <span
      class="min-w-0 flex-1 truncate text-sm"
      :class="task.status === 'done' ? 'text-muted-foreground line-through' : 'text-foreground'"
    >{{ task.title }}</span>

    <TrackerTagChips v-if="task.tags?.length" :tags="task.tags" class="hidden shrink-0 md:flex" />

    <!-- Overdue is the one date worth colouring; everything else is quiet metadata. A finished
         task is never overdue, however late it was — see `overdue`. -->
    <span
      v-if="task.due_date"
      class="hidden shrink-0 text-[11px] sm:inline"
      :class="overdue ? 'font-medium text-red-500' : 'text-muted-foreground'"
    >{{ formatDueDate(task.due_date) }}</span>

    <TrackerAvatar :user="task.assignee" size="sm" />
  </button>
</template>
