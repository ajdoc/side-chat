<script setup lang="ts">
import type { Camera, Occupant, SpaceMap } from '~/lib/spaceMapEngine'
import { Map as MapIcon, X } from 'lucide-vue-next'
import { backdropImage } from '~/lib/spaceBackdrops'
import { toWorld } from '~/lib/spaceMapEngine'
import { isWalkableTile } from '~/lib/spaceTiles'

/**
 * A small picture of the whole room, with everybody's position on it.
 *
 * ## Why a room this size needs one
 *
 * It didn't, until maps stopped being one screen. An office is entirely visible at the zoom the
 * stage picks for itself, so a second smaller copy of it would be decoration. A sixty-four tile
 * city is not: the camera follows you at walking pace, most of the map is off-screen at any
 * moment, and without an overview "where is everyone" and "which way is the park" have no answer
 * except walking until you find out.
 *
 * ## How it's drawn
 *
 * Two ways, and the good one is nearly free. A map with {@link file://../lib/spaceBackdrops.ts
 * backdrop} artwork is drawn by scaling that artwork down — it is already a picture of the whole
 * map from directly above, which is exactly what a minimap is, and the browser has it cached
 * because the room is drawing it too. A tile-built map is drawn as flat colour per tile instead:
 * walkable, solid, water. Legible rather than pretty, which is the right trade for a room whose
 * ground is a hundred rectangles of code.
 *
 * ## Refresh rate
 *
 * Twelve times a second, not sixty. Everything here moves at walking pace and the dots are three
 * pixels across, so the extra forty-eight frames are invisible — and this sits on top of a canvas
 * that genuinely does need sixty, on the same thread.
 */
const props = defineProps<{
  map: SpaceMap
  occupants: Occupant[]
  meId: number | null
  /** The stage's camera, for drawing the slice of the map currently on screen. */
  camera: Camera
}>()

const OPEN_KEY = 'space:minimap'

const open = ref(true)
const canvas = ref<HTMLCanvasElement | null>(null)

/**
 * The ground, drawn once and kept.
 *
 * The map only changes when somebody saves the editor, which is rare; the dots on top of it move
 * constantly. Redrawing the ground every frame therefore does the same work over and over for a
 * picture that is identical each time — and that work is *per tile*, so it grows with the square
 * of the map. At the 256-tile ceiling that is 65,536 tiles twelve times a second, on the same
 * thread as a room that needs sixty frames. Cached, the per-frame cost is one `drawImage`
 * whatever the map's size.
 */
let ground: HTMLCanvasElement | null = null
let groundOf: { tiles: unknown, backdrops: unknown, w: number, h: number, loaded: boolean } | null = null

/** How wide the picture is, in CSS pixels. The height follows the map's own proportions. */
const WIDTH = 176

const height = computed(() => {
  const ratio = props.map.height / Math.max(1, props.map.width)

  // Capped, so a tall narrow map doesn't grow a minimap the height of the room it's sitting on.
  return Math.round(Math.min(WIDTH * ratio, 200))
})

/*
 * The palette. Deliberately not the room's own tile colours: at one pixel per tile a faithful
 * miniature of grass and paving is a grey-green mush. These are three flat, high-contrast inks
 * that answer the only question a minimap is asked — can I walk there.
 */
const INK = {
  walkable: '#cbd5e1',
  solid: '#475569',
  water: '#1e3a5f',
  out: '#0f172a',
}

function draw() {
  const el = canvas.value
  const ctx = el?.getContext('2d')
  if (!el || !ctx) return

  const dpr = Math.min(2, window.devicePixelRatio || 1)
  const w = WIDTH
  const h = height.value

  if (el.width !== Math.round(w * dpr)) {
    el.width = Math.round(w * dpr)
    el.height = Math.round(h * dpr)
  }

  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, w, h)
  ctx.fillStyle = INK.out
  ctx.fillRect(0, 0, w, h)

  const sx = w / props.map.width
  const sy = h / props.map.height

  // One blit, whatever the map's size — the tile loop lives in paintGround and runs only when
  // the map itself changes.
  ctx.drawImage(paintGround(w, h, sx, sy), 0, 0, w, h)

  /*
   * The slice of the map currently on screen.
   *
   * Unprojected from the stage's own camera rather than worked out from the zoom, so it stays
   * correct under both projections — under `iso` the visible region is a diamond and its
   * bounding box is what this draws.
   */
  const corners = [
    toWorld(props.camera, 0, 0),
    toWorld(props.camera, props.camera.width, 0),
    toWorld(props.camera, 0, props.camera.height),
    toWorld(props.camera, props.camera.width, props.camera.height),
  ]

  const vx0 = Math.min(...corners.map(c => c.x))
  const vy0 = Math.min(...corners.map(c => c.y))
  const vx1 = Math.max(...corners.map(c => c.x))
  const vy1 = Math.max(...corners.map(c => c.y))

  ctx.strokeStyle = 'rgb(255 255 255 / 0.55)'
  ctx.lineWidth = 1
  ctx.strokeRect(vx0 * sx, vy0 * sy, (vx1 - vx0) * sx, (vy1 - vy0) * sy)

  // People. Everybody else first, so your own dot is never hidden under somebody standing on you.
  for (const person of props.occupants) {
    if (person.id === props.meId) continue

    ctx.fillStyle = '#f8fafc'
    ctx.beginPath()
    ctx.arc(person.x * sx, person.y * sy, 2, 0, Math.PI * 2)
    ctx.fill()
  }

  const me = props.occupants.find(o => o.id === props.meId)
  if (me) {
    // Ringed as well as coloured: a room where everyone is a dot needs *you* findable at a glance,
    // and colour alone fails the people most likely to be scanning a map for themselves.
    ctx.fillStyle = '#38bdf8'
    ctx.strokeStyle = '#0f172a'
    ctx.lineWidth = 1.5
    ctx.beginPath()
    ctx.arc(me.x * sx, me.y * sy, 3.2, 0, Math.PI * 2)
    ctx.fill()
    ctx.stroke()
  }
}

/**
 * Paint the artwork and the tiles onto an offscreen canvas, reusing the last one if nothing moved.
 *
 * Freshness is decided by the *identity* of the tiles and backdrops arrays rather than their
 * contents: everything that edits a map replaces those arrays wholesale, so a reference check is
 * both cheap and exact where comparing 65,536 characters would be neither. It also tracks whether
 * the artwork has finished loading, or a map cached in the moment before the image arrived would
 * keep its blank ground for good.
 */
function paintGround(w: number, h: number, sx: number, sy: number): HTMLCanvasElement {
  const placements = props.map.backdrops ?? []
  const loaded = placements.every(p => backdropImage(p.key))

  const same = ground && groundOf
    && groundOf.tiles === props.map.tiles
    && groundOf.backdrops === props.map.backdrops
    && groundOf.w === w && groundOf.h === h
    && groundOf.loaded === loaded

  if (same) return ground!

  const dpr = Math.min(2, window.devicePixelRatio || 1)
  ground ??= document.createElement('canvas')
  ground.width = Math.round(w * dpr)
  ground.height = Math.round(h * dpr)
  groundOf = { tiles: props.map.tiles, backdrops: props.map.backdrops, w, h, loaded }

  const g = ground.getContext('2d')!
  g.setTransform(dpr, 0, 0, dpr, 0, 0)
  g.fillStyle = INK.out
  g.fillRect(0, 0, w, h)

  // Artwork first. Each placement is scaled into the rectangle of tiles it covers, so a map that
  // is half hand-built room and half city shows both.
  const covered: Array<{ x: number, y: number, w: number, h: number }> = []
  g.imageSmoothingEnabled = true

  for (const placement of placements) {
    const img = backdropImage(placement.key)
    if (!img) continue

    g.drawImage(img, placement.x * sx, placement.y * sy, placement.w * sx, placement.h * sy)
    covered.push(placement)
  }

  // Then the tiles, for everywhere the artwork doesn't reach.
  for (let y = 0; y < props.map.height; y++) {
    for (let x = 0; x < props.map.width; x++) {
      if (covered.some(c => x >= c.x && x < c.x + c.w && y >= c.y && y < c.y + c.h)) continue

      const tile = props.map.tiles[y]?.[x] ?? '#'
      if (tile === ' ') continue

      g.fillStyle = tile === '~' ? INK.water : (isWalkableTile(tile) ? INK.walkable : INK.solid)
      g.fillRect(x * sx, y * sy, Math.ceil(sx), Math.ceil(sy))
    }
  }

  return ground
}

/*
 * A throttled frame loop rather than watchers.
 *
 * Positions arrive as peer whispers many times a second and are held in a plain reactive object;
 * watching them deeply to schedule a repaint would cost more than the repaint. A timer that draws
 * the current state twelve times a second is both cheaper and simpler, and it stops entirely when
 * the map is folded away.
 */
let frame: number | undefined
let last = 0

function loop(now: number) {
  frame = requestAnimationFrame(loop)

  if (now - last < 1000 / 12) return
  last = now
  draw()
}

watch(open, (isOpen) => {
  if (import.meta.client) localStorage.setItem(OPEN_KEY, isOpen ? '1' : '0')

  if (!isOpen) {
    if (frame) cancelAnimationFrame(frame)
    frame = undefined

    return
  }

  // The canvas only exists once v-if has rendered it.
  void nextTick(() => {
    if (!frame) frame = requestAnimationFrame(loop)
  })
})

onMounted(() => {
  open.value = localStorage.getItem(OPEN_KEY) !== '0'
  if (open.value) frame = requestAnimationFrame(loop)
})

onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
})
</script>

<template>
  <div class="pointer-events-auto">
    <button
      v-if="!open"
      type="button"
      class="flex h-8 w-8 items-center justify-center rounded-lg border bg-background/90 text-muted-foreground shadow-sm backdrop-blur transition-colors hover:bg-muted hover:text-foreground"
      title="Show the map"
      aria-label="Show the map"
      @click="open = true"
    >
      <MapIcon class="h-4 w-4" />
    </button>

    <div v-else class="overflow-hidden rounded-lg border bg-background/90 shadow-sm backdrop-blur">
      <div class="flex items-center justify-between gap-2 border-b px-2 py-1">
        <span class="truncate text-[11px] font-medium text-muted-foreground">{{ map.name }}</span>
        <button
          type="button"
          class="text-muted-foreground transition-colors hover:text-foreground"
          title="Hide the map"
          aria-label="Hide the map"
          @click="open = false"
        >
          <X class="h-3.5 w-3.5" />
        </button>
      </div>

      <canvas ref="canvas" :style="{ width: `${WIDTH}px`, height: `${height}px` }" class="block" />
    </div>
  </div>
</template>
