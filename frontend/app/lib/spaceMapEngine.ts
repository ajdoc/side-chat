/**
 * The geometry half of a Side Space — the walkable room, its collisions, and the rule that
 * decides how loudly you hear somebody.
 *
 * Framework-agnostic on purpose, like {@link file://./whiteboardEngine.ts whiteboardEngine} and
 * the game engines: the Vue component feeds it a canvas and pointer input, and everything that
 * can be reasoned about without a browser lives here. That matters more than usual for this
 * one, because {@link audibility} runs for every person in the room on every animation frame
 * and *is* the feature — a bug in it is somebody being silently unhearable.
 *
 * ## Coordinates
 *
 * Two systems, and keeping them apart is most of the work:
 *
 *   - **Tiles** — integers. The map is a grid; walls, zones and spawn are all in tiles, and
 *     `audibility` measures distance in them. This is the shared, authoritative space.
 *   - **Pixels** — tiles × {@link TILE}, offset by the camera. Purely local, purely for
 *     drawing. Nothing is ever sent in pixels.
 *
 * A person's position is a *float* in tile space, because they slide between tiles rather than
 * teleporting. Collision therefore tests the tile they are moving *into*, rounded.
 */

/** One tile, in pixels, before the camera's zoom. */
export const TILE = 32

export const FLOOR = '.'
export const WALL = '#'
export const VOID = ' '

/**
 * How far sound carries, in tiles.
 *
 * Three radii, not two, and the third is the one that matters for cost:
 *
 *   - Inside `NEAR_TILES` you're at full volume — close enough to be "in a conversation",
 *     and flat rather than falling off, so shuffling about doesn't modulate your voice.
 *   - Between there and `FAR_TILES` you fade out. This is the band that makes a room feel
 *     like a room.
 *   - `CONNECT_TILES` sits *past* silence, and is where the WebRTC connection itself is made
 *     and dropped. The gap between it and `FAR_TILES` is hysteresis: someone pacing on the
 *     edge of audibility crosses in and out of hearing (free — it's a volume assignment)
 *     without repeatedly tearing down and re-negotiating a peer connection (not free at all).
 */
export const NEAR_TILES = 2
export const FAR_TILES = 8
export const CONNECT_TILES = 10

export type Facing = 'up' | 'down' | 'left' | 'right'

export interface SpaceZone {
  id: string
  name: string
  /** Only one kind so far: a sealed room. Sound neither leaves it nor gets in. */
  kind: 'private'
  x: number
  y: number
  w: number
  h: number
}

export interface SpaceMap {
  id: number
  channel_id: number
  name: string
  width: number
  height: number
  /** `height` rows of `width` characters. See FLOOR / WALL / VOID. */
  tiles: string[]
  zones: SpaceZone[]
  spawn: { x: number, y: number }
  updated_by?: string | null
  updated_at?: string
}

/** Somebody standing in the room. Position is in tiles, and fractional while they move. */
export interface Occupant {
  id: number
  name: string
  avatar: string | null
  x: number
  y: number
  facing: Facing
}

// --- the grid ---

export function tileAt(map: SpaceMap, x: number, y: number): string {
  return map.tiles[y]?.[x] ?? WALL
}

/**
 * Can somebody stand here?
 *
 * Off-map counts as solid, which is what lets the edge of the map need no special case
 * anywhere else — walking off the top of the world is the same event as walking into a wall.
 */
export function isWalkable(map: SpaceMap, x: number, y: number): boolean {
  return tileAt(map, Math.round(x), Math.round(y)) === FLOOR
}

/** The zone containing a tile, or null out in the open. First match wins. */
export function zoneAt(map: SpaceMap, x: number, y: number): SpaceZone | null {
  const tx = Math.round(x)
  const ty = Math.round(y)

  for (const z of map.zones ?? []) {
    if (tx >= z.x && tx < z.x + z.w && ty >= z.y && ty < z.y + z.h) return z
  }

  return null
}

/** Where to put somebody with no remembered position — falling back to any floor tile. */
export function spawnPoint(map: SpaceMap): { x: number, y: number } {
  if (isWalkable(map, map.spawn.x, map.spawn.y)) return { x: map.spawn.x, y: map.spawn.y }

  for (let y = 0; y < map.height; y++) {
    for (let x = 0; x < map.width; x++) {
      if (isWalkable(map, x, y)) return { x, y }
    }
  }

  return { x: 0, y: 0 }
}

// --- proximity ---

/**
 * How loudly `a` hears `b`, from 0 (silence) to 1 (full volume).
 *
 * The whole feature, in one function. Three rules, in order:
 *
 * 1. **Zones win over distance.** Two people in the same private zone hear each other fully,
 *    however far apart they stand in it — that's what a meeting room is *for*. And if exactly
 *    one of them is in a zone, they hear nothing of each other regardless of how close they
 *    are, because a sealed room that leaks to somebody standing against the outside of its
 *    wall is not sealed. Different zones: likewise nothing.
 * 2. **Inside `NEAR_TILES`, full volume.** Flat, so a conversation doesn't wobble as people
 *    shift about in it.
 * 3. **Beyond that, fade to nothing at `FAR_TILES`.** Squared rather than linear, so the fall
 *    is gentle where you're still in earshot and steep as you leave — which sounds like
 *    walking away from someone, whereas a linear ramp sounds like a fader being pulled.
 *
 * Symmetric by construction: both ends compute the same number from the same two positions,
 * which is what lets each side gate its *own* connection without either negotiating.
 */
export function audibility(map: SpaceMap, a: { x: number, y: number }, b: { x: number, y: number }): number {
  const za = zoneAt(map, a.x, a.y)
  const zb = zoneAt(map, b.x, b.y)

  if (za || zb) return za && zb && za.id === zb.id ? 1 : 0

  const d = distance(a, b)
  if (d <= NEAR_TILES) return 1
  if (d >= FAR_TILES) return 0

  const t = (d - NEAR_TILES) / (FAR_TILES - NEAR_TILES)

  return (1 - t) ** 2
}

/**
 * Should a peer connection to `b` be open at all?
 *
 * Not simply `audibility > 0`: a shared zone means "always", and out in the open it uses the
 * wider `CONNECT_TILES` so the connection outlives the silence by a couple of tiles. See the
 * radii above for why that gap exists.
 */
export function inConnectRange(map: SpaceMap, a: { x: number, y: number }, b: { x: number, y: number }): boolean {
  const za = zoneAt(map, a.x, a.y)
  const zb = zoneAt(map, b.x, b.y)

  if (za || zb) return !!(za && zb && za.id === zb.id)

  return distance(a, b) <= CONNECT_TILES
}

export function distance(a: { x: number, y: number }, b: { x: number, y: number }): number {
  return Math.hypot(a.x - b.x, a.y - b.y)
}

// --- drawing ---

export interface Camera {
  /** Tile coordinates at the centre of the view. */
  x: number
  y: number
  /** Pixels per tile = TILE * zoom. */
  zoom: number
  /** Canvas size in CSS pixels. */
  width: number
  height: number
}

/** Tile coordinates → canvas pixels. */
export function toScreen(cam: Camera, x: number, y: number): { x: number, y: number } {
  const size = TILE * cam.zoom

  return {
    x: (x - cam.x) * size + cam.width / 2,
    y: (y - cam.y) * size + cam.height / 2,
  }
}

/** Canvas pixels → tile coordinates. The inverse of {@link toScreen}, for the editor's brush. */
export function toTile(cam: Camera, px: number, py: number): { x: number, y: number } {
  const size = TILE * cam.zoom

  return {
    x: Math.floor((px - cam.width / 2) / size + cam.x + 0.5),
    y: Math.floor((py - cam.height / 2) / size + cam.y + 0.5),
  }
}

/** The palette, taken as CSS colours so the caller can hand us theme-resolved values. */
export interface MapTheme {
  floor: string
  floorAlt: string
  wall: string
  wallTop: string
  zone: string
  zoneBorder: string
  text: string
  muted: string
}

/**
 * Paint the room: floors, walls, zones.
 *
 * Only the tiles the camera can actually see are visited — a 80×80 map is 6400 tiles, and
 * drawing all of them 60 times a second to show the fifth of them on screen is the kind of
 * waste that only shows up on somebody else's laptop.
 */
export function drawMap(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, theme: MapTheme): void {
  const size = TILE * cam.zoom
  const cols = Math.ceil(cam.width / size / 2) + 1
  const rows = Math.ceil(cam.height / size / 2) + 1

  const x0 = Math.max(0, Math.floor(cam.x) - cols)
  const x1 = Math.min(map.width - 1, Math.ceil(cam.x) + cols)
  const y0 = Math.max(0, Math.floor(cam.y) - rows)
  const y1 = Math.min(map.height - 1, Math.ceil(cam.y) + rows)

  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const tile = tileAt(map, x, y)
      if (tile === VOID) continue

      const p = toScreen(cam, x - 0.5, y - 0.5)

      if (tile === FLOOR) {
        // A checker, faint enough not to be a pattern you notice — but present, because a
        // featureless floor gives you nothing to judge your own movement against.
        ctx.fillStyle = (x + y) % 2 === 0 ? theme.floor : theme.floorAlt
        ctx.fillRect(p.x, p.y, size + 1, size + 1)
      }
      else {
        // Walls get a lighter cap so the grid reads as having height rather than as a
        // flat blocked-out square.
        ctx.fillStyle = theme.wall
        ctx.fillRect(p.x, p.y, size + 1, size + 1)
        ctx.fillStyle = theme.wallTop
        ctx.fillRect(p.x, p.y, size + 1, Math.max(2, size * 0.22))
      }
    }
  }

  drawZones(ctx, map, cam, theme)
}

/** Zones, as a tinted rectangle with its name along the top edge. */
function drawZones(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, theme: MapTheme): void {
  const size = TILE * cam.zoom

  for (const z of map.zones ?? []) {
    const p = toScreen(cam, z.x - 0.5, z.y - 0.5)
    const w = z.w * size
    const h = z.h * size

    ctx.fillStyle = theme.zone
    ctx.fillRect(p.x, p.y, w, h)

    ctx.strokeStyle = theme.zoneBorder
    ctx.lineWidth = 2
    ctx.setLineDash([6, 4])
    ctx.strokeRect(p.x + 1, p.y + 1, w - 2, h - 2)
    ctx.setLineDash([])

    ctx.fillStyle = theme.muted
    ctx.font = `500 ${Math.max(10, size * 0.34)}px system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'top'
    ctx.fillText(z.name, p.x + w / 2, p.y + 4, w - 8)
  }
}

/**
 * The soft ring showing how far your voice carries — drawn under everyone, for yourself only.
 *
 * Proximity audio is invisible otherwise, and a room where you cannot tell who can hear you is
 * a room people are wary of talking in. This is the affordance that makes the rule legible:
 * the solid inner edge is full volume, the fade is the falloff.
 *
 * Both ends of the gradient are passed in, and they must be the same colour at different
 * alphas. Canvas interpolates gradients in premultiplied space, so fading to the keyword
 * `transparent` — which is transparent *black* — drags a grey bruise through the middle of the
 * ramp on some engines. Fading to the same hue at zero alpha doesn't.
 */
export function drawEarshot(
  ctx: CanvasRenderingContext2D,
  cam: Camera,
  at: { x: number, y: number },
  from: string,
  to: string,
): void {
  const size = TILE * cam.zoom
  const p = toScreen(cam, at.x, at.y)

  const gradient = ctx.createRadialGradient(p.x, p.y, NEAR_TILES * size, p.x, p.y, FAR_TILES * size)
  gradient.addColorStop(0, from)
  gradient.addColorStop(1, to)

  ctx.fillStyle = gradient
  ctx.beginPath()
  ctx.arc(p.x, p.y, FAR_TILES * size, 0, Math.PI * 2)
  ctx.fill()
}

/**
 * Move a step, refusing to walk through walls.
 *
 * Axis-separated: a diagonal that's blocked on one axis still slides along the other, so
 * running at a wall at an angle glides along it instead of stopping dead. It's the difference
 * between a room that feels navigable and one that feels sticky.
 */
export function step(map: SpaceMap, from: { x: number, y: number }, dx: number, dy: number): { x: number, y: number } {
  let { x, y } = from

  if (dx !== 0 && isWalkable(map, x + dx, y)) x += dx
  if (dy !== 0 && isWalkable(map, x, y + dy)) y += dy

  return { x, y }
}

/** Which way a step faces. Vertical wins ties, arbitrarily but consistently. */
export function facingOf(dx: number, dy: number, fallback: Facing): Facing {
  if (dy < 0) return 'up'
  if (dy > 0) return 'down'
  if (dx < 0) return 'left'
  if (dx > 0) return 'right'

  return fallback
}

// --- the trainer sprite ---

/**
 * People are drawn as little pixel-art trainers, in the spirit of a Game Boy overworld: a
 * 16×16 grid, four facings, and a two-frame walk cycle.
 *
 * Original artwork, not lifted from anywhere — it's the *idiom* that's borrowed (chunky
 * outline, cap, side view narrower than the front) rather than any particular character.
 *
 * The grid is written as strings because that is the only way pixel art stays legible in a
 * source file; each character is a palette slot:
 *
 *   `.` transparent   `o` outline      `C` cap and shirt (this person's colour)
 *   `S` skin          `E` eye          `H` hair (the back of the head)
 *   `K` pack straps   `P` trousers     `B` boots
 *
 * Only three grids are stored. `left` is `right` mirrored at render time, because drawing the
 * same pixels backwards is free and maintaining two copies of one sprite is not.
 */
export const SPRITE_SIZE = 16

type SpriteDir = 'down' | 'up' | 'right'

const SPRITES: Record<SpriteDir, [string[], string[]]> = {
  down: [
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCo....',
      '...ooSSSSSSoo...',
      '...oSSSSSSSSo...',
      '...oSEESSEESo...',
      '...oSSSSSSSSo...',
      '....oSSSSSSo....',
      '.....oooooo.....',
      '...oKCCCCCCKo...',
      '..oKKCCCCCCKKo..',
      '..oSKCCCCCCKSo..',
      '...ooPPPPPPoo...',
      '....oPPooPPo....',
      '....oBBooBBo....',
    ],
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCo....',
      '...ooSSSSSSoo...',
      '...oSSSSSSSSo...',
      '...oSEESSEESo...',
      '...oSSSSSSSSo...',
      '....oSSSSSSo....',
      '.....oooooo.....',
      '...oKCCCCCCKo...',
      '..oKKCCCCCCKKo..',
      '..oSKCCCCCCKSo..',
      '...ooPPPPPPoo...',
      '....oPPPPPPo....',
      '...oBBo..oBBo...',
    ],
  ],
  up: [
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCo....',
      '...ooCCCCCCoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '....oHHHHHHo....',
      '.....oooooo.....',
      '...oKCCCCCCKo...',
      '..oKKCCCCCCKKo..',
      '..oSKCCCCCCKSo..',
      '...ooPPPPPPoo...',
      '....oPPooPPo....',
      '....oBBooBBo....',
    ],
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCo....',
      '...ooCCCCCCoo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '...oHHHHHHHHo...',
      '....oHHHHHHo....',
      '.....oooooo.....',
      '...oKCCCCCCKo...',
      '..oKKCCCCCCKKo..',
      '..oSKCCCCCCKSo..',
      '...ooPPPPPPoo...',
      '....oPPPPPPo....',
      '...oBBo..oBBo...',
    ],
  ],
  right: [
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCCo...',
      '...ooSSSSSSo....',
      '...oHSSSSSSo....',
      '...oHSSEESSo....',
      '...oHSSSSSSo....',
      '....oSSSSSo.....',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '...oKCCCCCCo....',
      '...oKKCCCCSo....',
      '....ooPPPPo.....',
      '.....oPPPo......',
      '.....oBBBo......',
    ],
    [
      '................',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '....oCCCCCCCo...',
      '...ooSSSSSSo....',
      '...oHSSSSSSo....',
      '...oHSSEESSo....',
      '...oHSSSSSSo....',
      '....oSSSSSo.....',
      '.....oooooo.....',
      '....oCCCCCCo....',
      '...oKCCCCCCo....',
      '...oKKCCCCSo....',
      '....ooPPPPo.....',
      '....oPPPPo......',
      '...oBBo.oBBo....',
    ],
  ],
}

/**
 * Everyone gets their own shirt.
 *
 * Derived from the user id rather than stored, so it needs no column, no migration and no
 * agreement between clients — everybody computes the same hue for the same person. Fixed
 * saturation and lightness keep the whole cast looking like one set of sprites rather than a
 * bag of highlighter pens, and stop anybody drawing a colour that vanishes into the floor.
 */
export function spriteHue(userId: number): number {
  // Golden-angle stepping: consecutive ids land far apart on the wheel, so the two people most
  // likely to be in a room together are the least likely to be wearing the same colour.
  return (userId * 137.508) % 360
}

interface SpritePalette {
  o: string
  C: string
  S: string
  E: string
  H: string
  K: string
  P: string
  B: string
}

function paletteFor(hue: number, self: boolean): SpritePalette {
  return {
    o: 'rgb(28 26 38)',
    C: `hsl(${hue} 62% ${self ? 56 : 48}%)`,
    S: 'rgb(247 206 168)',
    E: 'rgb(28 26 38)',
    H: 'rgb(78 52 38)',
    K: `hsl(${hue} 45% ${self ? 38 : 32}%)`,
    P: 'rgb(58 62 96)',
    B: 'rgb(40 38 52)',
  }
}

/**
 * Pre-rendered sprites, keyed by everything that changes their pixels.
 *
 * Drawing 256 one-pixel rectangles per person per frame would be 12,800 fills a frame in a
 * room of fifty — enough to cost real milliseconds for a picture that never changes. So each
 * variant is rasterised once into its own little canvas and thereafter blitted with a single
 * `drawImage`. The cache is small and bounded: a handful of variants per person in the room.
 */
const spriteCache = new Map<string, HTMLCanvasElement>()

/** How many device pixels each sprite pixel is rasterised at. 4× survives any sane zoom. */
const SPRITE_SCALE = 4

function spriteCanvas(hue: number, dir: SpriteDir, frame: 0 | 1, self: boolean): HTMLCanvasElement {
  const key = `${Math.round(hue)}|${dir}|${frame}|${self ? 1 : 0}`
  const cached = spriteCache.get(key)
  if (cached) return cached

  const palette = paletteFor(hue, self)
  const canvas = document.createElement('canvas')
  canvas.width = SPRITE_SIZE * SPRITE_SCALE
  canvas.height = SPRITE_SIZE * SPRITE_SCALE

  const ctx = canvas.getContext('2d')!
  const rows = SPRITES[dir][frame]

  for (let y = 0; y < SPRITE_SIZE; y++) {
    const row = rows[y] ?? ''

    for (let x = 0; x < SPRITE_SIZE; x++) {
      const ch = row[x]
      if (!ch || ch === '.') continue

      const colour = palette[ch as keyof SpritePalette]
      if (!colour) continue

      ctx.fillStyle = colour
      ctx.fillRect(x * SPRITE_SCALE, y * SPRITE_SCALE, SPRITE_SCALE, SPRITE_SCALE)
    }
  }

  spriteCache.set(key, canvas)

  return canvas
}

/**
 * Draw one person, standing on their tile.
 *
 * The sprite is anchored by its *feet* rather than its centre — a character stands on the
 * ground, so the tile they occupy is the one under their boots, and drawing them centred makes
 * everybody look like they're floating half a tile north of where they actually are.
 *
 * `imageSmoothingEnabled` goes off for the blit: this is pixel art, and letting the browser
 * interpolate it is exactly the mush the style exists to avoid.
 */
export function drawTrainer(
  ctx: CanvasRenderingContext2D,
  cam: Camera,
  who: { x: number, y: number, facing: Facing },
  opts: { hue: number, self: boolean, walking: boolean, phase: number },
): void {
  const size = TILE * cam.zoom
  // A shade over one tile, so a sprite reads as a person in a room rather than as a tile.
  const drawn = size * 1.5
  const p = toScreen(cam, who.x, who.y)

  const dir: SpriteDir = who.facing === 'up' ? 'up' : who.facing === 'down' ? 'down' : 'right'
  const frame: 0 | 1 = opts.walking && opts.phase % 2 === 1 ? 1 : 0
  const sprite = spriteCanvas(opts.hue, dir, frame, opts.self)

  const flip = who.facing === 'left'

  ctx.save()
  ctx.imageSmoothingEnabled = false
  ctx.translate(p.x, p.y + size * 0.35)

  if (flip) ctx.scale(-1, 1)

  ctx.drawImage(sprite, -drawn / 2, -drawn, drawn, drawn)
  ctx.restore()
}

/**
 * A blank room of a given size — four walls and floor between them.
 *
 * Used by the editor when somebody resizes the grid, so a new row arrives as floor with a wall
 * on its end rather than as a void the room leaks out of.
 */
export function blankTiles(width: number, height: number): string[] {
  return Array.from({ length: height }, (_, y) =>
    y === 0 || y === height - 1 ? WALL.repeat(width) : WALL + FLOOR.repeat(width - 2) + WALL)
}

/**
 * Resize a grid, keeping whatever still fits.
 *
 * The subtlety is the *old border*. Naively keeping every old character would grow the room by
 * wrapping the new floor around the wall that used to be the edge — leaving the new space
 * sealed off behind it, reachable by nobody and looking for all the world like a bug. So only
 * the old *interior* is carried over; the old wall is dropped and a fresh one drawn round the
 * new edge. Shrinking simply crops.
 *
 * Either way the result is a legal room — closed at the edges, open on the inside — because the
 * alternative is a save the server rejects for reasons the person resizing can't see.
 */
export function resizeTiles(tiles: string[], width: number, height: number): string[] {
  const oldHeight = tiles.length
  const oldWidth = tiles[0]?.length ?? 0

  return Array.from({ length: height }, (_, y) => {
    const row = tiles[y] ?? ''
    const chars = Array.from({ length: width }, (_, x) => {
      if (y === 0 || y === height - 1 || x === 0 || x === width - 1) return WALL

      const wasInterior = x > 0 && x < oldWidth - 1 && y > 0 && y < oldHeight - 1

      return wasInterior ? (row[x] ?? FLOOR) : FLOOR
    })

    return chars.join('')
  })
}
