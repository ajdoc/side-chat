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

import type { AvatarLook } from './spaceAvatar'
import type { SpaceObject } from './spaceDecor'
import type { PetKind } from './spacePets'
import { decorBlocks, drawDecor } from './spaceDecor'
import {
  CARPET,
  drawTallTile,
  drawTile,
  FLOOR,
  GRASS,
  isTallTile,
  isWalkableTile,
  PATH,
  SAND,
  TALL_GRASS,
  TREE,
  VOID,
  WALL,
  WATER,
  WOOD,
} from './spaceTiles'

/** One tile, in pixels, before the camera's zoom. */
export const TILE = 32

/*
 * The tile alphabet is re-exported rather than redefined: it lives with the code that knows how
 * to *draw* each character, and everything else in the room only ever needs to name one.
 */
export { CARPET, FLOOR, GRASS, PATH, SAND, TALL_GRASS, TILE_BRUSHES, TREE, VOID, WALL, WATER, WOOD } from './spaceTiles'
export type { SpaceObject } from './spaceDecor'

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
 *     and dropped. The gap between it and `FAR_TILES` does two jobs, and the second is the
 *     reason it's as wide as it is:
 *
 *     **Hysteresis** — someone pacing on the edge of audibility crosses in and out of hearing
 *     (free — it's a volume assignment) without repeatedly tearing down and re-negotiating a
 *     peer connection (not free at all).
 *
 *     **Pre-dialling** — a peer connection is not instant. Offer, answer, ICE, DTLS: several
 *     hundred milliseconds on a good day, and longer whenever the pair has to fall back to a
 *     relay. Dialling at the moment somebody becomes audible therefore guarantees that the
 *     first thing they say is lost, which reads as the room being slow rather than as physics.
 *     So the connection is opened while they are still *inaudible* and walking towards you —
 *     several tiles of walking, which at {@link SPEED} is comfortably more than a handshake —
 *     and by the time distance says you can hear them, there is already something to hear.
 *     The gain is 0 the whole way in, so an early connection is silent, not a leak.
 */
export const NEAR_TILES = 2
export const FAR_TILES = 8
export const CONNECT_TILES = 14

/**
 * How many people may be live on one stage at a time.
 *
 * A cap, and not an arbitrary one: the call is a **mesh**, so a live speaker uplinks their
 * camera once *per listener*. Three speakers to a room of thirty is ninety encodes' worth of
 * outbound video spread across three laptops, which the bitrate caps in useVoice can carry.
 * Thirty speakers is the same room asking every machine in it to be a broadcast studio, and
 * what it actually produces is thirty stuttering streams and no talk at all.
 *
 * So the platform holds three, first come, and everyone else on it is in the wings. When this
 * room outgrows that the answer is an SFU, not a bigger number here.
 */
export const STAGE_SPEAKERS = 3

export type Facing = 'up' | 'down' | 'left' | 'right'

export interface SpaceZone {
  id: string
  name: string
  /**
   * What the rectangle does to sound.
   *
   *   - `private` — a sealed room. Sound neither leaves it nor gets in.
   *   - `stage` — sealed in exactly the same way, *except* outbound for whoever is currently
   *     live on it: a speaker is heard, and seen, by the whole map. See {@link liveSpeakers}.
   *
   * The sealing is shared deliberately. It gives the stage its green room for free — step on
   * while it's full and you're in the wings, hearing the speakers and heard by nobody outside
   * — and it means a talk isn't drowned by the six people stood next to the platform.
   */
  kind: 'private' | 'stage'
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
  /** `height` rows of `width` characters. See the tile alphabet in spaceTiles. */
  tiles: string[]
  zones: SpaceZone[]
  /** The furniture standing on the ground. Kinds and positions only — see spaceDecor. */
  objects: SpaceObject[]
  spawn: { x: number, y: number }
  /**
   * Who is responsible for each room, and which doors are shut to whom.
   *
   * Kept out of the saved document on the server — any member may save a map, so a lock stored
   * in it would be a lock any member could delete by rebuilding the room. They arrive *with* the
   * map because the browser needs them at frame rate to decide whether a door swings open, and
   * for whom. See lib/spaceDoors.ts, and the migration that explains the split.
   */
  rooms?: Array<{ zone_id: string, owner_id: number | null, owner: string | null }>
  locks?: Array<{
    object_id: string
    zone_id: string | null
    /** Standing keys. A pass bought with the password is in `passes`, with its deadline. */
    allowed: number[]
    has_password?: boolean
    passes?: Array<{ id: number, until: number }>
  }>
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
  /** How they're drawn. Whispered with their position, so a newcomer needs no lookup. */
  look?: AvatarLook
  /** What's following them, if anything. */
  pet?: PetKind | null
  /**
   * Where their pet actually is, which is not where they are — it trails a tile or so behind.
   * Local to each client and never sent: everybody computes the same trot from the same path,
   * and a pet that arrived over the wire would cost as much traffic as a second person.
   */
  petAt?: { x: number, y: number, facing: Facing }
  /**
   * The id of the piece of furniture they're sitting on, or null if they're on their feet.
   *
   * Whispered with the position rather than derived from it, because it can't be derived: a
   * sitter is standing on a couch's tile, and so is somebody who has walked onto a stool. The
   * difference is intent, and only their own client knows it.
   */
  seatedOn?: string | null
  /**
   * The line they're shouting, drawn in a bubble over their head, or null for silence.
   *
   * Whispered with the position like the look is, and for the same reason: somebody who has
   * just walked in has to be able to draw the room from the first thing they hear, without a
   * lookup per person.
   */
  shout?: string | null
  /**
   * The id of whoever they're holding hands with, or null.
   *
   * Whispered with the position for the same reason `seatedOn` is: it cannot be worked out from
   * where two people are standing — two friends side by side and two people queueing look
   * identical — and the room has to draw the link between them from the first thing it hears.
   *
   * Stated by *both* ends, which is what makes it self-correcting. Each client writes only its
   * own half, so a dropped "let go" leaves one person claiming a link the other denies, and the
   * room draws it only when both agree. See `holdHands` and `handLink` in useSpacePresence.
   */
  handWith?: number | null
  /**
   * When they stepped onto a stage zone, or null if they aren't on one.
   *
   * Whispered with the position because it cannot be derived from it: everybody standing on the
   * platform is on the same tiles, and the queue for a full stage is decided by who got there
   * first. Only their own client knows that, so only their own client can say it — and once
   * said, every client can work out the same set of live speakers. See {@link liveSpeakers}.
   */
  stageAt?: number | null
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
  const tx = Math.round(x)
  const ty = Math.round(y)

  return isWalkableTile(tileAt(map, tx, ty)) && !decorBlocks(map.objects, tx, ty)
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
 * 1. **A live stage carries everywhere.** If `b` is live on a stage — see {@link liveSpeakers}
 *    for what earns that — you hear them at full volume from anywhere on the map, walls and
 *    distance included. That is the entire point of a stage, and it's the one rule that breaks
 *    the symmetry below: hearing a speaker is not the same as their hearing you.
 * 2. **Zones win over distance.** Two people in the same private zone hear each other fully,
 *    however far apart they stand in it — that's what a meeting room is *for*. And if exactly
 *    one of them is in a zone, they hear nothing of each other regardless of how close they
 *    are, because a sealed room that leaks to somebody standing against the outside of its
 *    wall is not sealed. Different zones: likewise nothing.
 * 3. **Inside `NEAR_TILES`, full volume.** Flat, so a conversation doesn't wobble as people
 *    shift about in it.
 * 4. **Beyond that, fade to nothing at `FAR_TILES`.** Squared rather than linear, so the fall
 *    is gentle where you're still in earshot and steep as you leave — which sounds like
 *    walking away from someone, whereas a linear ramp sounds like a fader being pulled.
 *
 * Symmetric by construction *apart from rule 1*: both ends compute the same number from the
 * same two positions, which is what lets each side gate its own connection without either
 * negotiating. `bLive` keeps that property, because it too is computed from whispered state
 * that everybody holds — both ends of a pair reach the same verdict about who is on the
 * platform, so the asymmetry is agreed rather than argued over.
 */
export function audibility(
  map: SpaceMap,
  a: { x: number, y: number },
  b: { x: number, y: number },
  bLive = false,
): number {
  const za = zoneAt(map, a.x, a.y)
  const zb = zoneAt(map, b.x, b.y)

  if (bLive && zb?.kind === 'stage') return 1

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
 * Not simply `audibility > 0`: the connection is deliberately wider than hearing, so that it is
 * already up before you can hear anything and stays up for a few tiles after you can't. See the
 * radii above.
 *
 * Zones are *not* allowed to narrow it, only widen it. A sealed room is sealed by gain — the
 * people either side of its wall hear nothing of each other — but hanging up on them as well
 * would mean that stepping through the door starts a fresh handshake, and the first thing said
 * inside the room gets eaten. So proximity to the wall is enough to keep a silent connection
 * warm, and sharing the zone keeps it open at any distance.
 */
export function inConnectRange(
  map: SpaceMap,
  a: { x: number, y: number },
  b: { x: number, y: number },
  bLive = false,
): boolean {
  const za = zoneAt(map, a.x, a.y)
  const zb = zoneAt(map, b.x, b.y)

  // Somebody the whole map can hear is somebody the whole map needs a connection to, wherever
  // they happen to be standing.
  if (bLive && zb?.kind === 'stage') return true

  if (za && zb && za.id === zb.id) return true

  return distance(a, b) <= CONNECT_TILES
}

/**
 * Who is live on a stage right now — the answer every client works out for itself.
 *
 * ## Why it's a pure function of whispered state
 *
 * Going live is not a request anybody grants. There is no server in this path at all: positions
 * are whispers between the people in the room (see useSpacePresence), and `stageAt` rides along
 * with them. So every client runs this same function over the same roster and arrives at the
 * same set — which is what lets a speaker's own screen show them live at the same moment the
 * audience's screens start hearing them, with nothing to negotiate and nothing to go stale.
 *
 * ## The ordering
 *
 * Per stage, earliest onto the platform first, ties broken by id so the answer is total.
 * `stageAt` is the wall clock of the person who stepped up, which means a badly-set clock can
 * jump the queue — the honest bound on this design. It only ever decides *which* of several
 * people is the one turned away from a full stage, never whether an uncontended speaker is
 * heard, and the loser can see they're in the wings and ask. That's a fair trade for a feature
 * that needs no round trip to start talking.
 */
export function liveSpeakers(
  map: SpaceMap,
  occupants: Array<{ id: number, x: number, y: number, stageAt?: number | null }>,
): Set<number> {
  const byZone = new Map<string, Array<{ id: number, stageAt: number }>>()

  for (const o of occupants) {
    if (!o.stageAt) continue

    const z = zoneAt(map, o.x, o.y)
    // Claiming a time but standing off the platform: they left, and their next whisper will say
    // so. Position has the final say either way, so nothing here trusts the claim alone.
    if (z?.kind !== 'stage') continue

    const queue = byZone.get(z.id) ?? []
    queue.push({ id: o.id, stageAt: o.stageAt })
    byZone.set(z.id, queue)
  }

  const live = new Set<number>()

  for (const queue of byZone.values()) {
    queue.sort((p, q) => p.stageAt - q.stageAt || p.id - q.id)

    for (const speaker of queue.slice(0, STAGE_SPEAKERS)) live.add(speaker.id)
  }

  return live
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

/**
 * How far the view may be pushed either side of the size a room picks for itself.
 *
 * A *multiplier*, not a zoom: the room already chooses a scale from the height it's been given
 * (a squat panel gets a wider view), and this is how much of that choice a person can overrule.
 * Bounded on both sides because neither extreme is a view of a room — far enough out and everyone
 * is three pixels tall, far enough in and you're looking at one desk through a letterbox.
 */
export const MIN_ZOOM = 0.6
export const MAX_ZOOM = 2.5

/** One notch of a zoom button, and the scale a wheel notch applies. */
export const ZOOM_STEP = 1.2

export function clampZoom(zoom: number, min = MIN_ZOOM, max = MAX_ZOOM): number {
  return Math.max(min, Math.min(max, zoom))
}

/**
 * Scale the view about a point on the canvas, keeping the world under that point still.
 *
 * The behaviour everyone expects from a wheel and from a pinch: the thing you are pointing at is
 * the thing you zoom into. Done by measuring where the point lands in the room before and after
 * the scale and shifting the camera by the difference — which is exact, and needs no special case
 * for where the camera happens to be.
 *
 * For a camera that follows somebody around (the stage) this is the wrong tool: there, the centre
 * is *supposed* to be the person, and a wheel that shifted it would fight the follow on the next
 * frame. That camera scales and stays put; see SideSpaceStage.
 */
export function zoomAround(
  cam: Camera,
  factor: number,
  px: number,
  py: number,
  min = MIN_ZOOM,
  max = MAX_ZOOM,
): void {
  const before = toWorld(cam, px, py)
  cam.zoom = clampZoom(cam.zoom * factor, min, max)
  const after = toWorld(cam, px, py)

  cam.x += before.x - after.x
  cam.y += before.y - after.y
}

/** Tile coordinates → canvas pixels. */
export function toScreen(cam: Camera, x: number, y: number): { x: number, y: number } {
  const size = TILE * cam.zoom

  return {
    x: (x - cam.x) * size + cam.width / 2,
    y: (y - cam.y) * size + cam.height / 2,
  }
}

/**
 * Canvas pixels → a fractional position in the room. The true inverse of {@link toScreen}.
 *
 * {@link toTile} is this rounded to the tile you clicked, which is what a brush wants. Walking
 * wants the unrounded answer: a tap half a tile to your left should turn you left rather than
 * resolve to the tile you're already standing on and do nothing.
 */
export function toWorld(cam: Camera, px: number, py: number): { x: number, y: number } {
  const size = TILE * cam.zoom

  return {
    x: (px - cam.width / 2) / size + cam.x,
    y: (py - cam.height / 2) / size + cam.y,
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

/**
 * The bits of the room's palette that still come from the app's theme.
 *
 * Only two things do, and both are annotation rather than scenery: the tint on a private zone
 * and the colour its name is written in. The ground itself is fixed — see spaceTiles for why a
 * room's grass shouldn't change colour when somebody picks a different accent.
 */
export interface MapTheme {
  zone: string
  zoneBorder: string
  /** The platform, and the colour its name and edge are written in. See {@link drawZones}. */
  stage: string
  stageBorder: string
  text: string
  muted: string
}

/** Which tiles the camera can actually see. Everything drawn works from this. */
function viewport(map: SpaceMap, cam: Camera) {
  const size = TILE * cam.zoom
  const cols = Math.ceil(cam.width / size / 2) + 2
  const rows = Math.ceil(cam.height / size / 2) + 2

  return {
    size,
    x0: Math.max(0, Math.floor(cam.x) - cols),
    x1: Math.min(map.width - 1, Math.ceil(cam.x) + cols),
    y0: Math.max(0, Math.floor(cam.y) - rows),
    y1: Math.min(map.height - 1, Math.ceil(cam.y) + rows),
  }
}

/**
 * Paint the room: the ground, then everything standing on it.
 *
 * Only the tiles the camera can actually see are visited — an 80×80 map is 6400 tiles, and
 * drawing all of them 60 times a second to show a fifth of them is the kind of waste that only
 * shows up on somebody else's laptop.
 *
 * Three passes, and the order is the whole of the depth sorting:
 *
 *   1. **Ground.** Flat, one tile per square.
 *   2. **Tall ground.** Tree canopies, which overhang the tile above them and would be painted
 *      over by whatever is up there if they went in pass one.
 *   3. **Furniture.** Sorted north to south, so a bookshelf against the top wall is behind the
 *      couch in front of it rather than in front of it by accident of array order.
 *
 * People are *not* drawn here. They're interleaved with nothing — the caller draws them after,
 * sorted among themselves — because a room where you walk behind the sofa is a much larger
 * change than it sounds, and this is not that change.
 *
 * `t` is seconds since the room opened, and is what makes water move.
 */
export function drawMap(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, theme: MapTheme, t = 0): void {
  const { size, x0, x1, y0, y1 } = viewport(map, cam)

  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const tile = tileAt(map, x, y)
      if (tile === VOID) continue

      const p = toScreen(cam, x - 0.5, y - 0.5)

      drawTile(ctx, tile, x, y, p.x, p.y, size, {
        up: tileAt(map, x, y - 1),
        down: tileAt(map, x, y + 1),
        left: tileAt(map, x - 1, y),
        right: tileAt(map, x + 1, y),
      }, t)
    }
  }

  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const tile = tileAt(map, x, y)
      if (!isTallTile(tile)) continue

      const p = toScreen(cam, x - 0.5, y - 0.5)
      drawTallTile(ctx, tile, x, y, p.x, p.y, size)
    }
  }

  drawObjects(ctx, map, cam)
  drawZones(ctx, map, cam, theme)
}

/**
 * The furniture.
 *
 * Sorted by the *bottom* of each piece rather than its origin, so a two-tile-deep rug and a
 * bookshelf standing at its far edge come out in the order you'd expect. Off-screen pieces are
 * skipped generously — a tall sprite reaches up out of its own footprint, so the cull has to
 * allow a tile of slack or bookshelves pop in as you scroll towards them.
 */
function drawObjects(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera): void {
  const { size, x0, x1, y0, y1 } = viewport(map, cam)
  const t = performance.now() / 1000

  const visible = (map.objects ?? [])
    .filter(o => o.x <= x1 + 2 && o.x >= x0 - 2 && o.y <= y1 + 2 && o.y >= y0 - 2)
    .sort((a, b) => a.y - b.y)

  for (const object of visible) {
    const p = toScreen(cam, object.x - 0.5, object.y - 0.5)
    drawDecor(ctx, object, p.x, p.y, size, t)
  }
}

/**
 * Zones, as a tinted rectangle with its name along the top edge.
 *
 * A stage is drawn as a solid-edged platform rather than the dashed outline of a sealed room,
 * because the two rectangles do opposite things and telling them apart is not optional: one is
 * where you go to be *unheard* by the room, the other is where a step across the line puts your
 * voice in everybody's ears. The border is the warning.
 */
function drawZones(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, theme: MapTheme): void {
  const size = TILE * cam.zoom

  for (const z of map.zones ?? []) {
    const p = toScreen(cam, z.x - 0.5, z.y - 0.5)
    const w = z.w * size
    const h = z.h * size
    const isStage = z.kind === 'stage'

    ctx.fillStyle = isStage ? theme.stage : theme.zone
    ctx.fillRect(p.x, p.y, w, h)

    ctx.strokeStyle = isStage ? theme.stageBorder : theme.zoneBorder
    ctx.lineWidth = isStage ? 3 : 2
    if (!isStage) ctx.setLineDash([6, 4])
    ctx.strokeRect(p.x + 1, p.y + 1, w - 2, h - 2)
    ctx.setLineDash([])

    ctx.fillStyle = isStage ? theme.stageBorder : theme.muted
    ctx.font = `500 ${Math.max(10, size * 0.34)}px system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'top'
    ctx.fillText(isStage ? `${z.name} · stage` : z.name, p.x + w / 2, p.y + 4, w - 8)
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

// --- people ---

/*
 * The trainer sprite used to live here. It now lives in spaceAvatar, because it stopped being
 * one sprite: a look is composed of a body and a hairstyle with their own palettes, and pets
 * are a second cast of sprites again. What's left in this file is the room — geometry,
 * proximity and paint — which is the half that has to be reasoned about without a browser.
 */
export { drawTrainer, spriteHue, SPRITE_SIZE } from './spaceAvatar'
export type { AvatarLook } from './spaceAvatar'
export { drawPet, PETS } from './spacePets'
export type { PetKind } from './spacePets'

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
