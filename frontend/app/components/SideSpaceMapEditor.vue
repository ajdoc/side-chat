<script setup lang="ts">
import { DoorOpen, Eraser, Loader2, PaintBucket, Square, SquareDashed, Trash2, X } from 'lucide-vue-next'
import type { Camera, MapTheme, SpaceMap, SpaceZone } from '~/lib/spaceMapEngine'
import {
  FLOOR,
  TILE,
  WALL,
  blankTiles,
  drawMap,
  isWalkable,
  resizeTiles,
  toScreen,
  toTile,
} from '~/lib/spaceMapEngine'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

/**
 * Building the room. Owner only — the stage hides the way in from everybody else, and the API
 * refuses it besides, because this replaces the floor under people who are standing on it.
 *
 * Edits are local until Save. That's deliberate: painting is a drag, and broadcasting every
 * tile under the brush would be both a flood and a room that flickers for everyone else while
 * one person makes up their mind. One save, one broadcast, one new room.
 *
 * The whole grid is drawn with the same {@link drawMap} the stage uses, so what you paint is
 * literally what everybody will see — there is no second renderer to drift out of step with the
 * first.
 */
const props = defineProps<{ channelId: number, map: SpaceMap }>()
const emit = defineEmits<{ close: [], saved: [] }>()

const { save } = useSpaceMap(props.channelId)

type Brush = 'floor' | 'wall' | 'spawn' | 'zone' | 'erase-zone'

const BRUSHES: { id: Brush, label: string, icon: any, hint: string }[] = [
  { id: 'floor', label: 'Floor', icon: PaintBucket, hint: 'Somewhere people can walk' },
  { id: 'wall', label: 'Wall', icon: Square, hint: 'Blocks movement' },
  { id: 'spawn', label: 'Entrance', icon: DoorOpen, hint: 'Where people arrive' },
  { id: 'zone', label: 'Room', icon: SquareDashed, hint: 'Drag out a sealed room — inside hears inside only' },
  { id: 'erase-zone', label: 'Erase room', icon: Eraser, hint: 'Click a room to remove it' },
]

// A working copy. Nothing here touches the live map until Save.
const name = ref(props.map.name)
const width = ref(props.map.width)
const height = ref(props.map.height)
const tiles = ref<string[]>([...props.map.tiles])
const zones = ref<SpaceZone[]>(props.map.zones.map(z => ({ ...z })))
const spawn = ref({ ...props.map.spawn })

const brush = ref<Brush>('wall')
const saving = ref(false)
const error = ref('')

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

/** An in-progress zone drag, in tiles. */
let zoneDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
let painting = false
let frame: number | undefined
let ro: ResizeObserver | undefined

/** The working map, in the shape the renderer and the API both want. */
const draft = computed<SpaceMap>(() => ({
  id: props.map.id,
  channel_id: props.map.channel_id,
  name: name.value,
  width: width.value,
  height: height.value,
  tiles: tiles.value,
  zones: zones.value,
  spawn: spawn.value,
}))

// --- painting ---

function setTile(x: number, y: number, char: string) {
  if (x < 0 || y < 0 || x >= width.value || y >= height.value) return

  const row = tiles.value[y]
  if (!row || row[x] === char) return

  tiles.value = tiles.value.map((r, i) => (i === y ? r.slice(0, x) + char + r.slice(x + 1) : r))
}

function paintAt(px: number, py: number) {
  const { x, y } = toTile(camera, px, py)

  if (brush.value === 'floor') return setTile(x, y, FLOOR)
  if (brush.value === 'wall') return setTile(x, y, WALL)

  if (brush.value === 'spawn') {
    // The entrance has to be somewhere you can stand — the API refuses otherwise, and being
    // told so on save is a worse way to find out than simply not being able to place it.
    if (isWalkable(draft.value, x, y)) spawn.value = { x, y }

    return
  }

  if (brush.value === 'erase-zone') {
    zones.value = zones.value.filter(z => !(x >= z.x && x < z.x + z.w && y >= z.y && y < z.y + z.h))
  }
}

function onPointerDown(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  const px = e.clientX - rect.left
  const py = e.clientY - rect.top

  canvas.value?.setPointerCapture(e.pointerId)

  if (brush.value === 'zone') {
    const t = toTile(camera, px, py)
    zoneDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  painting = true
  paintAt(px, py)
}

function onPointerMove(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  const px = e.clientX - rect.left
  const py = e.clientY - rect.top

  if (zoneDrag) {
    const t = toTile(camera, px, py)
    zoneDrag.x1 = t.x
    zoneDrag.y1 = t.y

    return
  }

  if (painting) paintAt(px, py)
}

function onPointerUp(e: PointerEvent) {
  canvas.value?.releasePointerCapture(e.pointerId)
  painting = false

  if (!zoneDrag) return

  const x = Math.max(0, Math.min(zoneDrag.x0, zoneDrag.x1))
  const y = Math.max(0, Math.min(zoneDrag.y0, zoneDrag.y1))
  const w = Math.min(width.value, Math.max(zoneDrag.x0, zoneDrag.x1) + 1) - x
  const h = Math.min(height.value, Math.max(zoneDrag.y0, zoneDrag.y1) + 1) - y

  zoneDrag = null

  if (w < 1 || h < 1) return

  zones.value = [...zones.value, {
    id: `z-${Date.now().toString(36)}`,
    name: `Room ${zones.value.length + 1}`,
    kind: 'private',
    x,
    y,
    w,
    h,
  }]
}

// --- the grid's size ---

/**
 * Resize the room, keeping what still fits.
 *
 * Zones and the entrance are pulled back inside as well, since a grid that shrinks past them
 * would otherwise produce a map the API rejects for reasons that aren't visible on screen.
 */
function applySize() {
  width.value = clampSize(width.value)
  height.value = clampSize(height.value)
  tiles.value = resizeTiles(tiles.value, width.value, height.value)
  zones.value = zones.value.filter(z => z.x + z.w <= width.value && z.y + z.h <= height.value)

  if (!isWalkable(draft.value, spawn.value.x, spawn.value.y)) {
    const found = firstFloor()
    if (found) spawn.value = found
  }
}

function clampSize(n: number) {
  return Math.max(8, Math.min(80, Math.round(n) || 8))
}

function firstFloor() {
  for (let y = 0; y < height.value; y++) {
    for (let x = 0; x < width.value; x++) {
      if (tiles.value[y]?.[x] === FLOOR) return { x, y }
    }
  }

  return null
}

function clearRoom() {
  tiles.value = blankTiles(width.value, height.value)
  zones.value = []
  spawn.value = { x: Math.floor(width.value / 2), y: Math.floor(height.value / 2) }
}

// --- saving ---

async function onSave() {
  saving.value = true
  error.value = ''

  try {
    await save({
      name: name.value,
      width: width.value,
      height: height.value,
      tiles: tiles.value,
      zones: zones.value,
      spawn: spawn.value,
    })
    emit('saved')
  }
  catch (e: any) {
    // The API's structural complaints are the useful ones ("Row 5 is not 20 characters"), so
    // show whichever it sent rather than a generic failure.
    const errors = e?.data?.errors as Record<string, string[]> | undefined
    error.value = errors ? Object.values(errors).flat()[0]! : (e?.data?.message ?? 'Could not save this room.')
  }
  finally {
    saving.value = false
  }
}

// --- rendering ---

let palette: MapTheme | null = null
let paletteAt = 0
let probe: HTMLElement | null = null

/** Same trick as the stage: custom properties need resolving through a real element. */
function theme(): MapTheme {
  const now = performance.now()
  if (palette && now - paletteAt < 1000) return palette
  paletteAt = now

  if (!probe) {
    probe = document.createElement('span')
    probe.style.display = 'none'
    document.body.appendChild(probe)
  }

  const resolve = (expr: string, fallback: string) => {
    probe!.style.color = ''
    probe!.style.color = expr

    return getComputedStyle(probe!).color || fallback
  }

  palette = {
    floor: resolve('var(--muted)', '#f1f5f9'),
    floorAlt: resolve('var(--background)', '#ffffff'),
    wall: resolve('var(--border)', '#cbd5e1'),
    wallTop: resolve('var(--accent)', '#e2e8f0'),
    zone: 'rgb(99 102 241 / 0.08)',
    zoneBorder: 'rgb(99 102 241 / 0.45)',
    text: resolve('var(--foreground)', '#0f172a'),
    muted: resolve('var(--muted-foreground)', '#64748b'),
  }

  return palette
}

function resize() {
  const el = canvas.value
  const box = wrap.value
  if (!el || !box) return

  const dpr = window.devicePixelRatio || 1
  const w = box.clientWidth
  const h = box.clientHeight

  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  el.style.width = `${w}px`
  el.style.height = `${h}px`
  el.getContext('2d')?.setTransform(dpr, 0, 0, dpr, 0, 0)

  camera.width = w
  camera.height = h
  fit()
}

/** Show the whole room at once — you can't lay out a floor you can only see a corner of. */
function fit() {
  camera.x = width.value / 2 - 0.5
  camera.y = height.value / 2 - 0.5
  camera.zoom = Math.min(
    camera.width / (width.value * TILE),
    camera.height / (height.value * TILE),
  ) * 0.95
}

function draw() {
  frame = requestAnimationFrame(draw)

  const ctx = canvas.value?.getContext('2d')
  if (!ctx) return

  const p = theme()
  ctx.clearRect(0, 0, camera.width, camera.height)
  drawMap(ctx, draft.value, camera, p)

  drawGrid(ctx, p)
  drawSpawn(ctx)
  drawZoneDrag(ctx)
}

/** Faint tile lines — you're placing individual squares, so you need to see the squares. */
function drawGrid(ctx: CanvasRenderingContext2D, p: MapTheme) {
  const size = TILE * camera.zoom

  ctx.save()
  ctx.globalAlpha = 0.25
  ctx.strokeStyle = p.muted
  ctx.lineWidth = 0.5
  ctx.beginPath()

  for (let x = 0; x <= width.value; x++) {
    const s = toScreen(camera, x - 0.5, -0.5)
    ctx.moveTo(s.x, s.y)
    ctx.lineTo(s.x, s.y + height.value * size)
  }
  for (let y = 0; y <= height.value; y++) {
    const s = toScreen(camera, -0.5, y - 0.5)
    ctx.moveTo(s.x, s.y)
    ctx.lineTo(s.x + width.value * size, s.y)
  }

  ctx.stroke()
  ctx.restore()
}

function drawSpawn(ctx: CanvasRenderingContext2D) {
  const size = TILE * camera.zoom
  const s = toScreen(camera, spawn.value.x - 0.5, spawn.value.y - 0.5)

  ctx.fillStyle = 'rgb(34 197 94 / 0.35)'
  ctx.fillRect(s.x, s.y, size, size)
  ctx.strokeStyle = 'rgb(34 197 94)'
  ctx.lineWidth = 2
  ctx.strokeRect(s.x + 1, s.y + 1, size - 2, size - 2)
}

function drawZoneDrag(ctx: CanvasRenderingContext2D) {
  if (!zoneDrag) return

  const size = TILE * camera.zoom
  const x = Math.min(zoneDrag.x0, zoneDrag.x1)
  const y = Math.min(zoneDrag.y0, zoneDrag.y1)
  const w = Math.abs(zoneDrag.x1 - zoneDrag.x0) + 1
  const h = Math.abs(zoneDrag.y1 - zoneDrag.y0) + 1
  const s = toScreen(camera, x - 0.5, y - 0.5)

  ctx.fillStyle = 'rgb(99 102 241 / 0.18)'
  ctx.fillRect(s.x, s.y, w * size, h * size)
}

watch([width, height], () => fit())

onMounted(() => {
  resize()
  ro = new ResizeObserver(resize)
  if (wrap.value) ro.observe(wrap.value)
  frame = requestAnimationFrame(draw)
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
  ro?.disconnect()
  probe?.remove()
  probe = null
})
</script>

<template>
  <!-- A sheet over the whole app rather than a panel: laying out a floor needs the room. -->
  <div class="fixed inset-0 z-50 flex flex-col bg-background">
    <header class="flex h-12 shrink-0 items-center justify-between gap-3 border-b px-4">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex items-center gap-2 font-semibold">
          Edit the room
          <AlphaBadge hint="The editor is new — save often, and tell us what it gets wrong." />
        </span>
        <Input v-model="name" class="h-8 w-48" placeholder="Room name" />
      </div>

      <div class="flex shrink-0 items-center gap-2">
        <p v-if="error" class="max-w-sm truncate text-xs text-destructive" :title="error">{{ error }}</p>
        <Button variant="outline" size="sm" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button size="sm" :disabled="saving" @click="onSave">
          <Loader2 v-if="saving" class="mr-1.5 h-4 w-4 animate-spin" />
          {{ saving ? 'Saving…' : 'Save room' }}
        </Button>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>
    </header>

    <div class="flex min-h-0 flex-1">
      <!-- Tools -->
      <aside class="w-56 shrink-0 space-y-4 overflow-y-auto border-r p-3">
        <div class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Brush</Label>
          <button
            v-for="b in BRUSHES"
            :key="b.id"
            type="button"
            class="flex w-full items-start gap-2 rounded-md border p-2 text-left text-sm transition-colors"
            :class="brush === b.id ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
            @click="brush = b.id"
          >
            <component :is="b.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span>
              <span class="block font-medium">{{ b.label }}</span>
              <span class="block text-[11px] leading-snug text-muted-foreground">{{ b.hint }}</span>
            </span>
          </button>
        </div>

        <div class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Size</Label>
          <div class="flex items-center gap-2">
            <Input v-model.number="width" type="number" min="8" max="80" class="h-8" @change="applySize" />
            <span class="text-xs text-muted-foreground">×</span>
            <Input v-model.number="height" type="number" min="8" max="80" class="h-8" @change="applySize" />
          </div>
          <p class="text-[11px] leading-snug text-muted-foreground">
            8–80 each way. Growing keeps what's already there and re-walls the edge.
          </p>
        </div>

        <div v-if="zones.length" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Rooms</Label>
          <div v-for="z in zones" :key="z.id" class="flex items-center gap-1.5">
            <Input v-model="z.name" class="h-7 text-xs" />
            <button
              class="rounded p-1 text-muted-foreground hover:text-destructive"
              :title="`Remove ${z.name}`"
              @click="zones = zones.filter(o => o.id !== z.id)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <Button variant="outline" size="sm" class="w-full gap-1.5" @click="clearRoom">
          <Trash2 class="h-3.5 w-3.5" /> Start over
        </Button>
      </aside>

      <!-- The grid -->
      <div ref="wrap" class="relative min-w-0 flex-1 bg-muted/20">
        <canvas
          ref="canvas"
          class="block h-full w-full cursor-crosshair touch-none"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        />
        <p class="pointer-events-none absolute bottom-2 left-2 rounded bg-background/85 px-2 py-1 text-[11px] text-muted-foreground">
          Drag to paint. The green square is where people walk in.
        </p>
      </div>
    </div>
  </div>
</template>
