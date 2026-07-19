<script setup lang="ts">
import { ClipboardList, Pencil, Plus, X } from 'lucide-vue-next'
import type { KanbanCard, KanbanState, Widget } from '~/types'

/**
 * The shared kanban board card — three columns driven by the same state everyone sees.
 *
 * Every mutation (add, drag between columns, edit, delete) goes through a widget action,
 * lands in the server's state and comes back as `WidgetUpdated`; the board never edits its
 * own copy locally. That's what keeps two people dragging cards from stomping each other.
 * Cards keep a stable number (`#id`) so a `k!done 3` typed in chat and a drag here refer to
 * the same card.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()

// `v-focus`: drop the cursor straight into a card when it flips to edit mode.
const vFocus = { mounted: (el: HTMLInputElement) => el.focus() }

const state = computed(() => props.widget.state as KanbanState)

const COLUMNS = [
  { key: 'todo', label: 'To Do' },
  { key: 'doing', label: 'Doing' },
  { key: 'done', label: 'Done' },
] as const

function cardsIn(column: string): KanbanCard[] {
  return (state.value.cards ?? []).filter(c => c.column === column)
}

// --- add ---
const draft = ref('')
async function add() {
  const text = draft.value.trim()
  if (!text) return
  draft.value = ''
  await action(props.widget.id, 'add', { text })
}

// --- drag between columns ---
const dragId = ref<number | null>(null)
function onDrop(column: string) {
  if (dragId.value != null) action(props.widget.id, 'move', { id: dragId.value, column })
  dragId.value = null
}

// --- inline edit ---
const editingId = ref<number | null>(null)
const editText = ref('')
function beginEdit(card: KanbanCard) {
  editingId.value = card.id
  editText.value = card.text
}
async function commitEdit(card: KanbanCard) {
  const text = editText.value.trim()
  editingId.value = null
  if (text && text !== card.text) await action(props.widget.id, 'edit', { id: card.id, text })
}

const remove = (card: KanbanCard) => action(props.widget.id, 'remove', { id: card.id })
</script>

<template>
  <div class="mt-1.5 w-full max-w-2xl rounded-lg border bg-muted/30 p-3">
    <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <ClipboardList class="h-3.5 w-3.5" /> Board
      <span class="ml-auto normal-case text-muted-foreground">{{ (state.cards ?? []).length }} cards</span>
    </div>

    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
      <div
        v-for="col in COLUMNS"
        :key="col.key"
        class="rounded-md bg-background/60 p-2"
        @dragover.prevent
        @drop="onDrop(col.key)"
      >
        <p class="mb-1.5 flex items-center justify-between px-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          {{ col.label }}
          <span>{{ cardsIn(col.key).length }}</span>
        </p>

        <ul class="space-y-1.5">
          <li
            v-for="card in cardsIn(col.key)"
            :key="card.id"
            draggable="true"
            class="group cursor-grab rounded border bg-card p-2 text-xs shadow-sm active:cursor-grabbing"
            @dragstart="dragId = card.id"
            @dblclick="beginEdit(card)"
          >
            <div class="flex items-start gap-1.5">
              <span class="mt-px flex-none text-[10px] font-medium text-muted-foreground">#{{ card.id }}</span>

              <input
                v-if="editingId === card.id"
                v-model="editText"
                class="min-w-0 flex-1 rounded border bg-background px-1 py-0.5 text-xs"
                @keyup.enter="commitEdit(card)"
                @keyup.esc="editingId = null"
                @blur="commitEdit(card)"
                v-focus
              >
              <span
                v-else
                class="min-w-0 flex-1 break-words"
                :class="card.column === 'done' && 'text-muted-foreground line-through'"
              >{{ card.text }}</span>

              <button
                v-if="editingId !== card.id"
                class="flex-none text-muted-foreground opacity-0 focus:opacity-100 group-hover:opacity-100 hover:text-foreground"
                title="Edit card"
                @click.stop="beginEdit(card)"
              >
                <Pencil class="h-3.5 w-3.5" />
              </button>

              <button
                class="flex-none text-muted-foreground opacity-0 focus:opacity-100 group-hover:opacity-100 hover:text-destructive"
                title="Delete card"
                @click.stop="remove(card)"
              >
                <X class="h-3.5 w-3.5" />
              </button>
            </div>

            <div class="mt-1 flex items-center gap-2 pl-4 text-[10px] text-muted-foreground">
              <span
                v-if="card.assignee"
                class="rounded-full bg-primary/10 px-1.5 py-px font-medium text-primary"
              >@{{ card.assignee.name }}</span>
              <span class="truncate">{{ card.addedBy }}</span>
            </div>
          </li>
        </ul>

        <!-- Quick-add lives under To Do; other columns fill by dragging. -->
        <div v-if="col.key === 'todo'" class="mt-1.5 flex items-center gap-1">
          <input
            v-model="draft"
            placeholder="Add a card…"
            class="min-w-0 flex-1 rounded border bg-background px-2 py-1 text-xs placeholder:text-muted-foreground"
            @keyup.enter="add"
          >
          <button
            class="flex-none rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
            title="Add"
            @click="add"
          >
            <Plus class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>

    <p class="mt-2 text-[10px] text-muted-foreground">
      Drag between columns · double-click to edit · or use <code class="rounded bg-muted px-1">k!add</code>, <code class="rounded bg-muted px-1">k!done &lt;n&gt;</code>
    </p>
  </div>
</template>
