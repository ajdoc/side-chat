<script setup lang="ts">
import { Check, ChevronDown, ChevronUp, Eye, EyeOff, Pen, Redo2, Trash2, Type, Undo2, X, ZoomIn, ZoomOut } from 'lucide-vue-next'
import type { StickerContent, StickerShape } from '~/lib/stickers'
import { STICKER_COLORS, STICKER_SHAPES, emptySticker, plainCopy, shapePath, stickerLayers } from '~/lib/stickers'
import { Input } from '~/components/ui/input'

/**
 * Drawing one sticker: pick a shape, fill it, doodle on it, name it.
 *
 * Deliberately not the Board. The Board is a shared infinite surface with live multi-user
 * strokes; this is a private 100×100 box that produces one object. Reusing it would have meant
 * bolting "but only this region, and only for me, and it commits as a record" onto a component
 * whose whole design is the opposite of all three.
 *
 * What *is* reused is the geometry: strokes are points in the same 0–100 space the wall renders
 * through {@link StickerCanvas}, so nothing is rasterised and a sticker stays sharp at any size.
 */
const props = defineProps<{ initial?: StickerContent, name?: string }>()

const emit = defineEmits<{
  save: [{ content: StickerContent, name: string }]
  cancel: []
}>()

const content = ref<StickerContent>(normalise(props.initial))

/**
 * Bring a sticker into the layered shape before editing it.
 *
 * A sticker drawn before layers existed has a flat `paths` array. Converting on open — rather
 * than teaching the editor both shapes — means there is exactly one shape in here, and the
 * conversion is saved the moment you press Place.
 */
function normalise(initial?: StickerContent): StickerContent {
  if (!initial) return emptySticker()
  const copy = plainCopy(initial)
  copy.layers = stickerLayers(copy).map(l => ({ ...l }))
  delete copy.paths
  return copy
}

/** Which layer new strokes land on. */
const activeLayer = ref(0)

const layers = computed(() => content.value.layers ?? [])
const drawTarget = computed(() => layers.value[activeLayer.value] ?? layers.value[0]!)

function addLayer() {
  snapshot()
  content.value.layers = [...layers.value, { name: `Layer ${layers.value.length + 1}`, visible: true, paths: [] }]
  activeLayer.value = layers.value.length - 1
}

/**
 * Snapshotted like any other change, so undo covers it.
 *
 * It wasn't at first, and that made undo lie: hiding a layer then pressing undo took back the
 * stroke *before* it and left the layer hidden, which reads as undo skipping a step.
 */
function toggleLayer(i: number) {
  const l = layers.value[i]
  if (!l) return
  snapshot()
  l.visible = !l.visible
}

/**
 * Select a layer to draw on — and reveal it if it was hidden.
 *
 * Drawing into a hidden layer is the worst of the small bugs here: the pen works, the strokes
 * are saved, and nothing appears. Rather than refusing the click (which needs explaining) or
 * silently dropping the marks, picking a hidden layer turns it back on. Choosing to draw
 * somewhere *is* choosing to see it.
 */
function selectLayer(i: number) {
  const l = layers.value[i]
  if (!l) return
  activeLayer.value = i
  if (!l.visible) {
    snapshot()
    l.visible = true
  }
}

function removeLayer(i: number) {
  // The last one stays. A sticker with no layers has nowhere to draw, and "delete the layer
  // then wonder why the pen does nothing" is a worse outcome than a disabled button.
  if (layers.value.length < 2) return
  snapshot()

  const wasActive = activeLayer.value
  content.value.layers = layers.value.filter((_, j) => j !== i)

  /*
   * Follow the layer you were on, rather than the index you were on.
   *
   * Removing a layer *below* the active one shifts every index above it down by one, so
   * keeping the number would quietly move you to the neighbour — you'd carry on drawing,
   * onto the wrong layer, with nothing to tell you. Only the clamp at the end was there
   * before, which happened to be right when deleting the last layer and wrong otherwise.
   */
  if (i < wasActive) activeLayer.value = wasActive - 1
  clampLayer()
}

/**
 * Move a layer up or down the stack.
 *
 * The thing layers are *for*, once there are two: deciding what covers what. Swapping with the
 * neighbour rather than offering a drag, because the list is short and a drag target inside a
 * 200px panel is a worse way to move one row by one place.
 *
 * `dir` is in stack terms — +1 is toward the front (painted later).
 */
function moveLayer(i: number, dir: 1 | -1) {
  const j = i + dir
  if (j < 0 || j >= layers.value.length) return

  snapshot()
  const next = [...layers.value]
  ;[next[i], next[j]] = [next[j]!, next[i]!]
  content.value.layers = next

  // Follow the layer, not the slot — you moved *this* one, so it stays selected.
  if (activeLayer.value === i) activeLayer.value = j
  else if (activeLayer.value === j) activeLayer.value = i
}
const name = ref(props.name ?? 'New Sticker')

const tool = ref<'select' | 'pen' | 'text'>('pen')
const penColor = ref('#000000')
const penWidth = ref(3)

/**
 * Undo history, as whole snapshots of `paths`.
 *
 * A sticker holds a handful of strokes, so a snapshot costs nothing and a diff-based history
 * would be machinery for a problem this size doesn't have.
 */
type LayerSnapshot = NonNullable<StickerContent['layers']>

const undoStack = ref<LayerSnapshot[]>([])
const redoStack = ref<LayerSnapshot[]>([])

function snapshot() {
  undoStack.value.push(plainCopy(layers.value))
  // A new stroke invalidates anything that was undone — the usual editor contract.
  redoStack.value = []
}

function undo() {
  const prev = undoStack.value.pop()
  if (!prev) return
  redoStack.value.push(plainCopy(layers.value))
  content.value.layers = prev
  clampLayer()
}

function redo() {
  const next = redoStack.value.pop()
  if (!next) return
  undoStack.value.push(plainCopy(layers.value))
  content.value.layers = next
  clampLayer()
}

/** Undoing past an "add layer" must not leave the pen pointed at a layer that's gone. */
function clampLayer() {
  if (activeLayer.value >= layers.value.length) activeLayer.value = Math.max(0, layers.value.length - 1)
}

// --- zoom ---------------------------------------------------------------------------------

/**
 * How large the 100×100 box is drawn.
 *
 * Nothing about the sticker changes — this is purely how much screen it gets, for picking out
 * detail on a small drawing. Which is also why the drawing maths needs no zoom term at all:
 * `toLocal` divides by the *measured* box, so a pointer maps to the same 0–100 point however
 * big the box currently is.
 */
const MIN_ZOOM = 0.5
const MAX_ZOOM = 4
const BASE_SIZE = 420

const zoom = ref(1)

function setZoom(next: number) {
  zoom.value = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, next))
}

/** Ctrl/⌘ + wheel, matching the Board and the wall. Plain wheel still scrolls the panel. */
function onWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey) return
  e.preventDefault()
  setZoom(zoom.value * (e.deltaY < 0 ? 1.1 : 1 / 1.1))
}

// --- drawing ----------------------------------------------------------------------------

const surface = ref<SVGSVGElement | null>(null)
let drawing = false

/** Pointer position in the sticker's own 0–100 space, whatever size the editor is drawn at. */
function toLocal(e: PointerEvent): [number, number] {
  const box = surface.value!.getBoundingClientRect()
  return [
    Math.round(((e.clientX - box.left) / box.width) * 100 * 10) / 10,
    Math.round(((e.clientY - box.top) / box.height) * 100 * 10) / 10,
  ]
}

function start(e: PointerEvent) {
  if (tool.value !== 'pen') return

  snapshot()

  // The other half of the hidden-layer problem: you can hide the layer you're standing on with
  // the eye button, and then the pen writes into nothing. Revealing on the first mark keeps
  // "draw and see the mark" true however you got here.
  if (!drawTarget.value.visible) drawTarget.value.visible = true

  drawing = true
  // Capture, so a stroke that leaves the box keeps drawing instead of ending mid-line.
  ;(e.target as Element).setPointerCapture(e.pointerId)
  drawTarget.value.paths.push({ points: [toLocal(e)], color: penColor.value, width: penWidth.value })
}

function move(e: PointerEvent) {
  if (!drawing) return
  drawTarget.value.paths.at(-1)!.points.push(toLocal(e))
}

function end() {
  drawing = false
  // A tap with no movement leaves a one-point path, which renders as nothing. Drop it rather
  // than leaving invisible entries that undo has to step through.
  const last = drawTarget.value.paths.at(-1)
  if (last && last.points.length < 2) drawTarget.value.paths.pop()
}

/** Clears the *active* layer only — the whole point of having them. */
function clearDrawing() {
  snapshot()
  drawTarget.value.paths = []
}

function pickShape(shape: StickerShape) {
  content.value.shape = shape
}

/** What the live preview draws: every visible layer's paths, in order. */
const visiblePaths = computed(() => layers.value.filter(l => l.visible).flatMap(l => l.paths))
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex shrink-0 items-center gap-2 border-b p-2">
      <Input v-model="name" class="h-8 max-w-[16rem] text-sm font-medium" placeholder="Sticker name" />
      <span class="flex-1" />
      <button type="button" class="grid h-8 w-8 place-items-center rounded border transition-colors hover:bg-muted disabled:opacity-40" :disabled="!undoStack.length" title="Undo" @click="undo">
        <Undo2 class="h-4 w-4" />
      </button>
      <button type="button" class="grid h-8 w-8 place-items-center rounded border transition-colors hover:bg-muted disabled:opacity-40" :disabled="!redoStack.length" title="Redo" @click="redo">
        <Redo2 class="h-4 w-4" />
      </button>
      <button type="button" class="grid h-8 w-8 place-items-center rounded border text-red-500 transition-colors hover:bg-red-500/10" title="Clear the active layer" @click="clearDrawing">
        <Trash2 class="h-4 w-4" />
      </button>
      <button type="button" class="grid h-8 w-8 place-items-center rounded border transition-colors hover:bg-muted" title="Cancel" @click="emit('cancel')">
        <X class="h-4 w-4" />
      </button>
      <button
        type="button"
        class="flex items-center gap-1.5 rounded bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground"
        @click="emit('save', { content, name: name.trim() || 'Sticker' })"
      >
        <Check class="h-4 w-4" /> Place
      </button>
    </header>

    <div class="flex min-h-0 flex-1 flex-col lg:flex-row">
      <!-- The canvas. A dotted ground behind it so a transparent sticker reads as transparent
           rather than as white. -->
      <div
        class="grid min-h-0 flex-1 place-items-center overflow-auto p-4"
        style="background-image: radial-gradient(circle, rgb(120 120 120 / 25%) 1px, transparent 1px); background-size: 12px 12px;"
        @wheel="onWheel"
      >
        <!-- Grown by max-width rather than a transform: the SVG then redraws at the new size
             instead of being scaled up, so a zoomed-in stroke is sharp rather than fattened. -->
        <div class="relative aspect-square w-full" :style="{ maxWidth: `${BASE_SIZE * zoom}px` }">
          <svg
            ref="surface"
            viewBox="0 0 100 100"
            class="h-full w-full touch-none"
            :class="tool === 'pen' ? 'cursor-crosshair' : 'cursor-default'"
            @pointerdown="start"
            @pointermove="move"
            @pointerup="end"
            @pointercancel="end"
          >
            <path
              v-if="content.shape !== 'none'"
              :d="shapePath(content.shape)"
              :fill="content.fill"
              :fill-opacity="content.fillOpacity"
              :stroke="content.stroke"
              stroke-width="1.5"
            />
            <path
              v-for="(p, i) in visiblePaths"
              :key="i"
              :d="p.points.map((pt, j) => `${j === 0 ? 'M' : 'L'}${pt[0]} ${pt[1]}`).join(' ')"
              fill="none"
              :stroke="p.color"
              :stroke-width="p.width"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <text
              v-if="content.text"
              x="50" y="54"
              text-anchor="middle" dominant-baseline="middle"
              font-size="14" font-weight="700"
              :fill="content.textColor ?? '#000000'"
            >{{ content.text }}</text>
          </svg>
        </div>

        <!-- The tool strip, floating under the canvas the way the reference does. -->
        <div class="mt-3 flex items-center gap-1 rounded-xl border bg-background p-1 shadow-lg">
          <button
            type="button"
            class="grid h-8 w-8 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
            title="Zoom out"
            :disabled="zoom <= MIN_ZOOM"
            @click="setZoom(zoom / 1.25)"
          >
            <ZoomOut class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="grid h-8 min-w-11 place-items-center rounded-lg px-1 text-[11px] tabular-nums text-muted-foreground transition-colors hover:bg-muted"
            title="Back to actual size"
            @click="setZoom(1)"
          >
            {{ Math.round(zoom * 100) }}%
          </button>
          <button
            type="button"
            class="grid h-8 w-8 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40"
            title="Zoom in"
            :disabled="zoom >= MAX_ZOOM"
            @click="setZoom(zoom * 1.25)"
          >
            <ZoomIn class="h-4 w-4" />
          </button>
          <span class="mx-0.5 h-5 w-px bg-border" />
          <button
            type="button"
            class="grid h-8 w-8 place-items-center rounded-lg transition-colors"
            :class="tool === 'pen' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            title="Draw"
            @click="tool = 'pen'"
          >
            <Pen class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="grid h-8 w-8 place-items-center rounded-lg transition-colors"
            :class="tool === 'text' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
            title="Caption"
            @click="tool = 'text'"
          >
            <Type class="h-4 w-4" />
          </button>
          <div v-if="tool === 'pen'" class="flex items-center gap-1 border-l pl-1">
            <button
              v-for="c in STICKER_COLORS"
              :key="c"
              type="button"
              class="h-5 w-5 rounded-full border transition-transform"
              :class="penColor === c && 'scale-125 ring-2 ring-primary'"
              :style="{ background: c }"
              :title="c"
              @click="penColor = c"
            />
            <input v-model.number="penWidth" type="range" min="1" max="10" class="ml-1 w-16" title="Pen width">
          </div>
          <div v-if="tool === 'text'" class="border-l pl-1">
            <Input v-model="content.text" placeholder="Caption" class="h-7 w-32 text-xs" />
          </div>
        </div>
      </div>

      <!-- Background: the shape and its colours. -->
      <aside class="w-full shrink-0 space-y-4 overflow-y-auto border-t p-3 lg:w-64 lg:border-l lg:border-t-0">
        <!--
          Layers. Top-first, the way a stack reads on screen — the stored order is bottom-first,
          so this is reversed for display only and the indices passed on are the real ones.

          Unlike the Board's, these never leave the sticker: they're part of the drawing, so
          there's no endpoint and nothing to sync. See StickerLayer.
        -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <p class="text-sm font-semibold">Layers</p>
            <button
              v-if="layers.length < 12"
              type="button"
              class="rounded border px-1.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted"
              title="Add a layer"
              @click="addLayer"
            >
              + Add
            </button>
          </div>

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
              @click="toggleLayer(layers.length - 1 - i)"
            >
              <component :is="layer.visible ? Eye : EyeOff" class="h-3.5 w-3.5" />
            </button>
            <input
              v-model="layer.name"
              class="min-w-0 flex-1 truncate rounded border border-transparent bg-transparent px-1 text-xs outline-none transition-colors hover:border-border focus:border-primary"
              :class="!layer.visible && 'text-muted-foreground line-through'"
              @focus="selectLayer(layers.length - 1 - i)"
              @click="selectLayer(layers.length - 1 - i)"
            >
            <span class="shrink-0 text-[10px] text-muted-foreground">{{ layer.paths.length }}</span>
            <button
              type="button"
              class="shrink-0 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-25"
              :disabled="layers.length - 1 - i >= layers.length - 1"
              title="Move up (in front)"
              @click="moveLayer(layers.length - 1 - i, 1)"
            >
              <ChevronUp class="h-3 w-3" />
            </button>
            <button
              type="button"
              class="shrink-0 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-25"
              :disabled="layers.length - 1 - i <= 0"
              title="Move down (behind)"
              @click="moveLayer(layers.length - 1 - i, -1)"
            >
              <ChevronDown class="h-3 w-3" />
            </button>
            <button
              v-if="layers.length > 1"
              type="button"
              class="shrink-0 text-muted-foreground transition-colors hover:text-red-500"
              :title="`Delete ${layer.name}`"
              @click="removeLayer(layers.length - 1 - i)"
            >
              <Trash2 class="h-3 w-3" />
            </button>
          </div>
          <p class="text-[10px] leading-tight text-muted-foreground">
            The pen and Clear act on the highlighted layer.
          </p>
        </div>

        <div class="space-y-2">
          <p class="text-sm font-semibold">Background</p>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="s in STICKER_SHAPES"
              :key="s.id"
              type="button"
              class="grid aspect-square place-items-center rounded-lg border p-2 transition-colors"
              :class="content.shape === s.id ? 'border-primary bg-primary/10' : 'hover:bg-muted'"
              :title="s.label"
              @click="pickShape(s.id)"
            >
              <svg v-if="s.id !== 'none'" viewBox="0 0 100 100" class="h-full w-full">
                <path :d="shapePath(s.id)" class="fill-muted-foreground/60" />
              </svg>
              <span v-else class="text-[10px] text-muted-foreground">None</span>
            </button>
          </div>
        </div>

        <div class="space-y-1.5">
          <p class="text-xs font-medium text-muted-foreground">Fill color</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="c in STICKER_COLORS"
              :key="c"
              type="button"
              class="h-6 w-6 rounded border transition-transform"
              :class="content.fill === c && 'scale-110 ring-2 ring-primary'"
              :style="{ background: c }"
              @click="content.fill = c"
            />
          </div>
        </div>

        <div class="space-y-1.5">
          <p class="text-xs font-medium text-muted-foreground">Fill opacity</p>
          <input v-model.number="content.fillOpacity" type="range" min="0" max="1" step="0.05" class="w-full">
        </div>

        <div class="space-y-1.5">
          <p class="text-xs font-medium text-muted-foreground">Stroke color</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="c in STICKER_COLORS"
              :key="c"
              type="button"
              class="h-6 w-6 rounded border transition-transform"
              :class="content.stroke === c && 'scale-110 ring-2 ring-primary'"
              :style="{ background: c }"
              @click="content.stroke = c"
            />
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
