<script setup lang="ts">
import {
  ChevronLeft, ChevronRight, ClipboardList, Eraser, MessageSquare, MoreVertical,
  Pencil, Plus, Smile, Trash2, X,
} from 'lucide-vue-next'
import type { KanbanCard } from '~/types'

/**
 * The shared kanban board — in the timeline, on the Open Canvas, as a Side Desk tab and as a
 * whole app channel.
 *
 * ## What changed
 *
 * It used to render the widget's JSON state and push every change through a widget action. The
 * board is rows now (see the kanban tables migration), so this talks to the board's own
 * endpoints through {@link useKanban}. Three things follow that the blob couldn't have:
 *
 * - **Columns are yours.** Add, rename, reorder, empty, remove. Removing one rehomes its cards
 *   rather than deleting them, which is the difference between tidying and losing work.
 * - **A card has a discussion.** Comments, tags and reactions, in the same panel every other app
 *   uses — because a card is now a row for `commentable_id` to point at.
 * - **Multi-line cards.** Enter commits, Shift+Enter starts a new line, in both the quick-add
 *   and the inline editor. A card that has to be one line is a card people write worse.
 *
 * ## Two shapes, one component
 *
 * `variant: 'card'` is the timeline/canvas placement — narrow, columns scroll sideways. `'full'`
 * is the tab and the app channel, which get the window. They differ only in chrome: the same
 * board, the same state, the same store, so the two can never disagree about what's on it.
 */
const props = withDefaults(defineProps<{
  /** The channel that owns the board. A side chat passes its parent's id, as widgets always have. */
  channelId: number
  variant?: 'card' | 'full'
  canEdit?: boolean
}>(), { variant: 'card', canEdit: true })

const basePath = computed(() => `/api/channels/${props.channelId}`)

const {
  board, columns, loading, error, open, cardsIn,
  addCard, patchCard, removeCard, moveCard,
  addColumn, renameColumn, moveColumn, removeColumn, clearColumn,
} = useKanban(basePath.value, `channel.${props.channelId}`)

open()

/** `v-focus`: drop the cursor straight into whatever just flipped to an editor. */
const vFocus = {
  mounted: (el: HTMLTextAreaElement | HTMLInputElement) => {
    el.focus()
    if ('setSelectionRange' in el) el.setSelectionRange(el.value.length, el.value.length)
  },
}

// --- adding ---------------------------------------------------------------------------------

/** Which column's quick-add is open, and what's in it. One at a time; there's one cursor. */
const addingIn = ref<string | null>(null)
const draft = ref('')

function beginAdd(column: string) {
  addingIn.value = column
  draft.value = ''
}

/**
 * Commit the quick-add.
 *
 * Stays open afterwards, because adding cards is something people do in runs of five — and the
 * draft is cleared, so a stray second Enter does nothing rather than duplicating the card.
 */
async function commitAdd(column: string) {
  const text = draft.value.trim()
  draft.value = ''
  if (!text) {
    addingIn.value = null
    return
  }
  await addCard(text, column)
}

// --- editing --------------------------------------------------------------------------------

const editingId = ref<number | null>(null)
const editText = ref('')

function beginEdit(card: KanbanCard) {
  if (!props.canEdit) return
  editingId.value = card.id
  editText.value = card.text
}

async function commitEdit(card: KanbanCard) {
  const text = editText.value.trim()
  editingId.value = null
  if (text && text !== card.text) await patchCard(card.id, { text })
}

// --- dragging -------------------------------------------------------------------------------

const dragId = ref<number | null>(null)
/** The card being hovered over, so a drop lands *above* it rather than at the end. */
const dropBefore = ref<number | null>(null)

function onDrop(column: string) {
  const id = dragId.value
  const before = dropBefore.value
  dragId.value = null
  dropBefore.value = null

  if (id != null && id !== before) void moveCard(id, column, before)
}

// --- the card panel -------------------------------------------------------------------------

/**
 * The open card, if any.
 *
 * Derived from the board rather than held as a copy, so a comment count or an edit arriving over
 * the socket updates the panel that's open on it.
 */
const openId = ref<number | null>(null)
const openCard = computed(() => board.value?.cards.find(c => c.id === openId.value) ?? null)

watch(openCard, card => {
  // The card was deleted by somebody else while its panel was open.
  if (openId.value !== null && !card) openId.value = null
})

// --- columns --------------------------------------------------------------------------------

const menuFor = ref<string | null>(null)
const renamingKey = ref<string | null>(null)
const renameDraft = ref('')
const addingColumn = ref(false)
const columnDraft = ref('')

function beginRename(key: string, label: string) {
  menuFor.value = null
  renamingKey.value = key
  renameDraft.value = label
}

async function commitRename(key: string) {
  const label = renameDraft.value.trim()
  renamingKey.value = null
  if (label) await renameColumn(key, label)
}

async function commitColumn() {
  const label = columnDraft.value.trim()
  columnDraft.value = ''
  addingColumn.value = false
  if (label) await addColumn(label)
}

/** The two destructive column actions, both behind a confirm — see ConfirmDialog. */
const confirming = ref<{ kind: 'remove' | 'clear', key: string, label: string } | null>(null)
const confirmBusy = ref(false)
const confirmError = ref('')

function askRemove(key: string, label: string) {
  menuFor.value = null
  confirming.value = { kind: 'remove', key, label }
}

function askClear(key: string, label: string) {
  menuFor.value = null
  confirming.value = { kind: 'clear', key, label }
}

async function runConfirm() {
  const ask = confirming.value
  if (!ask) return

  confirmBusy.value = true
  confirmError.value = ''
  try {
    await (ask.kind === 'remove' ? removeColumn(ask.key) : clearColumn(ask.key))
    confirming.value = null
  }
  catch (e: any) {
    confirmError.value = e?.data?.message ?? 'That didn’t work.'
  }
  finally {
    confirmBusy.value = false
  }
}

const total = computed(() => board.value?.cards.length ?? 0)
</script>

<template>
  <ConfirmDialog
    :open="confirming !== null"
    :title="confirming?.kind === 'remove' ? `Remove “${confirming?.label}”?` : `Empty “${confirming?.label}”?`"
    :description="confirming?.kind === 'remove'
      ? 'The column goes; its cards move to the column beside it. Nothing is deleted.'
      : 'Every card in this column is deleted, with its comments and reactions. This cannot be undone.'"
    :confirm-label="confirming?.kind === 'remove' ? 'Remove column' : 'Delete the cards'"
    :variant="confirming?.kind === 'remove' ? 'default' : 'destructive'"
    busy-label="Working…"
    :busy="confirmBusy"
    :error="confirmError"
    @update:open="(v: boolean) => { if (!v) confirming = null }"
    @confirm="runConfirm"
  />

  <div
    class="flex min-h-0 flex-col"
    :class="variant === 'full'
      ? 'flex-1'
      : 'mt-1.5 w-full max-w-3xl rounded-lg border bg-muted/30 p-3'"
  >
    <header
      class="flex shrink-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-primary"
      :class="variant === 'full' && 'h-12 border-b px-3'"
    >
      <ClipboardList class="h-3.5 w-3.5" /> Board
      <span class="ml-auto normal-case text-muted-foreground">{{ total }} cards</span>
    </header>

    <p v-if="error" class="px-3 py-6 text-center text-sm text-muted-foreground">{{ error }}</p>
    <p v-else-if="loading" class="px-3 py-6 text-center text-sm text-muted-foreground">Loading…</p>

    <div v-else class="flex min-h-0 flex-1">
      <!--
        Columns scroll sideways rather than wrapping. A board is read across, and a column that
        wrapped onto a second row would sit under the one it's meant to be beside.
      -->
      <div
        class="flex min-h-0 flex-1 gap-2 overflow-x-auto"
        :class="variant === 'full' ? 'p-3' : 'mt-2'"
      >
        <section
          v-for="(col, i) in columns"
          :key="col.key"
          class="flex w-56 shrink-0 flex-col rounded-md bg-background/60 p-2"
          :class="variant === 'full' && 'w-64'"
          @dragover.prevent
          @drop="onDrop(col.key)"
        >
          <div class="mb-1.5 flex items-center gap-1 px-0.5">
            <input
              v-if="renamingKey === col.key"
              v-model="renameDraft"
              v-focus
              class="min-w-0 flex-1 rounded border bg-background px-1 py-0.5 text-xs"
              @keyup.enter="commitRename(col.key)"
              @keyup.esc="renamingKey = null"
              @blur="commitRename(col.key)"
            >
            <p
              v-else
              class="min-w-0 flex-1 truncate text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
              :title="col.label"
            >
              {{ col.label }}
            </p>

            <span class="text-[10px] text-muted-foreground">{{ cardsIn(col.key).length }}</span>

            <!-- The column menu. Everything that reshapes the board lives here rather than as
                 four icons per column, which would be twelve controls on a three-column board. -->
            <div v-if="canEdit" class="relative">
              <button
                type="button"
                class="grid h-5 w-5 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                title="Column options"
                @click="menuFor = menuFor === col.key ? null : col.key"
              >
                <MoreVertical class="h-3.5 w-3.5" />
              </button>

              <div
                v-if="menuFor === col.key"
                class="absolute right-0 z-20 mt-1 w-40 rounded-md border bg-popover p-1 text-xs shadow-md"
              >
                <button class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-muted" @click="beginRename(col.key, col.label)">
                  <Pencil class="h-3.5 w-3.5" /> Rename
                </button>
                <button
                  v-if="i > 0"
                  class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-muted"
                  @click="menuFor = null; moveColumn(col.key, i - 1)"
                >
                  <ChevronLeft class="h-3.5 w-3.5" /> Move left
                </button>
                <button
                  v-if="i < columns.length - 1"
                  class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-muted"
                  @click="menuFor = null; moveColumn(col.key, i + 1)"
                >
                  <ChevronRight class="h-3.5 w-3.5" /> Move right
                </button>
                <button class="flex w-full items-center gap-2 rounded px-2 py-1.5 hover:bg-muted" @click="askClear(col.key, col.label)">
                  <Eraser class="h-3.5 w-3.5" /> Empty column
                </button>
                <button
                  v-if="columns.length > 1"
                  class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-destructive hover:bg-muted"
                  @click="askRemove(col.key, col.label)"
                >
                  <Trash2 class="h-3.5 w-3.5" /> Remove column
                </button>
              </div>
            </div>
          </div>

          <ul class="min-h-6 flex-1 space-y-1.5 overflow-y-auto">
            <li
              v-for="card in cardsIn(col.key)"
              :key="card.id"
              :draggable="canEdit && editingId !== card.id"
              class="group rounded border bg-card p-2 text-xs shadow-sm"
              :class="[canEdit && 'cursor-grab active:cursor-grabbing', dropBefore === card.id && 'ring-1 ring-primary']"
              @dragstart="dragId = card.id"
              @dragover.prevent="dropBefore = card.id"
              @dragleave="dropBefore === card.id && (dropBefore = null)"
              @dblclick="beginEdit(card)"
            >
              <div class="flex items-start gap-1.5">
                <span class="mt-px flex-none text-[10px] font-medium text-muted-foreground">#{{ card.id }}</span>

                <!-- Enter commits, Shift+Enter is a newline. `.exact` is what keeps the two
                     apart — without it the plain-Enter handler fires for the shifted one too. -->
                <textarea
                  v-if="editingId === card.id"
                  v-model="editText"
                  v-focus
                  rows="2"
                  class="min-w-0 flex-1 resize-y rounded border bg-background px-1 py-0.5 text-xs"
                  @keydown.enter.exact.prevent="commitEdit(card)"
                  @keyup.esc="editingId = null"
                  @blur="commitEdit(card)"
                />
                <span
                  v-else
                  class="min-w-0 flex-1 whitespace-pre-wrap break-words"
                >{{ card.text }}</span>

                <button
                  v-if="editingId !== card.id"
                  class="flex-none text-muted-foreground opacity-0 reveal-touch focus:opacity-100 group-hover:opacity-100 hover:text-foreground"
                  title="Open card"
                  @click.stop="openId = card.id"
                >
                  <MessageSquare class="h-3.5 w-3.5" />
                </button>
                <button
                  v-if="canEdit && editingId !== card.id"
                  class="flex-none text-muted-foreground opacity-0 reveal-touch focus:opacity-100 group-hover:opacity-100 hover:text-destructive"
                  title="Delete card"
                  @click.stop="removeCard(card.id)"
                >
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>

              <div class="mt-1 flex flex-wrap items-center gap-1.5 pl-4 text-[10px] text-muted-foreground">
                <span
                  v-if="card.assignee"
                  class="rounded-full bg-primary/10 px-1.5 py-px font-medium text-primary"
                >@{{ card.assignee.name }}</span>
                <span
                  v-for="tag in card.tags ?? []"
                  :key="tag.id"
                  class="rounded-full bg-muted px-1.5 py-px"
                >{{ tag.label }}</span>
                <!-- Counts, not the things themselves: what a board shows is *that* a card has
                     a thread, and the panel is where you read it. -->
                <span v-if="card.comment_count" class="flex items-center gap-0.5">
                  <MessageSquare class="h-2.5 w-2.5" />{{ card.comment_count }}
                </span>
                <span v-if="card.reaction_count" class="flex items-center gap-0.5">
                  <Smile class="h-2.5 w-2.5" />{{ card.reaction_count }}
                </span>
                <span v-if="card.added_by" class="ml-auto truncate">{{ card.added_by }}</span>
              </div>
            </li>
          </ul>

          <div v-if="canEdit" class="mt-1.5">
            <textarea
              v-if="addingIn === col.key"
              v-model="draft"
              v-focus
              rows="2"
              placeholder="Card text — Enter to add, Shift+Enter for a new line"
              class="w-full resize-y rounded border bg-background px-2 py-1 text-xs placeholder:text-muted-foreground"
              @keydown.enter.exact.prevent="commitAdd(col.key)"
              @keyup.esc="addingIn = null"
            />
            <button
              v-else
              class="flex w-full items-center gap-1 rounded px-1 py-1 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground"
              @click="beginAdd(col.key)"
            >
              <Plus class="h-3.5 w-3.5" /> Add a card
            </button>
          </div>
        </section>

        <!-- Adding a column sits where the next column would be, which is where you look for it. -->
        <section v-if="canEdit" class="w-44 shrink-0">
          <input
            v-if="addingColumn"
            v-model="columnDraft"
            v-focus
            placeholder="Column name"
            class="w-full rounded-md border bg-background px-2 py-1.5 text-xs"
            @keyup.enter="commitColumn"
            @keyup.esc="addingColumn = false"
            @blur="commitColumn"
          >
          <button
            v-else
            class="flex w-full items-center gap-1 rounded-md border border-dashed px-2 py-1.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground"
            @click="addingColumn = true"
          >
            <Plus class="h-3.5 w-3.5" /> Add a column
          </button>
        </section>
      </div>

      <!--
        The card's discussion, in a panel beside the board rather than inside the card — the same
        choice the canvas and the sticker wall made, and for the same reason: a thread inside a
        200px card is a scrollbar in a postage stamp.
      -->
      <aside
        v-if="openCard"
        class="flex w-72 shrink-0 flex-col border-l bg-background"
        :class="variant === 'card' && 'hidden sm:flex'"
      >
        <header class="flex h-10 shrink-0 items-center gap-2 border-b px-2">
          <span class="text-[10px] font-medium text-muted-foreground">#{{ openCard.id }}</span>
          <p class="min-w-0 flex-1 truncate text-xs font-medium">{{ openCard.text }}</p>
          <button
            class="grid h-6 w-6 shrink-0 place-items-center rounded text-muted-foreground hover:bg-muted"
            title="Close"
            @click="openId = null"
          >
            <X class="h-4 w-4" />
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto p-2">
          <!-- Knows nothing about kanban. That's what the polymorphic tables bought, and what
               a card in a JSON blob could never have joined. -->
          <AppItemDiscussion
            :key="openCard.id"
            :base-path="basePath"
            subject="kanban_card"
            :item-id="openCard.id"
            :can-edit="canEdit"
          />
        </div>
      </aside>
    </div>

    <p v-if="variant === 'card' && !loading" class="mt-2 shrink-0 text-[10px] text-muted-foreground">
      Drag between columns · double-click to edit · or use
      <code class="rounded bg-muted px-1">k!add</code>,
      <code class="rounded bg-muted px-1">k!done &lt;n&gt;</code>,
      <code class="rounded bg-muted px-1">k!col add</code>
    </p>
  </div>
</template>
