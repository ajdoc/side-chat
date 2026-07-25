<script setup lang="ts">
import { DoorOpen, Eraser, LayoutTemplate, Loader2, Sofa, SquareDashed, Trash2, X } from 'lucide-vue-next'
import type { Camera, MapTheme, SpaceMap, SpaceZone } from '~/lib/spaceMapEngine'
import type { SpaceObject } from '~/lib/spaceDecor'
import type { MapPreset } from '~/composables/useSpacePresets'
import {
  TILE,
  TILE_BRUSHES,
  blankTiles,
  drawMap,
  isWalkable,
  resizeTiles,
  toScreen,
  toTile,
} from '~/lib/spaceMapEngine'
import { DECOR, decorCovers } from '~/lib/spaceDecor'
import { isWalkableTile } from '~/lib/spaceTiles'
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
 * first, and furniture previews as the sprite it will be rather than as a coloured box.
 *
 * ## Two layers, one brush
 *
 * The ground is a grid of characters and the furniture is a list of things standing on it, so
 * the brush is really two brushes wearing one coat: a tile character to paint, or a kind of
 * furniture to place. Both are checked against the same rules the server will apply on save —
 * a painting refuses to go anywhere but a wall, a desk refuses to go anywhere but the floor —
 * because being told on save is a much worse way to find out than simply not being able to put
 * it there.
 */
/**
 * `mode` is the owner/member split made visible.
 *
 *   - `full` — the owner's editor: ground, furniture, rooms, the entrance, the size. Saves the
 *     whole map, and is the only way any of the geometry changes.
 *   - `decor` — a member decorating: furniture and nothing else. The ground shows through as a
 *     backdrop you can't paint, and Save writes only the furniture, through the member endpoint.
 *
 * One component rather than two because the *canvas* — how a room is drawn, how a piece previews,
 * how placement is checked — is identical either way. All `mode` changes is which tools appear
 * and which endpoint Save calls.
 */
const props = withDefaults(defineProps<{ channelId: number, map: SpaceMap, mode?: 'full' | 'decor' }>(), {
  mode: 'full',
})
const emit = defineEmits<{ close: [], saved: [] }>()

const { save, saveObjects } = useSpaceMap(props.channelId)

const isDecorMode = computed(() => props.mode === 'decor')

type Tool = 'tile' | 'decor' | 'spawn' | 'zone' | 'erase-zone' | 'erase-decor'

/** The full toolbox. In decorate mode only the furniture eraser survives — see {@link TOOLS}. */
const ALL_TOOLS: { id: Tool, label: string, icon: any, hint: string, decor?: boolean }[] = [
  { id: 'spawn', label: 'Entrance', icon: DoorOpen, hint: 'Where people arrive' },
  { id: 'zone', label: 'Room', icon: SquareDashed, hint: 'Drag out a sealed room — inside hears inside only' },
  { id: 'erase-zone', label: 'Erase room', icon: Eraser, hint: 'Click a room to remove it' },
  { id: 'erase-decor', label: 'Remove furniture', icon: Trash2, hint: 'Click a thing to take it away', decor: true },
]

const TOOLS = computed(() => (isDecorMode.value ? ALL_TOOLS.filter(t => t.decor) : ALL_TOOLS))

/** Furniture, grouped the way somebody furnishing a room thinks about it. */
const DECOR_GROUPS: { title: string, kinds: string[] }[] = [
  { title: 'Things that do something', kinds: ['speaker', 'tv', 'computer', 'arcade', 'racer', 'easel', 'noticeboard'] },
  { title: 'Furniture', kinds: ['desk', 'couch', 'bench', 'chair', 'stool', 'bookshelf', 'cabinet', 'fridge', 'watercooler', 'lamp'] },
  { title: 'Bits and pieces', kinds: ['plant', 'crate', 'barrel', 'campfire', 'rug', 'mat'] },
  { title: 'On the wall', kinds: ['painting', 'poster', 'window', 'clock', 'shelf'] },
]

// A working copy. Nothing here touches the live map until Save.
const name = ref(props.map.name)
const width = ref(props.map.width)
const height = ref(props.map.height)
const tiles = ref<string[]>([...props.map.tiles])
const zones = ref<SpaceZone[]>(props.map.zones.map(z => ({ ...z })))
const objects = ref<SpaceObject[]>((props.map.objects ?? []).map(o => ({ ...o })))
const spawn = ref({ ...props.map.spawn })

// Decorate mode has no ground brush, so it opens on the furniture; the owner opens on the wall.
const tool = ref<Tool>(props.mode === 'decor' ? 'decor' : 'tile')
/** Which tile character the ground brush paints, and which kind the furniture brush places. */
const tile = ref<string>('#')
const decor = ref<string>('speaker')
const saving = ref(false)
const error = ref('')
/** Set when a piece can't go where you clicked, and cleared as soon as one can. */
const refused = ref('')

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const camera = reactive<Camera>({ x: 0, y: 0, zoom: 1, width: 0, height: 0 })

/**
 * Swapping the room for one of the starting layouts.
 *
 * The same list the channel-creation page picks from, and the reason a room isn't stuck with
 * whatever it was born as: you can start over from Office, or turn the place into a gym, without
 * deleting the channel. Owner-only, like every other geometry change — the panel is hidden in
 * decorate mode, and the save it feeds is the owner's endpoint either way.
 *
 * Behind a toggle rather than always open, because it *replaces the room* and shouldn't be one
 * stray click away in a panel you're using to paint.
 */
const { presets, load: loadPresets, loading: loadingPresets } = useSpacePresets()
const showPresets = ref(false)
/** Which preset was last loaded, so the panel can show what you're now working from. */
const loadedPreset = ref<string | null>(null)

/** An in-progress zone drag, in tiles. */
let zoneDrag: { x0: number, y0: number, x1: number, y1: number } | null = null
let painting = false
let frame: number | undefined
let ro: ResizeObserver | undefined
let openedAt = performance.now()

/** The working map, in the shape the renderer and the API both want. */
const draft = computed<SpaceMap>(() => ({
  id: props.map.id,
  channel_id: props.map.channel_id,
  name: name.value,
  width: width.value,
  height: height.value,
  tiles: tiles.value,
  zones: zones.value,
  objects: objects.value,
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

  if (tool.value === 'tile') return setTile(x, y, tile.value)
  if (tool.value === 'decor') return placeDecor(x, y)

  if (tool.value === 'spawn') {
    // The entrance has to be somewhere you can stand — the API refuses otherwise, and being
    // told so on save is a worse way to find out than simply not being able to place it.
    if (isWalkable(draft.value, x, y)) spawn.value = { x, y }

    return
  }

  if (tool.value === 'erase-zone') {
    zones.value = zones.value.filter(z => !(x >= z.x && x < z.x + z.w && y >= z.y && y < z.y + z.h))
  }

  if (tool.value === 'erase-decor') {
    objects.value = objects.value.filter((o) => {
      const kind = DECOR[o.kind]

      return !kind || !decorCovers(o, kind, x, y)
    })
  }
}

/**
 * Put a piece of furniture down, or say why not.
 *
 * The three rules are the server's three rules, checked here so the answer is immediate: it has
 * to fit on the map, it has to be on the right sort of tile for how it's mounted, and two solid
 * things can't share a square. Overlapping a *flat* thing is fine and rather the point — a desk
 * on a rug, a chair tucked under it.
 */
function placeDecor(x: number, y: number) {
  const kind = DECOR[decor.value]
  if (!kind) return

  if (x < 0 || y < 0 || x + kind.w > width.value || y + kind.h > height.value) {
    return refuse('It doesn’t fit there.')
  }

  for (let dy = y; dy < y + kind.h; dy++) {
    for (let dx = x; dx < x + kind.w; dx++) {
      const ground = tiles.value[dy]?.[dx] ?? ' '

      if (kind.mount === 'wall' && isWalkableTile(ground)) {
        return refuse(`A ${kind.label.toLowerCase()} has to hang on a wall.`)
      }

      if (kind.mount === 'floor' && !isWalkableTile(ground)) {
        return refuse(`A ${kind.label.toLowerCase()} has to stand on the floor.`)
      }

      if (kind.solid && objects.value.some((o) => {
        const other = DECOR[o.kind]

        return other?.solid && decorCovers(o, other, dx, dy)
      })) {
        return refuse('Something’s already there.')
      }
    }
  }

  refused.value = ''
  objects.value = [...objects.value, {
    id: `d-${Date.now().toString(36)}-${objects.value.length}`,
    kind: decor.value,
    x,
    y,
  }]
}

function refuse(why: string) {
  refused.value = why
}

function onPointerDown(e: PointerEvent) {
  const rect = canvas.value!.getBoundingClientRect()
  const px = e.clientX - rect.left
  const py = e.clientY - rect.top

  canvas.value?.setPointerCapture(e.pointerId)

  if (tool.value === 'zone') {
    const t = toTile(camera, px, py)
    zoneDrag = { x0: t.x, y0: t.y, x1: t.x, y1: t.y }

    return
  }

  // Furniture is placed one click at a time. Dragging a brush across the floor would leave a
  // trail of forty desks behind it, which is never what anybody meant.
  painting = tool.value !== 'decor'
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
 * Zones, furniture and the entrance are all pulled back inside, since a grid that shrinks past
 * them would otherwise produce a map the API rejects for reasons that aren't visible on screen.
 */
function applySize() {
  width.value = clampSize(width.value)
  height.value = clampSize(height.value)
  tiles.value = resizeTiles(tiles.value, width.value, height.value)
  zones.value = zones.value.filter(z => z.x + z.w <= width.value && z.y + z.h <= height.value)
  objects.value = objects.value.filter((o) => {
    const kind = DECOR[o.kind]

    return kind && o.x + kind.w <= width.value && o.y + kind.h <= height.value
  })

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
      if (isWalkable(draft.value, x, y)) return { x, y }
    }
  }

  return null
}

function clearRoom() {
  tiles.value = blankTiles(width.value, height.value)
  zones.value = []
  objects.value = []
  spawn.value = { x: Math.floor(width.value / 2), y: Math.floor(height.value / 2) }
}

// --- loading a preset over the room ---

function togglePresets() {
  showPresets.value = !showPresets.value
  if (showPresets.value) void loadPresets()
}

/**
 * Replace the working room with a starting layout — its size, ground, rooms, furniture and
 * entrance, all of it.
 *
 * Deliberately *everything but the name*. A preset is a room, not a title: somebody who called
 * this place "Design standup" and then loads Office to re-floor it shouldn't find it renamed
 * "Office" underneath them. The name field is right there in the header if they do want it.
 *
 * Local until Save, like every other edit here — so Cancel still backs out of the whole swap, and
 * nobody standing in the room sees anything until it's saved.
 */
function applyPreset(preset: MapPreset) {
  width.value = preset.width
  height.value = preset.height
  // Copied, never referenced: these live in a session-wide cache (see useSpacePresets), and
  // painting a wall must not edit the catalogue every other picker reads from.
  tiles.value = [...preset.tiles]
  zones.value = preset.zones.map(z => ({ ...z }))
  objects.value = preset.objects.map(o => ({ ...o }))
  spawn.value = { ...preset.spawn }

  loadedPreset.value = preset.key
  refused.value = ''
  showPresets.value = false

  // The new room is a different size, so the camera has to re-frame it.
  fit()
}

// --- saving ---

async function onSave() {
  saving.value = true
  error.value = ''

  try {
    // Decorate mode saves the furniture alone, through the member endpoint. It's not that the
    // rest wouldn't validate — it's that a member has no right to send it, and doing so would
    // 403 rather than quietly ignore the geometry.
    if (isDecorMode.value) {
      await saveObjects(objects.value)
    }
    else {
      await save({
        name: name.value,
        width: width.value,
        height: height.value,
        tiles: tiles.value,
        zones: zones.value,
        objects: objects.value,
        spawn: spawn.value,
      })
    }
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
    zone: 'rgb(99 102 241 / 0.10)',
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
  drawMap(ctx, draft.value, camera, p, (performance.now() - openedAt) / 1000)

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

/** Choosing anything clears the last refusal — it was about the last thing you tried. */
function chooseTile(char: string) {
  tool.value = 'tile'
  tile.value = char
  refused.value = ''
}

function chooseDecor(kindName: string) {
  tool.value = 'decor'
  decor.value = kindName
  refused.value = ''
}

function chooseTool(id: Tool) {
  tool.value = id
  refused.value = ''
}

watch([width, height], () => fit())

onMounted(() => {
  openedAt = performance.now()
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
          {{ isDecorMode ? 'Decorate the room' : 'Edit the room' }}
          <AlphaBadge hint="The editor is new — save often, and tell us what it gets wrong." />
        </span>
        <!-- Renaming is part of rebuilding the room, so it's the owner's alone. -->
        <Input v-if="!isDecorMode" v-model="name" class="h-8 w-48" placeholder="Room name" />
        <span v-else class="text-sm text-muted-foreground">{{ name }}</span>
      </div>

      <div class="flex shrink-0 items-center gap-2">
        <p v-if="error" class="max-w-sm truncate text-xs text-destructive" :title="error">{{ error }}</p>
        <Button variant="outline" size="sm" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button size="sm" :disabled="saving" @click="onSave">
          <Loader2 v-if="saving" class="mr-1.5 h-4 w-4 animate-spin" />
          {{ saving ? 'Saving…' : (isDecorMode ? 'Save furniture' : 'Save room') }}
        </Button>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>
    </header>

    <div class="flex min-h-0 flex-1">
      <!-- Tools -->
      <aside class="w-64 shrink-0 space-y-4 overflow-y-auto border-r p-3">
        <!-- The ground. Owner only — a member decorating can't repaint the floor. -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Ground</Label>
          <div class="grid grid-cols-4 gap-1.5">
            <button
              v-for="b in TILE_BRUSHES"
              :key="b.tile"
              type="button"
              class="flex flex-col items-center gap-1 rounded-md border p-1.5 transition-colors"
              :class="tool === 'tile' && tile === b.tile ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              :title="b.hint"
              @click="chooseTile(b.tile)"
            >
              <span
                class="h-6 w-6 rounded-sm border"
                :style="{ backgroundColor: b.swatch }"
              />
              <span class="text-[9px] leading-none">{{ b.label }}</span>
            </button>
          </div>
        </div>

        <!-- The furniture. -->
        <div class="space-y-1.5">
          <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Sofa class="h-3.5 w-3.5" /> Furniture
          </Label>

          <div v-for="group in DECOR_GROUPS" :key="group.title" class="space-y-1">
            <p class="text-[10px] uppercase tracking-wide text-muted-foreground/70">{{ group.title }}</p>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="k in group.kinds"
                :key="k"
                type="button"
                class="rounded border px-1.5 py-1 text-[11px] transition-colors"
                :class="tool === 'decor' && decor === k ? 'border-primary bg-muted font-medium' : 'hover:bg-muted/50'"
                :title="DECOR[k]?.interact ? `${DECOR[k]?.label} — opens the ${DECOR[k]?.interact}` : DECOR[k]?.label"
                @click="chooseDecor(k)"
              >
                {{ DECOR[k]?.label }}
                <span v-if="DECOR[k]?.interact" class="text-primary">·</span>
              </button>
            </div>
          </div>

          <p class="text-[11px] leading-snug text-muted-foreground">
            Things marked <span class="text-primary">·</span> open something when somebody walks up and presses E.
          </p>
        </div>

        <!-- Everything that isn't a brush. -->
        <div class="space-y-1.5">
          <Label class="text-xs text-muted-foreground">Tools</Label>
          <button
            v-for="t in TOOLS"
            :key="t.id"
            type="button"
            class="flex w-full items-start gap-2 rounded-md border p-2 text-left text-sm transition-colors"
            :class="tool === t.id ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
            @click="chooseTool(t.id)"
          >
            <component :is="t.icon" class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span>
              <span class="block font-medium">{{ t.label }}</span>
              <span class="block text-[11px] leading-snug text-muted-foreground">{{ t.hint }}</span>
            </span>
          </button>
        </div>

        <div v-if="!isDecorMode" class="space-y-1.5">
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

        <div v-if="!isDecorMode && zones.length" class="space-y-1.5">
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

        <!--
          Swap the whole room for one of the starting layouts. Behind a toggle because it replaces
          everything: a picker sitting open next to the brushes would be one stray click from
          wiping a floor somebody just laid.
        -->
        <div v-if="!isDecorMode" class="space-y-1.5">
          <Button variant="outline" size="sm" class="w-full gap-1.5" @click="togglePresets">
            <LayoutTemplate class="h-3.5 w-3.5" />
            {{ showPresets ? 'Close layouts' : 'Load a layout' }}
          </Button>

          <div v-if="showPresets" class="space-y-1.5 rounded-md border p-2">
            <p class="text-[11px] leading-snug text-muted-foreground">
              Replaces the size, ground, rooms, furniture and entrance — everything but the name.
              Nothing is saved until you hit Save room.
            </p>

            <p v-if="loadingPresets" class="flex items-center gap-1.5 py-1 text-xs text-muted-foreground">
              <Loader2 class="h-3.5 w-3.5 animate-spin" /> Loading layouts…
            </p>

            <button
              v-for="p in presets"
              :key="p.key"
              type="button"
              class="w-full space-y-1 rounded-md border p-1.5 text-left transition-colors"
              :class="loadedPreset === p.key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
              :title="p.description"
              @click="applyPreset(p)"
            >
              <!-- The real grid, so what you pick is what you get. -->
              <SideSpaceMapThumbnail :tiles="p.tiles" :width="p.width" :height="p.height" />
              <span class="block text-xs font-medium">{{ p.label }}</span>
              <span class="block text-[10px] leading-snug text-muted-foreground">{{ p.width }}×{{ p.height }}</span>
            </button>
          </div>
        </div>

        <Button v-if="!isDecorMode" variant="outline" size="sm" class="w-full gap-1.5" @click="clearRoom">
          <Trash2 class="h-3.5 w-3.5" /> Start over
        </Button>

        <p v-else class="text-[11px] leading-snug text-muted-foreground">
          You're decorating — add and move furniture. Only the room's owner can change the walls,
          floor and the way in.
        </p>
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
          {{ tool === 'decor' ? 'Click to put it down. Furniture places one at a time.'
            : tool === 'erase-decor' ? 'Click a piece of furniture to remove it.'
              : 'Drag to paint. The green square is where people walk in.' }}
        </p>
        <p
          v-if="refused"
          class="pointer-events-none absolute bottom-2 left-1/2 -translate-x-1/2 rounded bg-destructive px-2 py-1 text-[11px] text-destructive-foreground"
        >
          {{ refused }}
        </p>
        <p class="pointer-events-none absolute right-2 top-2 rounded bg-background/85 px-2 py-1 text-[11px] text-muted-foreground">
          {{ objects.length }} thing{{ objects.length === 1 ? '' : 's' }} in the room
        </p>
      </div>
    </div>
  </div>
</template>
