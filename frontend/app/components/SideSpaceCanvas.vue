<script setup lang="ts">
import { Columns3, Flag, Gamepad2, GripVertical, ListChecks, Music, StickyNote, Trash2, Vote } from 'lucide-vue-next'
import type { CanvasItem } from '~/types'

/**
 * The Open Canvas app — a scrollable 2D board of freely-placed cards: a markdown note, a
 * checklist, or one of the interactive widgets we already have (music player, kanban, poll,
 * Galaga, racing) rendered live. Cards are absolutely positioned in the canvas's logical
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

// The interactive widgets that can be dropped on the canvas — the ones we already have.
const WIDGET_TYPES = [
  { type: 'music', label: 'Music', icon: Music, w: 300, h: 190 },
  { type: 'kanban', label: 'Kanban', icon: Columns3, w: 340, h: 320 },
  { type: 'poll', label: 'Poll', icon: Vote, w: 280, h: 260 },
  { type: 'shooter', label: 'Galaga', icon: Gamepad2, w: 320, h: 420 },
  { type: 'racing', label: 'Racing', icon: Flag, w: 340, h: 380 },
] as const

// One widget per (channel, type), so a type already on the canvas can't be added again.
const placedWidgetTypes = computed(
  () => new Set(items.value.filter(i => i.kind === 'widget' && i.widget).map(i => i.widget!.type)),
)

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

function addWidget(spec: (typeof WIDGET_TYPES)[number]) {
  if (!props.canEdit || placedWidgetTypes.value.has(spec.type)) return
  const { x, y } = nextCorner()
  void add('widget', { type: spec.type }, { x, y, w: spec.w, h: spec.h })
}

/** A friendly label for a card's header. */
function labelFor(item: CanvasItem) {
  if (item.kind !== 'widget') return item.kind
  return WIDGET_TYPES.find(w => w.type === item.widget?.type)?.label ?? 'widget'
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

      <!-- Drop one of the interactive widgets onto the board (one of each per channel). -->
      <button
        v-for="w in WIDGET_TYPES"
        :key="w.type"
        type="button"
        class="grid h-7 w-7 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :title="placedWidgetTypes.has(w.type) ? `${w.label} (already on canvas)` : `Add ${w.label}`"
        :disabled="!canEdit || placedWidgetTypes.has(w.type)"
        @click="addWidget(w)"
      >
        <component :is="w.icon" class="h-4 w-4" />
      </button>

      <span v-if="!canEdit && readonlyHint" class="ml-auto text-xs text-muted-foreground">{{ readonlyHint }}</span>
    </div>

    <!-- Scrollable board -->
    <div ref="surface" class="relative min-h-0 flex-1 overflow-auto bg-muted/20">
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
            <button
              v-if="canEdit"
              type="button"
              class="ml-auto text-muted-foreground hover:text-destructive"
              title="Delete card"
              @pointerdown.stop
              @click="remove(item.id)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>

          <!-- Body -->
          <div class="min-h-0 flex-1 overflow-auto">
            <CanvasNoteCard v-if="item.kind === 'note'" :item="item" :can-edit="canEdit" @change="onChange(item, $event)" />
            <CanvasTodoCard v-else-if="item.kind === 'todo'" :item="item" :can-edit="canEdit" @change="onChange(item, $event)" />
            <!-- A widget card renders the existing interactive widget live over the channel stream. -->
            <div v-else-if="item.kind === 'widget' && item.widget" class="p-1.5">
              <WidgetCard :widget="item.widget" />
            </div>
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
  </div>
</template>
