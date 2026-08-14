<script setup lang="ts">
import { Gamepad2, GripVertical, ListChecks, MessageSquare, StickyNote, Trash2, X } from 'lucide-vue-next'
import type { CanvasItem, CanvasItemKind } from '~/types'
import type { DeskApp } from '~/composables/useDeskApps'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * The Open Canvas app — a scrollable 2D board of freely-placed cards: a markdown note, a
 * checklist, or one of the interactive widgets we already have rendered live. The toolbar
 * splits those two ways: the tools (music, video, kanban, poll) sit in the row as icons, and
 * the games (Galaga, racing, Skribbl) are gathered under one labelled Games menu — see
 * {@link WIDGET_TYPES}. Cards are absolutely positioned in the canvas's logical
 * pixels; drag the header to move, the corner to resize, both applied locally for smoothness
 * and persisted on drop (see {@link useCanvas}). A widget card places the channel's shared
 * widget — the same one the timeline uses — so its state stays in lockstep. Surface-agnostic
 * via the same base-path / stream contract the board and notes use; read-only when `canEdit`
 * is false.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  readonlyHint?: string
}>()

const { items, load, add, patch, remove, topZ, subscribe, unsubscribe } = useCanvas(props.basePath, props.streamName)

const surface = ref<HTMLElement | null>(null)

// The in-flight drag or resize. Start offsets are in screen pixels; origins are the card's
// logical geometry when the gesture began.
type Op = { type: 'move' | 'resize', id: number, startX: number, startY: number, origX: number, origY: number, origW: number, origH: number }
let op: Op | null = null

/**
 * Which card's discussion is open. See the panel in the template for why it isn't in the card.
 */
const inspecting = ref<CanvasItem | null>(null)

watch(items, (list: CanvasItem[]) => {
  // Somebody else deleted the card you had open.
  if (inspecting.value && !list.some(i => i.id === inspecting.value!.id)) inspecting.value = null
})

function beginOp(type: Op['type'], e: PointerEvent, item: CanvasItem) {
  if (!props.canEdit) return
  e.preventDefault()
  raise(item)
  op = { type, id: item.id, startX: e.clientX, startY: e.clientY, origX: item.x, origY: item.y, origW: item.w, origH: item.h }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}

function onMove(e: PointerEvent) {
  if (!op) return
  const item = items.value.find(i => i.id === op!.id)
  if (!item) return
  const dx = e.clientX - op.startX
  const dy = e.clientY - op.startY
  if (op.type === 'move') {
    item.x = Math.max(0, Math.round(op.origX + dx))
    item.y = Math.max(0, Math.round(op.origY + dy))
  } else {
    item.w = Math.max(120, Math.round(op.origW + dx))
    item.h = Math.max(80, Math.round(op.origH + dy))
  }
}

function onUp() {
  window.removeEventListener('pointermove', onMove)
  window.removeEventListener('pointerup', onUp)
  const o = op
  op = null
  if (!o) return
  const item = items.value.find(i => i.id === o.id)
  if (!item) return
  if (o.type === 'move') void patch(item.id, { x: item.x, y: item.y, z: item.z })
  else void patch(item.id, { w: item.w, h: item.h, z: item.z })
}

/** Float a card to the top of the stack (local only; the z is persisted with the next save). */
function raise(item: CanvasItem) {
  const z = topZ()
  if (item.z < z - 1) item.z = z
}

function onChange(item: CanvasItem, content: Record<string, any>) {
  void patch(item.id, { content })
}

/**
 * What can be dropped on the canvas, from the one app registry (see {@link useDeskApps}).
 *
 * This list used to be local and widget-only. Now it's the registry's `canvasable` apps, which
 * is what lets the board, notes and calendar be placed here as cards — the mirror image of a
 * widget being promoted to a tab. A placed app card is a *window* onto the surface's one board
 * / one note / one calendar, not a copy: it reads and writes the same endpoints the tab does.
 *
 * `group` still splits the toolbar. Workspace apps and tools are one-click icons — they're what
 * a canvas is usually for. The games are three of seven widgets and growing, and a row of
 * unlabelled icons stops being readable at that point (a flag and a gamepad don't say "racing"
 * and "Galaga" on sight), so they live behind one labelled Games menu instead.
 */
const PLACEABLE = CANVASABLE_APPS
const TOOL_APPS = PLACEABLE.filter(a => a.group !== 'game')
const GAME_APPS = PLACEABLE.filter(a => a.group === 'game')

const gamesOpen = ref(false)

/**
 * Every app already on the board, by id.
 *
 * One of each: a widget is one-per-(channel, type) so a second card would be the same widget
 * twice, and a second Calendar card would be two windows onto one calendar — visually confusing
 * and useful to nobody. Widget cards are identified by their widget's type, app cards by their
 * `kind`, because only the former carry a widget.
 */
const placed = computed(() => new Set<string>(
  items.value.flatMap(i =>
    i.kind === 'widget' ? (i.widget ? [i.widget.type] : []) : [i.kind]),
))

// Every game already on the board: the menu would open on nothing but disabled rows, so the
// trigger itself goes dead — the same rule each individual button follows.
const allGamesPlaced = computed(() => GAME_APPS.every(a => placed.value.has(a.id)))

// Fresh cards cascade down-right from the current scroll corner so they don't stack exactly.
let cascade = 0
function nextCorner() {
  const el = surface.value
  const offset = (cascade++ % 6) * 26
  return {
    x: Math.round((el?.scrollLeft ?? 0) + 32 + offset),
    y: Math.round((el?.scrollTop ?? 0) + 32 + offset),
  }
}

function addCard(kind: 'note' | 'todo') {
  if (!props.canEdit) return
  const { x, y } = nextCorner()
  const content = kind === 'note' ? { text: '' } : { title: 'Checklist', items: [] }
  const geo = kind === 'note' ? { x, y, w: 220, h: 180 } : { x, y, w: 240, h: 200 }
  void add(kind, content, geo)
}

/**
 * Place an app on the canvas.
 *
 * The two families land differently, and this is the only place that has to care: a widget app
 * becomes a `widget` card naming its type (the server resolves it to the channel's widget), and
 * a surface app becomes a card whose `kind` *is* the app id, carrying no content of its own —
 * the card knows which surface it's on from the canvas's own base path.
 */
function addApp(app: DeskApp) {
  if (!props.canEdit || placed.value.has(app.id)) return
  const { x, y } = nextCorner()
  const geo = { x, y, w: app.card?.w ?? 300, h: app.card?.h ?? 260 }

  if (app.family === 'widget') void add('widget', { type: app.id }, geo)
  else void add(app.id as CanvasItemKind, {}, geo)
}

/** A friendly label for a card's header. */
function labelFor(item: CanvasItem) {
  if (item.kind === 'widget') return deskApp(item.widget?.type as any)?.label ?? 'widget'
  return deskApp(item.kind as any)?.label ?? item.kind
}

onMounted(async () => {
  await load()
  subscribe()
})
onBeforeUnmount(() => {
  unsubscribe()
  window.removeEventListener('pointermove', onMove)
  window.removeEventListener('pointerup', onUp)
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Toolbar -->
    <div class="flex shrink-0 flex-wrap items-center gap-1 border-b p-2">
      <button
        type="button"
        class="flex items-center gap-1.5 rounded border px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :disabled="!canEdit"
        @click="addCard('note')"
      >
        <StickyNote class="h-4 w-4" /> Note
      </button>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded border px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :disabled="!canEdit"
        @click="addCard('todo')"
      >
        <ListChecks class="h-4 w-4" /> Checklist
      </button>

      <span class="mx-0.5 h-5 w-px bg-border" />

      <!-- Drop an app onto the board — a widget, or one of the workspace surfaces (board, notes,
           calendar) as a live window onto the same thing its tab shows. One of each. -->
      <button
        v-for="a in TOOL_APPS"
        :key="a.id"
        type="button"
        class="grid h-7 w-7 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :title="placed.has(a.id) ? `${a.label} (already on canvas)` : `Add ${a.label}`"
        :disabled="!canEdit || placed.has(a.id)"
        @click="addApp(a)"
      >
        <component :is="a.icon" class="h-4 w-4" />
      </button>

      <!-- The games, together and labelled. Three unlabelled icons in the row above read as
           noise; one Games menu reads as a shelf you go to on purpose. -->
      <DropdownMenu v-model:open="gamesOpen">
        <DropdownMenuTrigger as-child>
          <button
            type="button"
            class="flex items-center gap-1.5 rounded border px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
            :title="allGamesPlaced ? 'Every game is already on the canvas' : 'Add a game'"
            :disabled="!canEdit || allGamesPlaced"
          >
            <Gamepad2 class="h-4 w-4" /> Games
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-44">
          <DropdownMenuItem
            v-for="g in GAME_APPS"
            :key="g.id"
            :disabled="placed.has(g.id)"
            @select="addApp(g)"
          >
            <component :is="g.icon" class="h-4 w-4" />
            {{ g.label }}
            <span v-if="placed.has(g.id)" class="ml-auto text-[10px] text-muted-foreground">on canvas</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <span v-if="!canEdit && readonlyHint" class="ml-auto text-xs text-muted-foreground">{{ readonlyHint }}</span>
    </div>

    <!--
      Scrollable board, inside a non-scrolling wrapper.

      The wrapper exists only so the discussion panel below can be `absolute` against the
      *viewport* rather than against the board. Put inside the scroller, the panel flows after a
      1500px-tall board and you have to scroll to the bottom of the canvas to find it.
    -->
    <div class="relative min-h-0 flex-1">
      <div ref="surface" class="h-full w-full overflow-auto bg-muted/20">
        <div class="relative h-[1500px] w-[2000px]">
        <div
          v-for="item in items"
          :key="item.id"
          class="absolute flex flex-col overflow-hidden rounded-lg border bg-card shadow-sm"
          :style="{ left: `${item.x}px`, top: `${item.y}px`, width: `${item.w}px`, height: `${item.h}px`, zIndex: item.z }"
        >
          <!-- Header / drag handle -->
          <div
            class="flex h-7 shrink-0 items-center gap-1 border-b bg-muted/40 px-1.5"
            :class="canEdit ? 'cursor-move' : ''"
            @pointerdown="beginOp('move', $event, item)"
          >
            <GripVertical class="h-3.5 w-3.5 text-muted-foreground" />
            <span class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ labelFor(item) }}</span>
            <!-- The discussion opens in a panel rather than inside the card: a default card is
                 240×180, and a comment thread in that is a scrollbar in a postage stamp. -->
            <button
              type="button"
              class="ml-auto text-muted-foreground hover:text-foreground"
              title="Comments and tags"
              @pointerdown.stop
              @click="inspecting = item"
            >
              <MessageSquare class="h-3.5 w-3.5" />
            </button>
            <button
              v-if="canEdit"
              type="button"
              class="text-muted-foreground hover:text-destructive"
              title="Delete card"
              @pointerdown.stop
              @click="remove(item.id)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>

          <!-- Body. A flex column so an app card (board, notes, calendar) can fill the card the
               same way it fills a tab; note and todo cards keep their natural height either way. -->
          <div class="flex min-h-0 flex-1 flex-col overflow-auto">
            <CanvasNoteCard v-if="item.kind === 'note'" :item="item" :can-edit="canEdit" @change="onChange(item, $event)" />
            <CanvasTodoCard v-else-if="item.kind === 'todo'" :item="item" :can-edit="canEdit" @change="onChange(item, $event)" />
            <!-- A widget card renders the existing interactive widget live over the channel stream. -->
            <div v-else-if="item.kind === 'widget' && item.widget" class="p-1.5">
              <WidgetCard :widget="item.widget" />
            </div>

            <!--
              A workspace app, placed as a card. These are the same components the tabs render,
              pointed at the same base path — so a stroke drawn here is on the Board tab, and an
              event added here is on the Calendar tab. Not a copy of the app: the app.

              They're mounted in a flex column so they fill the card, exactly as they fill a tab.
            -->
            <Whiteboard
              v-else-if="item.kind === 'board'"
              class="flex min-h-0 flex-1 flex-col"
              :base-path="`${basePath}/whiteboard`"
              :stream-name="streamName"
              :can-draw="canEdit"
              :readonly-hint="readonlyHint"
            />
            <SideDeskNotes
              v-else-if="item.kind === 'notes'"
              class="flex min-h-0 flex-1 flex-col"
              :base-path="basePath"
              :stream-name="streamName"
              :can-edit="canEdit"
              :readonly-hint="readonlyHint"
            />
            <!-- `compact` drops the month grid: a full month in a 300px card is six rows of
                 unreadable numbers, so the card shows the agenda instead. -->
            <SideDeskCalendar
              v-else-if="item.kind === 'calendar'"
              class="flex min-h-0 flex-1 flex-col"
              compact
              :base-path="basePath"
              :stream-name="streamName"
              :can-edit="canEdit"
              :readonly-hint="readonlyHint"
            />
          </div>

          <!-- Resize handle -->
          <div
            v-if="canEdit"
            class="absolute bottom-0 right-0 h-4 w-4 cursor-nwse-resize"
            @pointerdown="beginOp('resize', $event, item)"
          >
            <div class="absolute bottom-1 right-1 h-2 w-2 border-b-2 border-r-2 border-muted-foreground/40" />
          </div>
        </div>

          <p v-if="!items.length" class="absolute left-8 top-8 text-sm text-muted-foreground">
            {{ canEdit ? 'Add a note or checklist to start building.' : 'Nothing on the canvas yet.' }}
          </p>
        </div>
      </div>

      <!-- Pinned to the viewport, not the board, so it opens where you're looking and stays
           put as the canvas scrolls under it. Full width on a phone, where a side panel would
           leave neither half usable. -->
      <aside
        v-if="inspecting"
        class="absolute inset-y-0 right-0 z-30 flex w-full flex-col border-l bg-background/95 backdrop-blur sm:w-72"
      >
        <header class="flex shrink-0 items-center gap-2 border-b p-2">
          <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ labelFor(inspecting) }}</span>
          <button
            type="button"
            class="grid h-7 w-7 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted"
            title="Close"
            @click="inspecting = null"
          >
            <X class="h-4 w-4" />
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-2">
          <AppItemDiscussion
            :key="inspecting.id"
            :base-path="basePath"
            subject="canvas_item"
            :item-id="inspecting.id"
            :can-edit="canEdit"
          />
        </div>
      </aside>
    </div>
  </div>
</template>
