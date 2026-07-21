<script setup lang="ts">
import { ArrowUpRight, Circle, Eraser, Minus, MousePointer2, PaintBucket, Pencil, Square, StickyNote, Trash2, Type, Undo2 } from 'lucide-vue-next'
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
 * panel width — see whiteboardEngine. The rendered surface grows past the panel as marks
 * approach its edges and pans in both directions (see {@link resize}), so a narrow column is
 * a window onto the board rather than the whole of it. `canDraw` gates the tools; when false
 * the board is read-only and `readonlyHint` says why.
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

type Tool = WhiteboardStrokeKind | 'eraser' | 'select' | 'bucket'
const TOOLS: { tool: Tool, icon: any, label: string }[] = [
  { tool: 'select', icon: MousePointer2, label: 'Select / move' },
  { tool: 'pen', icon: Pencil, label: 'Pen' },
  { tool: 'eraser', icon: Eraser, label: 'Eraser' },
  { tool: 'bucket', icon: PaintBucket, label: 'Fill — click a mark to paint it' },
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
/**
 * How far each base colour is pushed towards white (negative) or black (positive) to make its
 * shades. A board fills up with same-coloured marks fast — a lighter blue for the second layer
 * of an idea, a darker one for the correction, is what keeps it readable.
 */
const SHADES = [-0.55, -0.28, 0, 0.28, 0.5]
/**
 * Logical breathing room kept past the furthest mark, right and down. The board scrolls, so
 * this is what guarantees there's always fresh surface beyond the last thing you drew.
 */
const BOARD_PAD = 240
/**
 * The narrowest the board is ever rendered, in css pixels. A side chat's column can be 320px
 * wide; squeezing the whole shared board into that makes it unreadable, so below this it stops
 * shrinking and pans sideways instead.
 */
const MIN_BOARD_CSS = 640

/**
 * The tools a fill colour means anything for: the two shapes with an inside, and the bucket
 * that paints one onto a mark already on the board.
 */
const FILLABLE: Tool[] = ['rect', 'ellipse', 'bucket']

/**
 * The explicit "no fill" choice, as distinct from *not having chosen* (null).
 *
 * The difference is the whole behaviour of the fill tool. Unchosen means "I haven't said" —
 * so the bucket paints with the line colour, because a bucket that paints nothing is a broken
 * bucket. NO_FILL means "I said: none" — so the bucket strips a shape's inside, and clicking
 * bare board clears the backdrop. New shapes are outlines either way.
 */
const NO_FILL = 'none'

const tool = ref<Tool>('pen')
const color = ref(COLORS[0]!)
const width = ref(WIDTHS[1]!)
// The fill swatch: a colour, NO_FILL, or null for unchosen.
const fill = ref<string | null>(null)
/** The colour the fill swatch is showing, or null when it's empty (unchosen or NO_FILL). */
const fillColor = computed(() => (fill.value && fill.value !== NO_FILL ? fill.value : null))
/** What the bucket paints with: the fill swatch, falling back to the line colour. */
const bucketColor = computed(() => (fill.value === NO_FILL ? null : fill.value ?? color.value))
const canFill = computed(() => FILLABLE.includes(tool.value))
/** Which swatch the palette is open for — the stroke colour, the fill, or nothing. */
const paletteFor = ref<'stroke' | 'fill' | null>(null)

/** The swatch the open palette is editing, so the grid can tick the current choice. */
const palettePick = computed(() => (paletteFor.value === 'fill' ? fill.value : color.value))

function pickColor(c: string | null) {
  if (paletteFor.value === 'fill') fill.value = c
  else if (c) color.value = c
  paletteFor.value = null
}

/** The free colour input, pointed at whichever swatch the palette was opened for. */
const customColor = computed({
  // NO_FILL isn't a colour an <input type="color"> can show, so it reads as white here.
  get: () => (paletteFor.value === 'fill' ? fillColor.value ?? '#ffffff' : color.value),
  set: (v: string) => {
    if (paletteFor.value === 'fill') fill.value = v
    else color.value = v
  },
})

function hexToRgb(hex: string): [number, number, number] {
  const h = hex.replace('#', '')
  const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h
  return [0, 2, 4].map(i => Number.parseInt(full.slice(i, i + 2), 16) || 0) as [number, number, number]
}

/** `hex` blended `amount` of the way towards black (positive) or white (negative). */
function shade(hex: string, amount: number): string {
  if (!amount) return hex
  const towards = amount > 0 ? 0 : 255
  const t = Math.abs(amount)
  return `#${hexToRgb(hex)
    .map(c => Math.round(c + (towards - c) * t).toString(16).padStart(2, '0'))
    .join('')}`
}

const wrap = ref<HTMLDivElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)
const textInput = ref<HTMLInputElement | null>(null)
// The drawing surface's size in css pixels — the *board's*, which is as large as it needs to
// be, not the panel's. `scale` converts logical units to those pixels; see resize().
const cssW = ref(0)
const cssH = ref(0)
const scale = ref(1)

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
// Guards the one follow-up measurement resize() takes after scrollbars settle.
let settling = false

/** How far right and how far down anything on the board reaches, in logical units. */
function contentExtent(): { right: number, bottom: number } {
  let right = 0
  let bottom = 0
  for (const s of strokes.value) {
    const box = boundingBox({ kind: s.kind, payload: s.payload })
    if (!box) continue
    right = Math.max(right, box.x + box.w)
    bottom = Math.max(bottom, box.y + box.h)
  }
  return { right, bottom }
}

/**
 * Size the drawing surface — a board as big as it needs to be, scrolled to rather than
 * squeezed into the panel.
 *
 * `scale` still comes from the panel's width, so {@link LOGICAL_WIDTH} — the shared coordinate
 * space every client draws in — spans the visible column and the board lines up for everyone.
 * Below a comfortable minimum it stops shrinking, so a narrow side-chat column gets a legible
 * board it can pan across instead of a cramped one. From there the *surface* grows past the
 * viewport in both directions as marks approach its edges, and the wrapper scrolls to reach
 * them: that's what stops a busy board running out of room at the fold or at the margin.
 *
 * Everything below the toolbar is in css pixels of this surface rather than of the viewport, so
 * pointer maths and the text input need no scroll adjustment — they're measured off the canvas
 * rect, which already moves with it.
 */
function resize() {
  const el = canvas.value
  const box = wrap.value
  if (!el || !box) return
  const dpr = window.devicePixelRatio || 1
  const viewW = Math.max(box.clientWidth, MIN_BOARD_CSS)
  scale.value = viewW / LOGICAL_WIDTH

  const { right, bottom } = contentExtent()
  const w = Math.max(viewW, (right + BOARD_PAD) * scale.value)
  const h = Math.max(box.clientHeight, (bottom + BOARD_PAD) * scale.value)
  // Resizing a canvas wipes it, so leave it alone when nothing actually changed.
  if (Math.round(w) === Math.round(cssW.value) && Math.round(h) === Math.round(cssH.value)) return
  cssW.value = w
  cssH.value = h
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  el.style.width = `${w}px`
  el.style.height = `${h}px`
  const ctx = el.getContext('2d')
  if (ctx) ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  // A scrollbar appearing eats into the wrapper's client box, which is what `viewW`/`h` were
  // measured against — so settle once against the new measurements. It converges immediately
  // (the second pass only ever shrinks the surface towards the viewport), and the flag keeps
  // that from becoming a loop.
  if (!settling) {
    settling = true
    requestAnimationFrame(() => {
      settling = false
      resize()
    })
  }
}

/**
 * The board's marks in painting order: the backdrop first, then everything else in the order
 * it was drawn. A `bg` arrives like any other stroke — appended — so without this a board
 * painted after the fact would bury its own contents.
 */
const orderedStrokes = computed(() => [
  ...strokes.value.filter(s => s.kind === 'bg'),
  ...strokes.value.filter(s => s.kind !== 'bg'),
])

function paint() {
  const ctx = canvas.value?.getContext('2d')
  if (ctx) {
    ctx.clearRect(0, 0, cssW.value, cssH.value)
    for (const s of orderedStrokes.value) renderStroke(ctx, s, scale.value)
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

  // The bucket paints what's already there rather than adding anything, so it never starts
  // a draft or captures the pointer.
  if (tool.value === 'bucket') {
    e.preventDefault()
    void fillAt(p)
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
    draft.value = {
      kind: tool.value as WhiteboardStrokeKind,
      payload: {
        color: color.value,
        width: width.value,
        x1: p.x,
        y1: p.y,
        x2: p.x,
        y2: p.y,
        // Only rect and ellipse have an inside; a line carrying a fill would be noise on the wire.
        ...(canFill.value && fillColor.value ? { fill: fillColor.value } : {}),
      },
    }
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

/**
 * The paint bucket, in two halves.
 *
 * On a mark: a rectangle or ellipse gets an inside; everything else has no interior to flood,
 * so it takes the colour on its ink instead, which is what someone reaching for a bucket over
 * a line or a label actually wants. On bare board: the board itself takes the colour — one
 * `bg` mark, painted behind everything, replaced rather than stacked so a board that's been
 * recoloured ten times still carries one backdrop.
 *
 * Either way the colour is {@link bucketColor}: the fill swatch, or the line colour when you
 * haven't chosen one. Only an explicit "No fill" strips — a shape back to an outline, the
 * board back to bare. Like moving someone's sticky note, painting their mark is allowed: the
 * board belongs to the room.
 */
async function fillAt(p: { x: number, y: number }) {
  for (let i = strokes.value.length - 1; i >= 0; i--) {
    const s = strokes.value[i]!
    if (!hitStroke({ kind: s.kind, payload: s.payload }, p, 6)) continue
    if (s.id <= 0) return // still awaiting its server id; nothing to PATCH yet

    if (s.kind === 'rect' || s.kind === 'ellipse') {
      if (bucketColor.value) s.payload.fill = bucketColor.value
      else delete s.payload.fill
    } else if (bucketColor.value) {
      s.payload.color = bucketColor.value
    } else {
      return // "No fill" means nothing on a mark that's all outline
    }

    try {
      await updateStroke(s)
    } catch {
      await load() // reconcile against the board of record
    }
    return
  }

  await paintBoard()
}

/** Set (or clear) the board's backdrop — the empty-board half of the bucket. */
async function paintBoard() {
  // Every backdrop currently on the board, not just the first: two people painting at once
  // each add one, and the loser of that race must not be left buried under the winner.
  const previous = strokes.value.filter(s => s.kind === 'bg')

  if (bucketColor.value) {
    // The new wash lands *before* the old ones go, or the board flashes bare for a round trip.
    await addStroke('bg', { color: bucketColor.value }, crypto.randomUUID())
  }

  for (const bg of previous) await removeStroke(bg)
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

// The surface is sized to fit the marks on it, so anything landing on the board — yours or
// someone else's — may extend it downwards.
watch(strokes, () => resize(), { deep: true })

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

      <!-- Colour and fill. Both swatches open the same palette — every base colour's shades,
           plus a free picker — pointed at whichever one was clicked. -->
      <div class="relative flex items-center gap-1">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded transition-colors hover:bg-muted disabled:opacity-40"
          title="Line colour and shade"
          :aria-expanded="paletteFor === 'stroke'"
          :disabled="!canDraw"
          @click="paletteFor = paletteFor === 'stroke' ? null : 'stroke'"
        >
          <span class="h-4 w-4 rounded-full border border-border" :style="{ backgroundColor: color }" />
        </button>

        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded transition-colors hover:bg-muted disabled:opacity-40"
          :title="canFill ? 'Fill colour' : 'Fill — for rectangles, ellipses and the fill tool'"
          :aria-expanded="paletteFor === 'fill'"
          :disabled="!canDraw || !canFill"
          @click="paletteFor = paletteFor === 'fill' ? null : 'fill'"
        >
          <!-- An empty fill reads as the hollow square it draws. -->
          <span
            class="h-4 w-4 rounded-sm border border-border"
            :class="fillColor ? '' : 'bg-[linear-gradient(to_top_right,transparent_45%,currentColor_45%,currentColor_55%,transparent_55%)] text-muted-foreground'"
            :style="fillColor ? { backgroundColor: fillColor } : {}"
          />
        </button>

        <!-- A full-screen catcher closes the palette on the next click anywhere else. -->
        <div v-if="paletteFor" class="fixed inset-0 z-20" @click="paletteFor = null" />
        <div
          v-if="paletteFor"
          class="absolute left-0 top-8 z-30 w-max rounded-md border bg-popover p-2 text-popover-foreground shadow-md"
        >
          <p class="mb-1.5 text-xs font-medium text-muted-foreground">
            {{ paletteFor === 'fill' ? 'Fill' : 'Line colour' }}
          </p>

          <button
            v-if="paletteFor === 'fill'"
            type="button"
            class="mb-1.5 w-full rounded border px-2 py-1 text-xs transition-colors hover:bg-muted"
            :class="fill === NO_FILL ? 'border-foreground text-foreground' : 'text-muted-foreground'"
            @click="pickColor(NO_FILL)"
          >
            No fill
          </button>
          <p v-if="paletteFor === 'fill'" class="mb-1.5 text-[11px] leading-snug text-muted-foreground">
            The fill tool paints a shape's inside — or the board itself, if you click bare board.
            With no fill chosen it uses the line colour.
          </p>

          <div class="grid grid-cols-6 gap-1">
            <template v-for="s in SHADES" :key="s">
              <button
                v-for="c in COLORS"
                :key="`${c}-${s}`"
                type="button"
                class="h-5 w-5 rounded border-2 transition-transform"
                :class="palettePick === shade(c, s) ? 'scale-110 border-foreground' : 'border-transparent'"
                :style="{ backgroundColor: shade(c, s) }"
                :title="shade(c, s)"
                @click="pickColor(shade(c, s))"
              />
            </template>
          </div>

          <label class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
            <input
              v-model="customColor"
              type="color"
              class="h-6 w-8 cursor-pointer rounded border border-border bg-transparent p-0"
            >
            Custom
          </label>
        </div>
      </div>

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

    <!-- Canvas. The board runs past the panel in both directions once marks approach its edges,
         so the wrapper scrolls it; the surface inside carries the canvas and anything anchored
         to it. -->
    <div class="relative min-h-0 flex-1">
      <div ref="wrap" class="h-full w-full overflow-auto bg-white dark:bg-neutral-900">
        <div class="relative" :style="{ width: `${cssW}px`, height: `${cssH}px` }">
          <canvas
            ref="canvas"
            class="absolute inset-0 touch-none"
            :class="!canDraw ? 'cursor-default' : tool === 'select' ? 'cursor-move' : tool === 'eraser' ? 'cursor-cell' : tool === 'bucket' ? 'cursor-copy' : 'cursor-crosshair'"
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
        </div>
      </div>

      <!-- Rides above the scroller, so it stays put as the board moves under it. -->
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
