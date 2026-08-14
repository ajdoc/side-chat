<script setup lang="ts">
import { ArrowUpRight, Circle, Eraser, Eye, EyeOff, Hand, Layers3, Maximize, Minus, MousePointer2, PaintBucket, Pencil, Redo2, Square, StickyNote, Trash2, Type, Undo2, ZoomIn, ZoomOut } from 'lucide-vue-next'
import type { WhiteboardStroke, WhiteboardStrokeKind, WhiteboardStrokePayload } from '~/types'
import type { BoardEntry } from '~/composables/useWhiteboard'
import { snapshot } from '~/composables/useWhiteboard'
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

const {
  strokes, liveStrokes, cursors,
  layers, hiddenLayers, activeLayer, addLayer, renameLayer, toggleLayer,
  load, addStroke, removeStroke,
  draw, erase, editStroke, asOneGesture, clear,
  undo, redo, canUndo, canRedo,
  whisperLive, whisperCursor, whisperMove, subscribe, unsubscribe,
} = useWhiteboard(props.basePath, props.streamName)

type Tool = WhiteboardStrokeKind | 'eraser' | 'select' | 'bucket' | 'pan'
const TOOLS: { tool: Tool, icon: any, label: string }[] = [
  // Pan leads the row because on a touch screen it is the tool you need first: the board is
  // wider than a phone, and every other tool draws on it rather than moves it.
  { tool: 'pan', icon: Hand, label: 'Pan — drag the board around' },
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
 * How far in or out the viewer has zoomed, on top of the fit-to-panel scale.
 *
 * A private multiplier — it changes nothing about the shared coordinate space, so two people
 * at different zoom levels are still drawing on the same board. It exists mostly for the
 * phone: {@link MIN_BOARD_CSS} means the board is never rendered narrower than 640px, which
 * on a 390px screen is a board you can only ever see two thirds of. Zooming out is how that
 * screen sees the whole thing.
 */
const MIN_ZOOM = 0.35
const MAX_ZOOM = 4
const zoom = ref(1)

function setZoom(next: number) {
  zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, next))
}

// The one-off "you can move this" nudge, shown to fingers only and retired by the first touch.
const coarse = useCoarsePointer()
const touchHint = ref(false)
let hintTimer: ReturnType<typeof setTimeout> | undefined

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
const layersOpen = ref(false)
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

/**
 * Whether the pointer is over the board — the toolbar included, not just the canvas.
 *
 * The keyboard shortcuts are claimed only while it is. A board can be a tab in a panel or a
 * floating window sitting over a chat, and in both cases something else on the page has an
 * equally good claim to Ctrl+Z — the message you were rewriting, most obviously. Hover is the
 * cheapest honest answer to "did they mean the board?".
 */
const hovering = ref(false)

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
// `before` is the mark's geometry as the drag began — snapshotted because the live payload is
// mutated in place for instant feedback, and undo needs the side of the change that's now gone.
let drag: { mode: 'move' | 'resize', stroke: WhiteboardStroke, offX: number, offY: number, before: WhiteboardStrokePayload } | null = null

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
  const fitW = Math.max(box.clientWidth, MIN_BOARD_CSS)
  scale.value = (fitW / LOGICAL_WIDTH) * zoom.value

  const { right, bottom } = contentExtent()
  // The floor is the *panel*, not the fit width: zoomed out, the whole board is allowed to
  // become narrower than MIN_BOARD_CSS and finally fit on a phone, which is the point of it.
  const w = Math.max(box.clientWidth, (right + BOARD_PAD) * scale.value, LOGICAL_WIDTH * scale.value)
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
 * The board's marks in painting order: the backdrop first, then everything else by layer and
 * then by the order it was drawn.
 *
 * A `bg` arrives like any other stroke — appended — so without pulling it forward a board
 * painted after the fact would bury its own contents. Layer order comes second because it's
 * what "layers" means; within a layer the older mark is still underneath.
 *
 * Hidden layers are dropped here rather than skipped in `renderStroke`, so hit-testing (which
 * reads `strokes` directly) is the only thing that still sees them — and that's deliberate:
 * see `visibleStrokes`.
 */
const orderedStrokes = computed(() => {
  const visible = strokes.value.filter(s => !hiddenLayers.value.has(s.layer ?? 0))
  return [
    ...visible.filter(s => s.kind === 'bg'),
    ...visible.filter(s => s.kind !== 'bg').sort((a, b) => (a.layer ?? 0) - (b.layer ?? 0)),
  ]
})

/**
 * What the pointer may select or erase: only what's on screen.
 *
 * Hit-testing used to walk `strokes` backwards. With layers that would let you erase a mark on
 * a hidden layer by clicking empty space above it — the worst kind of bug, since you can't see
 * what you destroyed. Everything that resolves a click goes through this instead.
 */
const visibleStrokes = computed(() => strokes.value.filter(s => !hiddenLayers.value.has(s.layer ?? 0)))

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

/**
 * Moving the board, rather than drawing on it.
 *
 * The canvas is `touch-action: none` — it has to be, or the browser claims every finger drag
 * as a scroll and the pen never draws a line. The cost is that it also swallows the wrapper's
 * own scrolling, which on a phone left the board completely stuck: wider than the screen, and
 * unpannable. So panning is done here by hand instead of by the browser, on two gestures:
 *
 *   - the Pan tool, one finger (or the mouse) dragging the board about; and
 *   - two fingers, from *any* tool, which pan and pinch-zoom together. That one matters
 *     because it means you never have to put the pen down to go and look somewhere else.
 *
 * Every live pointer is tracked, because "is this a second finger" is the whole question.
 */
const pointers = new Map<number, { x: number, y: number }>()
let pan: { x: number, y: number, left: number, top: number } | null = null
let pinch: { distance: number, zoom: number, x: number, y: number, left: number, top: number } | null = null

function twoFingerState() {
  const [a, b] = [...pointers.values()]
  if (!a || !b) return null
  return {
    distance: Math.max(1, Math.hypot(b.x - a.x, b.y - a.y)),
    x: (a.x + b.x) / 2,
    y: (a.y + b.y) / 2,
  }
}

/** Abandon whatever mark or drag was in flight — a second finger means you meant to navigate. */
function abandonGesture() {
  drawing = false
  drag = null
  if (draft.value) {
    draft.value = null
    whisperLive(null, true)
  }
}

function beginPan(e: PointerEvent) {
  const box = wrap.value
  if (!box) return
  canvas.value?.setPointerCapture(e.pointerId)
  pan = { x: e.clientX, y: e.clientY, left: box.scrollLeft, top: box.scrollTop }
}

function movePan(e: PointerEvent) {
  const box = wrap.value
  if (!box || !pan) return
  box.scrollLeft = pan.left - (e.clientX - pan.x)
  box.scrollTop = pan.top - (e.clientY - pan.y)
}

function beginPinch() {
  const box = wrap.value
  const state = twoFingerState()
  if (!box || !state) return
  abandonGesture()
  pinch = { distance: state.distance, zoom: zoom.value, x: state.x, y: state.y, left: box.scrollLeft, top: box.scrollTop }
}

function movePinch() {
  const box = wrap.value
  const state = twoFingerState()
  if (!box || !pinch || !state) return

  // Pan on the midpoint's travel, then zoom on how far apart the fingers have moved. The zoom
  // watcher re-anchors the panel's centre afterwards, so the two don't fight each other.
  box.scrollLeft = pinch.left - (state.x - pinch.x)
  box.scrollTop = pinch.top - (state.y - pinch.y)
  setZoom(pinch.zoom * (state.distance / pinch.distance))
}

/**
 * Ctrl/⌘ + wheel zooms, the way every canvas app does; a plain wheel keeps scrolling the board,
 * which is what the wrapper would have done anyway.
 */
function onWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey) return
  e.preventDefault()
  setZoom(zoom.value * (e.deltaY < 0 ? 1.1 : 1 / 1.1))
}

/** Back to 1:1 with the panel, from wherever the pinching left it. */
function resetZoom() {
  setZoom(1)
}

/**
 * Pull the whole shared width into the panel.
 *
 * Distinct from 100%, and the more useful of the two on a phone: at 100% the board is still
 * MIN_BOARD_CSS wide however narrow the screen is, so this is the button that actually says
 * "show me all of it".
 */
function fitWidth() {
  const box = wrap.value
  if (!box) return
  setZoom(box.clientWidth / Math.max(box.clientWidth, MIN_BOARD_CSS))
}

function onPointerDown(e: PointerEvent) {
  pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })
  touchHint.value = false

  // A second finger takes over from whatever the first was doing.
  if (pointers.size === 2) {
    pan = null
    beginPinch()
    return
  }
  if (pointers.size > 2) return

  // Panning is navigation, not authorship — it works on a read-only board too.
  if (tool.value === 'pan') {
    e.preventDefault()
    beginPan(e)
    return
  }

  if (!props.canDraw || textEntry.value) return
  const p = toLogical(e)

  // Select tool: pick a text/note to move, or grab its handle to resize.
  if (tool.value === 'select') {
    e.preventDefault()
    const sel = selectedStroke()
    if (sel && onResizeHandle(sel, p)) {
      canvas.value?.setPointerCapture(e.pointerId)
      drag = { mode: 'resize', stroke: sel, offX: 0, offY: 0, before: snapshot(sel.payload) }
      return
    }
    for (let i = visibleStrokes.value.length - 1; i >= 0; i--) {
      const s = visibleStrokes.value[i]!
      if (MOVABLE.includes(s.kind) && s.id > 0 && hitStroke({ kind: s.kind, payload: s.payload }, p, 6)) {
        canvas.value?.setPointerCapture(e.pointerId)
        selectedId.value = s.id
        drag = { mode: 'move', stroke: s, offX: p.x - (s.payload.x ?? 0), offY: p.y - (s.payload.y ?? 0), before: snapshot(s.payload) }
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
  if (pointers.has(e.pointerId)) pointers.set(e.pointerId, { x: e.clientX, y: e.clientY })

  if (pinch) return movePinch()
  if (pan) return movePan(e)

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
  pointers.delete(e.pointerId)

  // Lifting one of two fingers ends the pinch rather than handing the gesture back to the
  // remaining finger — carrying on as a one-finger pan would jump the board.
  if (pinch) {
    if (pointers.size < 2) pinch = null
    return
  }
  if (pan) {
    pan = null
    return
  }

  // Finished a move/resize — persist it (the broadcast then corrects every other board).
  if (drag) {
    const { stroke, before } = drag
    drag = null
    try { await editStroke(stroke, before) } catch { await load() /* reconcile on failure */ }
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
  await draw(d.kind, d.payload)
}

/**
 * The paint bucket, in two halves.
 *
 * On a mark: a rectangle, an ellipse or a free-form pen scribble gets an inside; a line, an
 * arrow or a label has no interior to flood, so it takes the colour on its ink instead, which
 * is what someone reaching for a bucket over one of those actually wants. On bare board: the
 * board itself takes the colour — one
 * `bg` mark, painted behind everything, replaced rather than stacked so a board that's been
 * recoloured ten times still carries one backdrop.
 *
 * Either way the colour is {@link bucketColor}: the fill swatch, or the line colour when you
 * haven't chosen one. Only an explicit "No fill" strips — a shape back to an outline, the
 * board back to bare. Like moving someone's sticky note, painting their mark is allowed: the
 * board belongs to the room.
 */
async function fillAt(p: { x: number, y: number }) {
  for (let i = visibleStrokes.value.length - 1; i >= 0; i--) {
    const s = visibleStrokes.value[i]!
    if (!hitStroke({ kind: s.kind, payload: s.payload }, p, 6)) continue
    if (s.id <= 0) return // still awaiting its server id; nothing to PATCH yet

    const before = snapshot(s.payload)

    if (s.kind === 'rect' || s.kind === 'ellipse' || s.kind === 'pen') {
      if (bucketColor.value) s.payload.fill = bucketColor.value
      else delete s.payload.fill
    } else if (bucketColor.value) {
      s.payload.color = bucketColor.value
    } else {
      return // "No fill" means nothing on a mark that's all outline
    }

    try {
      await editStroke(s, before)
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

  // Recolouring the board is several writes and exactly one gesture, so it's logged as one:
  // undoing it has to bring the old backdrop back *and* take the new one away, or the board
  // ends up in a state nobody asked for. See asOneGesture.
  await asOneGesture(async () => {
    const ops: BoardEntry = []
    const clientId = crypto.randomUUID()

    if (bucketColor.value) {
      const payload = { color: bucketColor.value }
      // The new wash lands *before* the old ones go, or the board flashes bare for a round trip.
      await addStroke('bg', payload, clientId)
      ops.push({ op: 'add' as const, clientId, kind: 'bg' as const, payload })
    }

    for (const bg of previous) {
      ops.push({ op: 'erase' as const, clientId: bg.client_id, kind: bg.kind, payload: snapshot(bg.payload) })
      await removeStroke(bg)
    }

    return ops
  })
}

function eraseAt(p: { x: number, y: number }) {
  // Erase from the top down, so the topmost mark under the cursor goes first.
  for (let i = visibleStrokes.value.length - 1; i >= 0; i--) {
    const s = visibleStrokes.value[i]!
    if (hitStroke({ kind: s.kind, payload: s.payload }, p, 8)) {
      void erase(s)
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
  await draw(entry.kind, payload)
}

/**
 * Undo and redo, from the toolbar or from Ctrl/⌘+Z and Ctrl/⌘+Y (⇧Z also works, as it does
 * everywhere else).
 *
 * Scoped to *my own* gestures, and applied as inverse operations through the same API the
 * originals went through — see the history log in useWhiteboard for why a shared board can't
 * undo by snapshot. So pressing it never disturbs somebody else's mark, and when it does put
 * something back, everyone watching sees it come back.
 */
function onUndo() {
  if (!props.canDraw || !canUndo.value) return
  selectedId.value = null
  void undo()
}

function onRedo() {
  if (!props.canDraw || !canRedo.value) return
  selectedId.value = null
  void redo()
}

/**
 * The board only claims the keys while the pointer is over it and nothing else is being typed
 * into — a board floating beside a composer must not eat the Ctrl+Z of somebody rewriting a
 * message. `hovering` is that gate.
 */
function onKeydown(e: KeyboardEvent) {
  if (!hovering.value || !props.canDraw) return
  if (!(e.ctrlKey || e.metaKey) || e.altKey) return

  const target = e.target as HTMLElement | null
  if (target?.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '')) return

  const key = e.key.toLowerCase()

  if (key === 'z' && !e.shiftKey) { e.preventDefault(); onUndo(); return }
  // Redo answers to both conventions: Ctrl+Y (Windows) and Ctrl+Shift+Z (mac, and most editors).
  if (key === 'y' || (key === 'z' && e.shiftKey)) { e.preventDefault(); onRedo() }
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

/**
 * Re-lay the surface at the new zoom, keeping whatever was in the middle of the panel there.
 *
 * Scroll offsets are css pixels and so scale with the zoom; dividing by the old factor gives a
 * zoom-independent point on the board, which is then put back under the same place on screen.
 * Without it, zooming out on a phone threw you to the top-left corner of an empty board.
 */
watch(zoom, (next, previous) => {
  const box = wrap.value
  if (!box) return resize()

  const cx = (box.scrollLeft + box.clientWidth / 2) / previous
  const cy = (box.scrollTop + box.clientHeight / 2) / previous
  resize()
  nextTick(() => {
    box.scrollLeft = cx * next - box.clientWidth / 2
    box.scrollTop = cy * next - box.clientHeight / 2
  })
})

onMounted(async () => {
  if (coarse.value) {
    touchHint.value = true
    hintTimer = setTimeout(() => (touchHint.value = false), 6000)
  }
  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)
  raf = requestAnimationFrame(paint)
  // On the window rather than the board: the canvas isn't focusable, so a keystroke never
  // arrives at it. `hovering` is what scopes them to this board instead.
  window.addEventListener('keydown', onKeydown)
  await load()
  subscribe()
})
onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  ro?.disconnect()
  clearTimeout(hintTimer)
  window.removeEventListener('keydown', onKeydown)
  unsubscribe()
})
</script>

<template>
  <!-- Hover is what scopes the keyboard shortcuts to this board (see onKeydown), so it's claimed
       for the whole component rather than just the canvas: reaching for Ctrl+Z with the pointer
       resting on the toolbar is not a person who meant some other undo. -->
  <div
    class="flex min-h-0 flex-1 flex-col"
    @pointerenter="hovering = true"
    @pointerleave="hovering = false"
  >
    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-1 border-b p-2">
      <button
        v-for="t in TOOLS"
        :key="t.tool"
        type="button"
        class="grid h-7 w-7 place-items-center rounded transition-colors"
        :class="tool === t.tool ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
        :title="t.label"
        :disabled="!canDraw && t.tool !== 'pan'"
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

      <span class="mx-1 h-5 w-px bg-border" />

      <!-- Zoom. Buttons rather than pinch-only, because a mouse has no pinch and a read-only
           board on a phone still has to be readable. -->
      <span class="flex items-center gap-1">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Zoom out"
          :disabled="zoom <= MIN_ZOOM"
          @click="setZoom(zoom / 1.25)"
        >
          <ZoomOut class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 min-w-11 place-items-center rounded px-1 text-[11px] tabular-nums text-muted-foreground transition-colors hover:bg-muted"
          title="Fit the board to the panel"
          @click="resetZoom"
        >
          {{ Math.round(zoom * 100) }}%
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Zoom in"
          :disabled="zoom >= MAX_ZOOM"
          @click="setZoom(zoom * 1.25)"
        >
          <ZoomIn class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted"
          title="Fit the whole board width"
          @click="fitWidth"
        >
          <Maximize class="h-4 w-4" />
        </button>
      </span>

      <span class="ml-auto flex items-center gap-1">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Undo (Ctrl+Z)"
          :disabled="!canDraw || !canUndo"
          @click="onUndo"
        >
          <Undo2 class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
          title="Redo (Ctrl+Y)"
          :disabled="!canDraw || !canRedo"
          @click="onRedo"
        >
          <Redo2 class="h-4 w-4" />
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
      <!--
        Layers.

        Floated over the board rather than added to the toolbar, which is already a scrolling
        strip on a phone, and collapsed to a single button by default — most boards have one
        layer and never want to think about it. Opening it is the moment you do.

        Top-right, so it stays clear of the chat pill an app channel or a Side Space floats
        bottom-right.
      -->
      <div class="absolute right-2 top-2 z-10">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-md border bg-background/90 px-2 py-1 text-xs shadow backdrop-blur transition-colors hover:bg-muted"
          :title="layersOpen ? 'Hide layers' : 'Layers'"
          @click="layersOpen = !layersOpen"
        >
          <Layers3 class="h-3.5 w-3.5" />
          <span v-if="layers.length > 1">{{ activeLayer + 1 }}/{{ layers.length }}</span>
        </button>

        <div
          v-if="layersOpen"
          class="mt-1 w-52 space-y-1 rounded-lg border bg-background/95 p-1.5 shadow-lg backdrop-blur"
        >
          <!--
            Drawn top-first, because that's how a stack reads on screen: the last layer is the
            one painted over everything. The stored order is bottom-first (index 0 is the
            bottom), so this is reversed for display only — the indices handed to the callbacks
            are the real ones.
          -->
          <div
            v-for="(layer, i) in [...layers].reverse()"
            :key="layers.length - 1 - i"
            class="flex items-center gap-1 rounded px-1 py-0.5 transition-colors"
            :class="activeLayer === layers.length - 1 - i ? 'bg-primary/15' : 'hover:bg-muted'"
          >
            <button
              type="button"
              class="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
              :title="layer.visible ? `Hide ${layer.name}` : `Show ${layer.name}`"
              :disabled="!canDraw"
              @click="toggleLayer(layers.length - 1 - i)"
            >
              <component :is="layer.visible ? Eye : EyeOff" class="h-3.5 w-3.5" />
            </button>

            <!-- One control for two jobs: click selects the layer to draw on, and the text is
                 editable in place so renaming isn't a second menu. -->
            <input
              :value="layer.name"
              class="min-w-0 flex-1 truncate rounded border border-transparent bg-transparent px-1 text-xs outline-none transition-colors hover:border-border focus:border-primary"
              :class="!layer.visible && 'text-muted-foreground line-through'"
              :readonly="!canDraw"
              @focus="activeLayer = layers.length - 1 - i"
              @click="activeLayer = layers.length - 1 - i"
              @change="renameLayer(layers.length - 1 - i, ($event.target as HTMLInputElement).value.trim() || layer.name)"
              @keydown.enter="($event.target as HTMLInputElement).blur()"
            >
          </div>

          <button
            v-if="canDraw && layers.length < 64"
            type="button"
            class="w-full rounded border border-dashed px-2 py-1 text-[11px] text-muted-foreground transition-colors hover:bg-muted"
            @click="addLayer"
          >
            + Add layer
          </button>
          <p class="px-1 text-[10px] leading-tight text-muted-foreground">
            New marks land on the highlighted layer.
          </p>
        </div>
      </div>

      <div ref="wrap" class="h-full w-full overflow-auto bg-white dark:bg-neutral-900">
        <div class="relative" :style="{ width: `${cssW}px`, height: `${cssH}px` }">
          <canvas
            ref="canvas"
            class="absolute inset-0 touch-none"
            :class="tool === 'pan' ? 'cursor-grab active:cursor-grabbing' : !canDraw ? 'cursor-default' : tool === 'select' ? 'cursor-move' : tool === 'eraser' ? 'cursor-cell' : tool === 'bucket' ? 'cursor-copy' : 'cursor-crosshair'"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
            @wheel="onWheel"
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

      <!-- Two-finger pan/zoom is invisible until someone tries it, and the board being stuck
           is exactly the confusion this is here to prevent. Said once, then gone. -->
      <div
        v-if="touchHint && !(!canDraw && readonlyHint)"
        class="pointer-events-none absolute inset-x-0 bottom-2 mx-auto w-fit rounded-full bg-background/90 px-3 py-1 text-xs text-muted-foreground shadow"
      >
        Two fingers to pan and zoom · or pick <Hand class="inline h-3 w-3 align-text-bottom" />
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
