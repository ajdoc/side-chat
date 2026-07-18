<script setup lang="ts">
import { ArrowUpRight, Circle, Eraser, Minus, MousePointer2, Pencil, Square, StickyNote, Trash2, Type, Undo2 } from 'lucide-vue-next'
import type { WhiteboardStroke, WhiteboardStrokeKind, WhiteboardStrokePayload } from '~/types'
import { LOGICAL_WIDTH, boundingBox, hitStroke, renderStroke, simplify } from '~/lib/whiteboardEngine'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'

/**
 * A shared whiteboard surface — the canvas that makes a chat a place you *build* something
 * in, not just talk in. It renders the persistent board plus everyone's live drags and
 * cursors onto one canvas, and commits finished marks through {@link useWhiteboard}.
 *
 * Surface-agnostic: the caller passes the board's REST base path and the private stream it
 * lives on, so this drives a side chat's board and a channel's alike. Coordinates are the
 * engine's logical space (fixed width), so the board lines up for everyone whatever their
 * panel width — see whiteboardEngine. `canDraw` gates the tools; when false the board is
 * read-only and `readonlyHint` says why.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canDraw: boolean
  readonlyHint?: string
}>()

const { user } = useAuth()
const {
  strokes, liveStrokes, cursors,
  load, addStroke, updateStroke, removeStroke, clear,
  whisperLive, whisperCursor, whisperMove, subscribe, unsubscribe,
} = useWhiteboard(props.basePath, props.streamName)

type Tool = WhiteboardStrokeKind | 'eraser' | 'select'
const TOOLS: { tool: Tool, icon: any, label: string }[] = [
  { tool: 'select', icon: MousePointer2, label: 'Select / move' },
  { tool: 'pen', icon: Pencil, label: 'Pen' },
  { tool: 'eraser', icon: Eraser, label: 'Eraser' },
  { tool: 'rect', icon: Square, label: 'Rectangle' },
  { tool: 'ellipse', icon: Circle, label: 'Ellipse' },
  { tool: 'line', icon: Minus, label: 'Line' },
  { tool: 'arrow', icon: ArrowUpRight, label: 'Arrow' },
  { tool: 'text', icon: Type, label: 'Text' },
  { tool: 'note', icon: StickyNote, label: 'Sticky note' },
]

// Move/resize applies to text labels and sticky notes — the marks with a movable anchor.
const MOVABLE: WhiteboardStrokeKind[] = ['text', 'note']
const MIN_NOTE = 60
const MIN_FONT = 10
const MAX_FONT = 200
const COLORS = ['#111827', '#e11d48', '#2563eb', '#16a34a', '#d97706', '#7c3aed']
const WIDTHS = [2, 4, 8]

const tool = ref<Tool>('pen')
const color = ref(COLORS[0]!)
const width = ref(WIDTHS[1]!)

const wrap = ref<HTMLDivElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const textInput = ref<HTMLInputElement | null>(null)
const cssW = ref(0)
const cssH = ref(0)
const scale = computed(() => (cssW.value > 0 ? cssW.value / LOGICAL_WIDTH : 1))

// The mark being drawn right now, in logical coordinates. Null when idle.
const draft = ref<{ kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload } | null>(null)
let drawing = false
// Select tool: the picked text/note, and an in-flight move or resize of it.
const selectedId = ref<number | null>(null)
let drag: { mode: 'move' | 'resize', stroke: WhiteboardStroke, offX: number, offY: number } | null = null

function selectedStroke(): WhiteboardStroke | null {
  return strokes.value.find(s => s.id === selectedId.value) ?? null
}

/** Is the logical point on the selected mark's bottom-right resize handle? */
function onResizeHandle(stroke: WhiteboardStroke, p: { x: number, y: number }): boolean {
  const box = boundingBox({ kind: stroke.kind, payload: stroke.payload })
  if (!box) return false
  return Math.hypot(p.x - (box.x + box.w), p.y - (box.y + box.h)) <= 14 / scale.value
}
// Inline text/note entry: a floating input anchored at the click, in css + logical coords.
const textEntry = ref<{ cssX: number, cssY: number, x: number, y: number, kind: 'text' | 'note' } | null>(null)
const textValue = ref('')

let ro: ResizeObserver | undefined
let raf = 0

function resize() {
  const el = canvas.value
  const box = wrap.value
  if (!el || !box) return
  const dpr = window.devicePixelRatio || 1
  cssW.value = box.clientWidth
  cssH.value = box.clientHeight
  el.width = Math.round(cssW.value * dpr)
  el.height = Math.round(cssH.value * dpr)
  el.style.width = `${cssW.value}px`
  el.style.height = `${cssH.value}px`
  const ctx = el.getContext('2d')
  if (ctx) ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
}

function paint() {
  const ctx = canvas.value?.getContext('2d')
  if (ctx) {
    ctx.clearRect(0, 0, cssW.value, cssH.value)
    for (const s of strokes.value) renderStroke(ctx, s, scale.value)
    for (const live of Object.values(liveStrokes.value)) renderStroke(ctx, live.stroke, scale.value)
    if (draft.value) renderStroke(ctx, draft.value, scale.value)
    if (tool.value === 'select') drawSelection(ctx)
    for (const c of Object.values(cursors.value)) drawCursor(ctx, c.x, c.y, c.name)
  }
  raf = requestAnimationFrame(paint)
}

/** The dashed outline + corner resize handle around the currently selected text/note. */
function drawSelection(ctx: CanvasRenderingContext2D) {
  const sel = selectedStroke()
  if (!sel || !MOVABLE.includes(sel.kind)) return
  const box = boundingBox({ kind: sel.kind, payload: sel.payload })
  if (!box) return
  const s = scale.value
  const x = box.x * s, y = box.y * s, w = box.w * s, h = box.h * s
  ctx.save()
  ctx.strokeStyle = '#6366f1'
  ctx.lineWidth = 1.5
  ctx.setLineDash([4, 3])
  ctx.strokeRect(x - 3, y - 3, w + 6, h + 6)
  ctx.setLineDash([])
  ctx.fillStyle = '#6366f1'
  ctx.fillRect(x + w - 4, y + h - 4, 11, 11) // bottom-right resize handle
  ctx.restore()
}

function drawCursor(ctx: CanvasRenderingContext2D, lx: number, ly: number, name: string) {
  const x = lx * scale.value
  const y = ly * scale.value
  ctx.save()
  ctx.fillStyle = '#6366f1'
  ctx.beginPath()
  ctx.arc(x, y, 4, 0, Math.PI * 2)
  ctx.fill()
  ctx.font = '11px ui-sans-serif, system-ui, sans-serif'
  ctx.textBaseline = 'bottom'
  const w = ctx.measureText(name).width
  ctx.fillStyle = 'rgba(99,102,241,0.9)'
  ctx.fillRect(x + 6, y - 16, w + 8, 14)
  ctx.fillStyle = '#fff'
  ctx.fillText(name, x + 10, y - 3)
  ctx.restore()
}

function toLogical(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  return { x: (e.clientX - rect.left) / scale.value, y: (e.clientY - rect.top) / scale.value }
}

function onPointerDown(e: PointerEvent) {
  if (!props.canDraw || textEntry.value) return
  const p = toLogical(e)

  // Select tool: pick a text/note to move, or grab its handle to resize.
  if (tool.value === 'select') {
    e.preventDefault()
    const sel = selectedStroke()
    if (sel && onResizeHandle(sel, p)) {
      canvas.value?.setPointerCapture(e.pointerId)
      drag = { mode: 'resize', stroke: sel, offX: 0, offY: 0 }
      return
    }
    for (let i = strokes.value.length - 1; i >= 0; i--) {
      const s = strokes.value[i]!
      if (MOVABLE.includes(s.kind) && s.id > 0 && hitStroke({ kind: s.kind, payload: s.payload }, p, 6)) {
        canvas.value?.setPointerCapture(e.pointerId)
        selectedId.value = s.id
        drag = { mode: 'move', stroke: s, offX: p.x - (s.payload.x ?? 0), offY: p.y - (s.payload.y ?? 0) }
        return
      }
    }
    selectedId.value = null // clicked empty space
    return
  }

  // Text and sticky notes open a floating input at the click. Crucially this must NOT capture
  // the pointer or let the canvas take the default focus — either would immediately blur the
  // fresh input (firing an empty commit) and the box would vanish before you could type. So
  // this branch runs before setPointerCapture, and preventDefault keeps focus off the canvas.
  if (tool.value === 'text' || tool.value === 'note') {
    e.preventDefault()
    const rect = canvas.value!.getBoundingClientRect()
    textEntry.value = { cssX: e.clientX - rect.left, cssY: e.clientY - rect.top, x: p.x, y: p.y, kind: tool.value as 'text' | 'note' }
    textValue.value = ''
    nextTick(() => textInput.value?.focus())
    return
  }

  canvas.value?.setPointerCapture(e.pointerId)
  drawing = true
  if (tool.value === 'eraser') { eraseAt(p); return }
  if (tool.value === 'pen') {
    draft.value = { kind: 'pen', payload: { color: color.value, width: width.value, points: [p] } }
  } else {
    // Reached only for the shape tools (rect/ellipse/line/arrow); select, pen, eraser, text
    // and note all returned above, but a ref read can't narrow across those, so assert.
    draft.value = { kind: tool.value as WhiteboardStrokeKind, payload: { color: color.value, width: width.value, x1: p.x, y1: p.y, x2: p.x, y2: p.y } }
  }
}

function onPointerMove(e: PointerEvent) {
  const p = toLogical(e)

  // Dragging a selected text/note: move its anchor, or resize from the bottom-right corner.
  if (drag) {
    const s = drag.stroke
    if (drag.mode === 'move') {
      s.payload.x = p.x - drag.offX
      s.payload.y = p.y - drag.offY
    } else if (s.kind === 'note') {
      const ax = s.payload.x ?? 0, ay = s.payload.y ?? 0
      s.payload.w = Math.max(MIN_NOTE, Math.max(p.x - ax, p.y - ay))
    } else { // text: the corner drag sets the font size (box height ≈ font × 1.3)
      s.payload.width = Math.max(MIN_FONT, Math.min(MAX_FONT, (p.y - (s.payload.y ?? 0)) / 1.3))
    }
    whisperMove(s.id, s.payload)
    return
  }

  if (!drawing) {
    // Idle hover: let others see where your pointer is. While drawing, the live stroke
    // already carries your position, so we don't also whisper a cursor.
    if (props.canDraw) whisperCursor(p.x, p.y)
    return
  }

  if (tool.value === 'eraser') { eraseAt(p); return }
  if (!draft.value) return
  if (draft.value.kind === 'pen') {
    draft.value.payload.points!.push(p)
  } else {
    draft.value.payload.x2 = p.x
    draft.value.payload.y2 = p.y
  }
  whisperLive(draft.value)
}

async function onPointerUp(e: PointerEvent) {
  canvas.value?.releasePointerCapture(e.pointerId)

  // Finished a move/resize — persist it (the broadcast then corrects every other board).
  if (drag) {
    const s = drag.stroke
    drag = null
    try { await updateStroke(s) } catch { await load() /* reconcile on failure */ }
    return
  }

  if (!drawing) return
  drawing = false
  const d = draft.value
  draft.value = null
  whisperLive(null, true)
  if (!d) return

  // Ignore an accidental click that produced no shape.
  if (d.kind === 'pen') {
    d.payload.points = simplify(d.payload.points ?? [], 1.5)
    if (!d.payload.points.length) return
  } else if (d.payload.x1 === d.payload.x2 && d.payload.y1 === d.payload.y2) {
    return
  }
  await addStroke(d.kind, d.payload, crypto.randomUUID())
}

function eraseAt(p: { x: number, y: number }) {
  // Erase from the top down, so the topmost mark under the cursor goes first.
  for (let i = strokes.value.length - 1; i >= 0; i--) {
    const s = strokes.value[i]!
    if (hitStroke({ kind: s.kind, payload: s.payload }, p, 8)) {
      removeStroke(s)
      break
    }
  }
}

async function commitText() {
  const entry = textEntry.value
  const value = textValue.value.trim()
  textEntry.value = null
  if (!entry || !value) return
  const payload: WhiteboardStrokePayload = entry.kind === 'note'
    ? { x: entry.x, y: entry.y, text: value, color: '#fde68a' }
    : { x: entry.x, y: entry.y, text: value, color: color.value, width: 18 }
  await addStroke(entry.kind, payload, crypto.randomUUID())
}

/** Undo: take back your own most recent mark (not other people's). */
function undoMine() {
  for (let i = strokes.value.length - 1; i >= 0; i--) {
    const s = strokes.value[i]!
    if (s.user?.id === user.value?.id) { removeStroke(s); return }
  }
}

// Clearing wipes the board for everyone, so it goes through a confirm dialog.
const showClear = ref(false)
function onClear() {
  if (!props.canDraw || !strokes.value.length) return
  showClear.value = true
}
async function confirmClear() {
  await clear()
}

// Leaving the Select tool drops the selection so its outline doesn't linger.
watch(tool, () => { selectedId.value = null; drag = null })

onMounted(async () => {
  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)
  raf = requestAnimationFrame(paint)
  await load()
  subscribe()
})
onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  ro?.disconnect()
  unsubscribe()
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-1 border-b p-2">
      <button
        v-for="t in TOOLS"
        :key="t.tool"
        type="button"
        class="grid h-7 w-7 place-items-center rounded transition-colors"
        :class="tool === t.tool ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
        :title="t.label"
        :disabled="!canDraw"
        @click="tool = t.tool"
      >
        <component :is="t.icon" class="h-4 w-4" />
      </button>

      <span class="mx-1 h-5 w-px bg-border" />

      <button
        v-for="c in COLORS"
        :key="c"
        type="button"
        class="h-5 w-5 rounded-full border-2 transition-transform"
        :class="color === c ? 'scale-110 border-foreground' : 'border-transparent'"
        :style="{ backgroundColor: c }"
        :title="`Color ${c}`"
        :disabled="!canDraw"
        @click="color = c"
      />

      <span class="mx-1 h-5 w-px bg-border" />

      <button
        v-for="w in WIDTHS"
        :key="w"
        type="button"
        class="grid h-7 w-7 place-items-center rounded transition-colors"
        :class="width === w ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted'"
        :title="`Width ${w}`"
        :disabled="!canDraw"
        @click="width = w"
      >
        <span class="rounded-full bg-current" :style="{ width: `${w + 2}px`, height: `${w + 2}px` }" />
      </button>

      <span class="ml-auto flex items-center gap-1">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Undo my last mark"
          :disabled="!canDraw"
          @click="undoMine"
        >
          <Undo2 class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive disabled:opacity-40"
          title="Clear the board"
          :disabled="!canDraw"
          @click="onClear"
        >
          <Trash2 class="h-4 w-4" />
        </button>
      </span>
    </div>

    <!-- Canvas -->
    <div ref="wrap" class="relative min-h-0 flex-1 overflow-hidden bg-white dark:bg-neutral-900">
      <canvas
        ref="canvas"
        class="absolute inset-0 touch-none"
        :class="!canDraw ? 'cursor-default' : tool === 'select' ? 'cursor-move' : tool === 'eraser' ? 'cursor-cell' : 'cursor-crosshair'"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
      />

      <!-- Inline text / sticky-note entry -->
      <input
        v-if="textEntry"
        ref="textInput"
        v-model="textValue"
        class="wb-text-input absolute z-10 rounded border border-primary bg-background px-1 py-0.5 text-sm text-foreground shadow outline-none"
        :style="{ left: `${textEntry.cssX}px`, top: `${textEntry.cssY}px`, minWidth: '120px' }"
        :placeholder="textEntry.kind === 'note' ? 'Sticky note…' : 'Text…'"
        @keydown.enter.prevent="commitText"
        @keydown.esc.prevent="textEntry = null"
        @blur="commitText"
      >

      <div
        v-if="!canDraw && readonlyHint"
        class="pointer-events-none absolute inset-x-0 bottom-2 mx-auto w-fit rounded-full bg-background/90 px-3 py-1 text-xs text-muted-foreground shadow"
      >
        {{ readonlyHint }}
      </div>
    </div>

    <AlertDialog v-model:open="showClear">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Clear the whiteboard?</AlertDialogTitle>
          <AlertDialogDescription>
            This wipes the board for everyone and can’t be undone.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            class="bg-destructive text-white hover:bg-destructive/90"
            @click="confirmClear"
          >
            Clear board
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
