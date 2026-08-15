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
import type { Camera, Projection } from './spaceProjection'
import type { BackdropPlacement } from './spaceBackdrops'
import { backdropOf, coveredBy, drawBackdrop } from './spaceBackdrops'
import { DECOR, decorBlocks, decorRow, decorSize, drawDecor } from './spaceDecor'
import { depthOf, groundTransform, project, projectionOf, TILE, tileTransform, unproject, unprojectTile, visibleTiles } from './spaceProjection'
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

/*
 * The tile size, the camera and the tile→pixel conversion live in spaceProjection, because a room
 * can now be drawn two ways and "where is tile 3,4" stopped being one answer. They're re-exported
 * here so every existing importer of this module is unaffected.
 */
export { depthOf, PROJECTIONS, projectionOf, TILE, tileTransform, visibleTiles } from './spaceProjection'
export type { Camera, Projection } from './spaceProjection'

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

/**
 * How big a map may be, each way — mirrored from `SideSpaceMap::MIN_SIZE` / `MAX_SIZE`.
 *
 * Here as well as on the server because the editor has to clamp a drag *before* it builds a grid,
 * and finding out the size was illegal from a 422 after you let go of the mouse is not a usable
 * way to draw a map.
 */
export const MIN_MAP = 8
export const MAX_MAP = 256

/**
 * How many screen surfaces one map may hold — mirrored from `SideSpaceMap::MAX_SCREENS`.
 *
 * Small, and for cost rather than taste: every surface is a `drawImage` of a live video frame,
 * every frame, and that is the most expensive thing this renderer ever paints.
 */
export const MAX_SCREENS = 4

/**
 * How many frames one map may hang — mirrored from `SideSpaceMap::MAX_EXHIBITS`.
 *
 * Generous, because a gallery is the point: a museum that ran out of frames a third of the way
 * round would be a worse room than one with none.
 */
export const MAX_EXHIBITS = 120

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

/**
 * Where a doorway leads.
 *
 * Three shapes, because "somewhere else" turned out to mean three genuinely different things
 * and collapsing them would have made each of them awkward. They differ by *how much is torn
 * down* on the way through, which is the only distinction that matters at the doorway:
 *
 *  - `point` — fast travel across a map you already have loaded. Nothing fetches, nothing is
 *    torn down; you are simply moved.
 *  - `map` — one of this channel's other maps: an interior. The grid under your feet is
 *    replaced, and *nothing else is* — same channel, same call, same presence channel, same
 *    people on the other end of it. This is the doorway kind, and the reason interiors exist
 *    rather than being separate channels.
 *  - `room` — another Side Space entirely. A journey: the call is torn down and rebuilt at the
 *    far end, which is a second or two of silence and is why it is not what a door does.
 */
export type PortalTarget =
  | { kind: 'point', x: number, y: number }
  /** `map` is the sibling's slug — maps are pointed at by name, so the link survives a copy. */
  | { kind: 'map', map: string, x?: number | null, y?: number | null }
  | { kind: 'room', channel_id: number, x?: number | null, y?: number | null }

/**
 * How a doorway is taken — mirrored from `SideSpaceMap::ACTIVATIONS`.
 *
 * A property of the *doorway*, not a global setting, because the right answer genuinely differs
 * per door. A wormhole between two halves of one island wants `walk`: it is scenery you run
 * through, and stopping to press a key at every one would make crossing the map a chore. A door
 * out of the room wants `press` — it is a decision, and one careless step should not end the
 * conversation you were having.
 */
export type PortalActivation = 'walk' | 'press'

/** A wormhole: a rectangle that puts you somewhere else when you walk in, or when you press E. */
export interface SpacePortal {
  id: string
  name: string
  x: number
  y: number
  w: number
  h: number
  /** Absent reads as `walk` — what every doorway built before this existed did. */
  activation?: PortalActivation | null
  to: PortalTarget
}

/**
 * A rectangle that shows whatever the room is currently watching.
 *
 * Geometry only — nothing about the *source* is stored, because the source is the call and every
 * browser already has it. This says where to paint; each client answers "paint what?" from the
 * screen share it is receiving.
 */
export interface SpaceScreen {
  id: string
  name: string
  x: number
  y: number
  w: number
  h: number
  /**
   * How far the screen's right edge is raised above its left, in tiles. Negative lifts it.
   *
   * Exists because the best-looking rooms are *drawn* rather than tiled, and drawn rooms are
   * almost always isometric — so the cinema screen painted into the artwork is a parallelogram,
   * not a rectangle. A video drawn square onto it sits visibly crooked, floating over the wall at
   * one end and short of the screen at the other.
   *
   * A shear rather than a full quad because a shear is what isometric art actually is: the 2:1
   * slope that makes a wall look vertical. `w` tiles across and `-w / 2` here is exactly that,
   * and is what the Movie Theatre uses.
   *
   * Absent is zero, which is a plain rectangle — right for a flat map and for a screen painted
   * face-on.
   */
  skew?: number | null
}

/**
 * A frame you can walk up to and open — a painting, a statue, a case.
 *
 * Geometry only. What is *in* the frame is a file somebody uploaded and the card beside it, which
 * arrives separately as {@link ExhibitPiece} — a member may move a frame but only staff may fill
 * one, and the two halves are stored apart for exactly that reason.
 */
export interface SpaceExhibit {
  id: string
  name: string
  x: number
  y: number
  w: number
  h: number
}

/** What is hanging in a frame: the picture and the wall label. Keyed to a {@link SpaceExhibit}. */
export interface ExhibitPiece {
  exhibit_id: string
  title: string
  artist?: string | null
  caption?: string | null
  /** Signed and expiring — see SideSpaceExhibit::url. Fetched when opened, never with the map. */
  url: string
}

/** How this doorway is taken, with the default applied. */
export function activationOf(portal: SpacePortal): PortalActivation {
  return portal.activation === 'press' ? 'press' : 'walk'
}

/**
 * The doorway on this map that leads into a given interior, if there is one.
 *
 * What "come back out of the door you went in by" is built on. Resolved at the moment of travel
 * rather than stored as a coordinate, and that is the whole point: a return that baked in *where
 * the door was* goes stale the first time somebody moves it, and nothing about moving a doorway
 * suggests you should also have to go into the other room and fix its way home.
 *
 * First match wins, and a map with two doors into the same room is a real thing to build — a
 * cinema with two entrances to one screen. Coming back out of the first is arbitrary between
 * them, but it is at least *stable*, and stable beats clever here: a return that tried to guess
 * which of the two you had used would be wrong in exactly the cases you would notice.
 */
export function doorwayInto(map: SpaceMap, slug: string): SpacePortal | null {
  for (const p of map.portals ?? []) {
    if (p.to.kind === 'map' && p.to.map === slug) return p
  }

  return null
}

/** Somewhere to stand inside a rectangle on this map, furniture included. Null if it is all solid. */
export function standableIn(
  map: SpaceMap,
  rect: { x: number, y: number, w: number, h: number },
): { x: number, y: number } | null {
  for (let y = rect.y; y < rect.y + rect.h; y++) {
    for (let x = rect.x; x < rect.x + rect.w; x++) {
      if (isWalkable(map, x, y)) return { x, y }
    }
  }

  return null
}

/**
 * The map a Side Space opens to — mirrored from the server's `SideSpaceMap::MAIN`.
 *
 * Every other map on a channel is an interior you can only reach through a door. This is the
 * one that always exists, the one an absent `?map=` means, and the one you are put back in if
 * the room you were standing in stops being there.
 */
export const MAIN_MAP = 'main'

/** One of a Side Space's rooms, as it appears in a list of them: enough to name and open. */
export interface SpaceMapRef {
  slug: string
  name: string
}

export interface SpaceMap {
  id: number
  channel_id: number
  /**
   * Which of the channel's rooms this is. `main` is the way in.
   *
   * A Side Space holds several maps — an overworld and the interiors behind its doors — and
   * they share one channel, one call and one presence channel. So this is what distinguishes
   * two people who are in the same *call* but not in the same *place*: see the position
   * whispers, which carry it, and are filtered by it.
   */
  slug: string
  /** The building's other rooms, names only. Present on a freshly read map. */
  siblings?: SpaceMapRef[]
  name: string
  width: number
  height: number
  /** `height` rows of `width` characters. See the tile alphabet in spaceTiles. */
  tiles: string[]
  /**
   * Artwork drawn instead of the tile art, and where each piece of it goes.
   *
   * Wherever a placement covers, the grid stops being painted and becomes *collision only* — see
   * `drawMap`. That is the entire difference artwork makes: everything that asks where you can
   * walk, what a zone holds or how far your voice carries reads the same tiles it always did.
   *
   * A list of rectangles rather than one whole-map picture, which is what lets a map be half
   * hand-built room and half city.
   */
  backdrops?: BackdropPlacement[]
  /**
   * Which way the room is drawn — see {@link file://./spaceProjection.ts spaceProjection}.
   *
   * A property of the map rather than of the viewer, because two people standing in the same
   * space have to be looking at the same space: a room someone furnished in an isometric view
   * has its couch against a wall that, seen flat, is a different wall.
   *
   * Absent on every map made before this existed, and read as `flat`. That's the whole of the
   * migration — an old room is a flat room and is drawn exactly as it was.
   */
  projection?: Projection
  zones: SpaceZone[]
  /**
   * Wormholes: rectangles you stand in and press E to be moved by.
   *
   * Not furniture, and not a zone. A zone changes who can hear you and changes nothing about
   * where you are. Furniture is something you press E on *in front of you*, and that is the
   * closer relative — the difference left is that a doorway is a region you are inside rather
   * than an object you face, which is why it wins the key over anything you happen to be facing
   * while stood in one.
   *
   * This did fire on being walked into, and the comment here used to argue that a doorway you
   * had to press was "a button lying on the floor". What that missed is the asymmetry of the
   * mistake: failing to notice a door costs you a keypress, and taking one you didn't mean to
   * costs you the conversation you were having. See the stage's `checkPortal`.
   */
  portals?: SpacePortal[]
  /**
   * Surfaces that show whatever the room is watching — a cinema screen, a monitor wall.
   *
   * Not furniture, and the difference is what they belong to: a television is an object standing
   * in the room and this is part of the room itself. On a backdrop map the cinema screen is
   * *painted into the picture*, at a position no catalogue item could be expected to line up
   * with, so a screen names an area rather than a thing occupying one.
   */
  screens?: SpaceScreen[]
  /**
   * Frames you can walk up to and open.
   *
   * A drawn room paints its own art, and at a few dozen pixels a painting reads as a gallery
   * without being anything you can *look at*. A frame is where the real picture is, and the real
   * picture is somebody's upload rather than anything in the artwork.
   */
  exhibits?: SpaceExhibit[]
  /** What is hanging in each frame. Absent frames are empty ones. */
  exhibit_pieces?: ExhibitPiece[]
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

/**
 * The frame a position is standing in front of, or null.
 *
 * Generous about what counts as in front, like {@link screenNear} and for the same reason: a
 * painting hangs on a wall you cannot walk into, so requiring you to be *inside* the frame would
 * mean the offer never appeared. One tile of slack all round, which is close enough to read as
 * "standing at this painting" and tight enough that a corridor of them doesn't all fire at once.
 */
export function exhibitNear(map: SpaceMap, x: number, y: number): SpaceExhibit | null {
  const tx = Math.round(x)
  const ty = Math.round(y)

  for (const e of map.exhibits ?? []) {
    if (tx >= e.x - 1 && tx < e.x + e.w + 1 && ty >= e.y - 1 && ty < e.y + e.h + 1) return e
  }

  return null
}

/** What's hanging in a given frame, if anything has been. */
export function pieceIn(map: SpaceMap, exhibitId: string): ExhibitPiece | null {
  return (map.exhibit_pieces ?? []).find(p => p.exhibit_id === exhibitId) ?? null
}

/**
 * The screen surface a position is standing in front of, or null.
 *
 * Used for the "watch this fullscreen" prompt, so it is generous about what counts as in front:
 * the rectangle itself plus a couple of tiles below it. A cinema screen hangs on a wall you
 * cannot walk into, so requiring you to be *inside* it would mean the prompt never appeared.
 */
export function screenNear(map: SpaceMap, x: number, y: number): SpaceScreen | null {
  const tx = Math.round(x)
  const ty = Math.round(y)

  for (const s of map.screens ?? []) {
    if (tx >= s.x - 1 && tx < s.x + s.w + 1 && ty >= s.y && ty < s.y + s.h + SCREEN_REACH) return s
  }

  return null
}

/**
 * How far in front of a screen the "watch this" prompt reaches, in tiles.
 *
 * Deep rather than tight, because a cinema is a room you watch from the *back* of. The prompt is
 * an offer and costs nothing to ignore, so the failure worth avoiding is somebody sitting down to
 * watch and finding no way to make it bigger.
 */
const SCREEN_REACH = 4

/**
 * The wormhole a position is standing in, or null.
 *
 * Rounded like every other question about where somebody is: a person's position is a float
 * because they slide between tiles, and a doorway you only registered by landing on exactly
 * `12.0, 5.0` would be one you could walk straight across without it ever offering.
 *
 * Standing in one no longer *takes* you through it — this answers "what would E do here", not
 * "where are you going". See the stage's `checkPortal` for why that changed.
 *
 * First match wins. Two overlapping doorways is not something the editor can draw, and if one
 * ever appeared the answer should at least be the same for everybody.
 */
export function portalAt(map: SpaceMap, x: number, y: number): SpacePortal | null {
  const tx = Math.round(x)
  const ty = Math.round(y)

  for (const p of map.portals ?? []) {
    if (tx >= p.x && tx < p.x + p.w && ty >= p.y && ty < p.y + p.h) return p
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

/*
 * `Camera` is defined in spaceProjection and re-exported at the top of this file — it now carries
 * which projection the room is drawn with, and the code that knows what that means lives there.
 */

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

/**
 * Tile coordinates → canvas pixels.
 *
 * A thin name over {@link project}. Kept because two dozen call sites across the stage and the
 * editor read `toScreen(camera, …)` and the rename would be noise — and because "to screen" is
 * what these callers mean, whereas "project" is what the geometry is.
 */
export function toScreen(cam: Camera, x: number, y: number): { x: number, y: number } {
  return project(cam, x, y)
}

/**
 * Canvas pixels → a fractional position in the room. The true inverse of {@link toScreen}.
 *
 * {@link toTile} is this rounded to the tile you clicked, which is what a brush wants. Walking
 * wants the unrounded answer: a tap half a tile to your left should turn you left rather than
 * resolve to the tile you're already standing on and do nothing.
 */
export function toWorld(cam: Camera, px: number, py: number): { x: number, y: number } {
  return unproject(cam, px, py)
}

/** Canvas pixels → tile coordinates. The inverse of {@link toScreen}, for the editor's brush. */
export function toTile(cam: Camera, px: number, py: number): { x: number, y: number } {
  return unprojectTile(cam, px, py)
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

/**
 * Which tiles the camera can actually see. Everything drawn works from this.
 *
 * The box is worked out by unprojecting the four corners of the canvas, which is the same answer
 * the old arithmetic gave for a flat room and the only one that's correct for a turned grid. See
 * {@link visibleTiles} for why it's a box rather than the true visible diamond.
 */
function viewport(map: SpaceMap, cam: Camera) {
  return visibleTiles(cam, map.width, map.height)
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
 *   1. **Ground.** The floor, painted through {@link tileTransform} — upright squares in a flat
 *      room, diamonds in an isometric one, from one set of tile art either way.
 *   2. **Standing scenery.** Tree canopies, and under `iso` the walls as well, which stop being
 *      part of the floor the moment the floor recedes. Interleaved with the furniture.
 *   3. **Furniture.** Sorted by {@link depthOf}, so a bookshelf against the far wall is behind
 *      the couch in front of it rather than in front of it by accident of array order.
 *
 * People are *not* drawn here. They're interleaved with nothing — the caller draws them after,
 * sorted among themselves — because a room where you walk behind the sofa is a much larger
 * change than it sounds, and this is not that change.
 *
 * `t` is seconds since the room opened, and is what makes water move.
 */
export function drawMap(
  ctx: CanvasRenderingContext2D,
  map: SpaceMap,
  cam: Camera,
  theme: MapTheme,
  t = 0,
  /**
   * The room's live picture, if anything is being shared — painted onto the map's screens.
   *
   * Passed in rather than reached for, because this module is framework-agnostic and knows
   * nothing about calls. The stage keeps one hidden `<video>` fed by whichever share is on, and
   * the editor passes nothing, so a screen shows its name there instead of a stale frame.
   */
  screen?: HTMLVideoElement | null,
): void {
  const { x0, x1, y0, y1 } = viewport(map, cam)

  /*
   * Artwork replaces the ground pass wherever it covers.
   *
   * Not *under* it — instead of it. Painting tile art on top of a picture of a city would cover
   * the city with grey rectangles, which is the one thing this exists to avoid; painting it
   * underneath would be work nobody ever sees. So covered tiles stop being drawn and carry on
   * being collision, which is the whole trick and is why nothing else in this file changed.
   *
   * Each placement goes down in one call rather than being culled to the viewport: a single
   * `drawImage` the GPU clips for free, where a per-tile cull would mean slicing the source into
   * hundreds of sub-rectangles a frame to save nothing.
   */
  const placements = (map.backdrops ?? []).filter(p => backdropOf(p.key))

  for (const placement of placements) {
    drawBackdrop(
      ctx,
      backdropOf(placement.key)!,
      toScreen(cam, placement.x - 0.5, placement.y - 0.5),
      toScreen(cam, placement.x + placement.w - 0.5, placement.y + placement.h - 0.5),
    )
  }

  {
    for (let y = y0; y <= y1; y++) {
      for (let x = x0; x <= x1; x++) {
        const tile = tileAt(map, x, y)
        if (tile === VOID) continue
        /*
         * Already painted by artwork: the grid here is collision and nothing else — *except* for
         * boards.
         *
         * Boards are the one tile that has to show through, because they are how a link across a
         * harbour is laid ({@link repairConnectivity}). Without this the causeway joining two
         * maps is invisible: you can walk it, and what you see is somebody strolling across open
         * water. It doubles as the rule for painting a floor over artwork by hand, which is the
         * same wish — "there is a walkway here now" — expressed with a brush.
         */
        if (coveredBy(placements, x, y) && tile !== WOOD) continue
        // Walls stand up in an isometric room and are drawn with the scenery below, not here.
        if (standsUp(cam, tile)) continue

        const size = tileTransform(ctx, cam, x, y)

        drawTile(ctx, tile, x, y, 0, 0, size, {
          up: tileAt(map, x, y - 1),
          down: tileAt(map, x, y + 1),
          left: tileAt(map, x - 1, y),
          right: tileAt(map, x + 1, y),
        }, t)

        ctx.restore()
      }
    }
  }

  // Furniture is drawn either way — artwork is the *ground*, and a speaker somebody placed in
  // Times Square still has to appear. What artwork suppresses is the scenery that is really tile
  // art standing up: walls and tree canopies, which are already painted into the picture.
  drawScenery(ctx, map, cam, t, placements)
  // Before the zones and before every sprite: a screen is part of the room's surface, and people
  // standing in front of one have to be drawn in front of it.
  drawScreens(ctx, map, cam, theme, screen)
  drawExhibits(ctx, map, cam, theme, TILE * cam.zoom)
  drawZones(ctx, map, cam, theme)
}

/**
 * The screens, showing the live frame if there is one and a dark panel if there isn't.
 *
 * ## Drawing a video into a canvas
 *
 * `drawImage` takes an `HTMLVideoElement` and paints its *current frame*, which is the whole
 * trick: the element never has to be in the document or visible, it only has to be playing. So
 * the room paints the share at whatever rate it is already painting itself, and there is no
 * second video pipeline, no extra decode, and nothing to keep in sync — the frame on the cinema
 * screen is the frame the element is on.
 *
 * Fitted rather than stretched. A share is whatever shape somebody's monitor is and a screen is
 * whatever shape the map's author drew, so filling the rectangle would distort every share that
 * wasn't cut to it. Letterboxed inside the rectangle instead, over black, which is what a cinema
 * does with the wrong aspect anyway.
 *
 * ## Why a rectangle and not the projected quad
 *
 * Under `iso` a map tile is a diamond, and a screen drawn as one would be a parallelogram — which
 * is *correct* for something lying on the floor and wrong for something standing up. A screen is
 * vertical: it faces the viewer. So it is drawn axis-aligned in the box its corners span, which
 * is the same thing `drawTallTile` does for a wall and for the same reason.
 */
function drawScreens(
  ctx: CanvasRenderingContext2D,
  map: SpaceMap,
  cam: Camera,
  theme: MapTheme,
  video?: HTMLVideoElement | null,
): void {
  const screens = map.screens ?? []
  if (!screens.length) return

  // A video with no dimensions yet has nothing to paint — it is still negotiating, and asking
  // `drawImage` for a frame it hasn't got throws on some engines rather than drawing nothing.
  const live = video && video.readyState >= 2 && video.videoWidth > 0 ? video : null

  for (const s of screens) {
    const a = toScreen(cam, s.x - 0.5, s.y - 0.5)
    const b = toScreen(cam, s.x + s.w - 0.5, s.y + s.h - 0.5)

    const left = Math.min(a.x, b.x)
    const top = Math.min(a.y, b.y)
    const width = Math.abs(b.x - a.x)
    const height = Math.abs(b.y - a.y)

    if (width < 2 || height < 2) continue

    /*
     * The shear that lays the picture onto isometric artwork — see SpaceScreen.skew.
     *
     * Measured by projecting the same tile twice rather than multiplying by a tile size, so it
     * follows the camera's zoom and would follow the projection too if a sheared screen were ever
     * placed on an iso map.
     */
    const rise = s.skew
      ? toScreen(cam, s.x, s.y + s.skew).y - toScreen(cam, s.x, s.y).y
      : 0

    ctx.save()
    ctx.translate(left, top)
    // A vertical shear about the left edge: every pixel across shifts down by `rise / width`.
    if (rise) ctx.transform(1, rise / width, 0, 1, 0, 0)

    // The screen itself, always: a cinema screen with nothing on it is black, not a hole.
    ctx.fillStyle = 'rgb(6 6 12)'
    ctx.fillRect(0, 0, width, height)

    if (live) {
      // Letterbox: the largest rectangle of the share's own shape that fits.
      const scale = Math.min(width / live.videoWidth, height / live.videoHeight)
      const w = live.videoWidth * scale
      const h = live.videoHeight * scale

      ctx.drawImage(live, (width - w) / 2, (height - h) / 2, w, h)
    }
    else {
      // Nothing playing. Named, so a screen somebody placed is visibly a screen rather than a
      // black rectangle they will assume is broken.
      ctx.fillStyle = theme.muted
      ctx.font = `500 ${Math.max(9, Math.min(height * 0.3, width * 0.09))}px system-ui, sans-serif`
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      ctx.fillText(s.name, width / 2, height / 2, width - 8)
    }

    // A faint frame, so the screen reads as mounted rather than as a hole cut in the artwork.
    ctx.strokeStyle = 'rgb(148 163 184 / 0.35)'
    ctx.lineWidth = 1.5
    ctx.strokeRect(0, 0, width, height)
    ctx.restore()
  }
}

/**
 * Whether a tile is drawn as something standing on the floor rather than as the floor.
 *
 * Only walls, and only under `iso`. A flat room draws a wall as a patch of masonry seen from
 * above and that is exactly right; project the same patch onto a diamond and it reads as a
 * strangely coloured piece of floor, because the thing that made it a wall was that it was
 * *vertical* and a flat view had no way to show that. Under `iso` it is drawn upright instead —
 * the same art, billboarded at the tile's anchor, so masonry faces the viewer like a wall.
 */
function standsUp(cam: Camera, tile: string): boolean {
  return tile === WALL && projectionOf(cam) === 'iso'
}

/**
 * Everything that stands on the floor: the furniture, the tree canopies, and — under `iso` — the
 * walls.
 *
 * ## Why these three are one pass now
 *
 * They used to be two, and the split was safe only because a flat room's scenery barely overlaps:
 * a canopy reaches into the row above it and nothing else, so painting every canopy and then
 * every piece of furniture gave the right answer nearly always. Turn the grid and that stops
 * being true in both directions at once. A wall on the north edge has to be behind the desk in
 * front of it and in front of the rug behind it, and there is no ordering of "all walls, then all
 * furniture" that gets both right. So they go into one list and are sorted together, which is the
 * only rule that generalises: **draw in increasing {@link depthOf}.**
 *
 * ## Cost
 *
 * The gather is over the visible box only, so a furnished room costs what's on screen rather than
 * what's in it — a thousand-piece office with a dozen pieces in view draws the dozen. The sort is
 * over that same handful rather than the whole map, which is what keeps a per-frame sort from
 * being the thing that makes a big room slow. {@link decorRow} still does the row indexing; this
 * just no longer relies on row order *being* the sort.
 *
 * Off-screen pieces are skipped generously — a tall sprite reaches up out of its own footprint,
 * so a tight cull pops bookshelves in as you scroll towards them.
 */
function drawScenery(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, t: number, placements: BackdropPlacement[] = []): void {
  const { size, x0, x1, y0, y1 } = viewport(map, cam)
  const now = performance.now() / 1000

  /*
   * A tagged draw call rather than a common interface, because the three have genuinely nothing
   * in common but a position: one needs its neighbours, one needs the clock, one needs its facing.
   * A union kept here is honest about that and costs a switch; an interface they all implement
   * would have meant a wrapper object per piece per frame.
   */
  const queue: Array<{ depth: number, draw: () => void }> = []

  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      // Where artwork covers, the walls and canopies are already in the picture; drawing the
      // tile art for them again would stamp grey masonry over a skyline. Boards are the
      // exception, for the reason given in drawMap — but boards are flat, so nothing here
      // stands up for them either way.
      const tile = coveredBy(placements, x, y) ? VOID : tileAt(map, x, y)

      if (standsUp(cam, tile)) {
        const p = anchor(cam, x, y)
        queue.push({
          depth: depthOf(cam, x, y),
          draw: () => drawTile(ctx, tile, x, y, p.x, p.y, size, {
            up: tileAt(map, x, y - 1),
            down: tileAt(map, x, y + 1),
            left: tileAt(map, x - 1, y),
            right: tileAt(map, x + 1, y),
          }, t),
        })
      }

      if (isTallTile(tile)) {
        const p = anchor(cam, x, y)
        queue.push({ depth: depthOf(cam, x, y), draw: () => drawTallTile(ctx, tile, x, y, p.x, p.y, size) })
      }
    }

    for (const object of decorRow(map.objects, y)) {
      if (object.x > x1 + 2 || object.x < x0 - 2) continue

      // The *placed* footprint, so a turned piece anchors against the tiles it actually covers.
      const kind = DECOR[object.kind]
      const placed = kind ? decorSize(object, kind) : { w: 1, h: 1 }

      const p = anchor(cam, object.x, object.y, placed.w, placed.h)
      queue.push({ depth: depthOf(cam, object.x, object.y), draw: () => drawDecor(ctx, object, p.x, p.y, size, now) })
    }
  }

  queue.sort((a, b) => a.depth - b.depth)
  for (const item of queue) item.draw()
}

/**
 * Where a *standing* thing is drawn: the top-left of the upright box whose bottom sits on the
 * tile's floor.
 *
 * Every sprite in the room — furniture, canopies, people, pets — is authored as an upright square
 * anchored at its base, and none of them are skewed by the projection (see the note at the top of
 * spaceProjection about why). So the only thing the projection changes for them is *where the
 * base lands*, which is the centre of the tile's diamond. Under `flat` that works out to the same
 * `x - 0.5, y - 0.5` corner every call site has always passed, so nothing moves in an existing
 * room.
 */
function anchor(cam: Camera, x: number, y: number, w = 1, h = 1): { x: number, y: number } {
  const size = TILE * cam.zoom

  if (projectionOf(cam) === 'flat') return toScreen(cam, x - 0.5, y - 0.5)

  /*
   * The footprint's *middle*, in tile space, and then back off by half the box.
   *
   * Taking the origin tile's centre and letting the caller add half a footprint would be right
   * for a one-tile piece and wrong for every other, because "half a footprint" is measured in
   * screen pixels while the footprint itself lies along the grid — which the projection has
   * turned. A three-tile bed anchored that way sits a tile and a half to the south-east of the
   * floor it occupies. Projecting the centre first and subtracting afterwards puts the same
   * arithmetic in the frame it belongs to, and collapses to the old answer at 1×1.
   */
  const c = toScreen(cam, x + (w - 1) / 2, y + (h - 1) / 2)

  return { x: c.x - (w * size) / 2, y: c.y - h * size }
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
    const isStage = z.kind === 'stage'

    /*
     * The outline is the zone's four corners *projected*, not a screen-space rectangle. A zone is
     * a rectangle of tiles, and under `iso` a rectangle of tiles is a parallelogram on screen — a
     * `strokeRect` there would draw a box that agrees with the sealed region at no point except
     * by accident, which for a boundary whose entire job is telling you where your voice stops
     * carrying is worse than drawing nothing.
     */
    const corners = [
      toScreen(cam, z.x - 0.5, z.y - 0.5),
      toScreen(cam, z.x + z.w - 0.5, z.y - 0.5),
      toScreen(cam, z.x + z.w - 0.5, z.y + z.h - 0.5),
      toScreen(cam, z.x - 0.5, z.y + z.h - 0.5),
    ]

    ctx.beginPath()
    ctx.moveTo(corners[0]!.x, corners[0]!.y)
    for (const c of corners.slice(1)) ctx.lineTo(c.x, c.y)
    ctx.closePath()

    ctx.fillStyle = isStage ? theme.stage : theme.zone
    ctx.fill()

    ctx.strokeStyle = isStage ? theme.stageBorder : theme.zoneBorder
    ctx.lineWidth = isStage ? 3 : 2
    if (!isStage) ctx.setLineDash([6, 4])
    ctx.stroke()
    ctx.setLineDash([])

    // The name goes over the middle of the zone rather than along a top edge that, once the grid
    // is turned, is a diagonal running off the corner of the shape.
    const middle = toScreen(cam, z.x + (z.w - 1) / 2, z.y - 0.5)

    ctx.fillStyle = isStage ? theme.stageBorder : theme.muted
    ctx.font = `500 ${Math.max(10, size * 0.34)}px system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'top'
    ctx.fillText(isStage ? `${z.name} · stage` : z.name, middle.x, middle.y + 4, z.w * size - 8)
  }

  drawPortals(ctx, map, cam, theme, size)
}

/**
 * Doorways, as wormholes: a dark eye on the floor with light turning around it.
 *
 * ## Why a vortex rather than a marked-out rectangle
 *
 * It used to be a tinted panel with a bright edge, which is the same visual language as a zone —
 * and a zone and a doorway are the two things in this room that must never be confused. One
 * changes who can hear you; the other picks you up and puts you somewhere else. A rectangle on
 * the floor is a *boundary*, and every boundary in this map means the first thing.
 *
 * A vortex isn't a boundary at all. It is an object with a middle, it is obviously not floor,
 * and it says "something happens here" without having to be read. The name still sits above it,
 * because which doorway this is remains a question only words can answer.
 *
 * ## How it is drawn
 *
 * The portal's footprint is projected first and the vortex is fitted *inside* that quad, which is
 * what keeps it honest under both projections: flat, the quad is a rectangle and the eye is a
 * circle; iso, the quad is a diamond and the same maths yields the squashed ellipse you would
 * expect to be looking at from that angle. Nothing here special-cases the projection — the only
 * input is where the four corners landed.
 *
 * The turning is three arcs at different radii and different speeds, two one way and one the
 * other. That counter-rotation is most of the effect: rings all turning together read as a
 * spinning plate, and rings turning against each other read as depth.
 */
function drawPortals(ctx: CanvasRenderingContext2D, map: SpaceMap, cam: Camera, theme: MapTheme, size: number): void {
  const portals = map.portals ?? []
  if (!portals.length) return

  const now = performance.now()
  // A slow breath under the spin. It has to read as alive from the corner of your eye while you
  // are doing something else, and not compete for attention while you aren't.
  const pulse = 0.5 + 0.5 * Math.sin(now / 620)

  for (const portal of portals) {
    const corners = [
      toScreen(cam, portal.x - 0.5, portal.y - 0.5),
      toScreen(cam, portal.x + portal.w - 0.5, portal.y - 0.5),
      toScreen(cam, portal.x + portal.w - 0.5, portal.y + portal.h - 0.5),
      toScreen(cam, portal.x - 0.5, portal.y + portal.h - 0.5),
    ]

    // The middle of the footprint, and the two half-axes of the ellipse that fits it. Measured
    // from the projected corners rather than computed from w/h, so the iso diamond comes out as
    // a squashed ellipse for free.
    const cx = (corners[0]!.x + corners[2]!.x) / 2
    const cy = (corners[0]!.y + corners[2]!.y) / 2
    const rx = Math.max(6, Math.abs(corners[1]!.x - corners[3]!.x) / 2)
    const ry = Math.max(4, Math.abs(corners[2]!.y - corners[0]!.y) / 2)

    ctx.save()
    ctx.translate(cx, cy)
    // Everything below is drawn as a circle of radius 1 and squashed into place here, which is
    // the only way arcs stay arcs: stroking an ellipse directly means every radius needs its own
    // trigonometry, and the line width would stretch with it.
    ctx.scale(rx, ry)

    /*
     * The eye. Dark in the middle going to bright at the rim — the opposite of a light on the
     * floor, and the reason it reads as a hole rather than as a glowing tile.
     */
    const eye = ctx.createRadialGradient(0, 0, 0, 0, 0, 1)
    eye.addColorStop(0, 'rgb(9 9 34 / 0.92)')
    eye.addColorStop(0.55, `rgb(76 29 149 / ${0.55 + pulse * 0.15})`)
    eye.addColorStop(0.85, `rgb(56 189 248 / ${0.34 + pulse * 0.18})`)
    eye.addColorStop(1, 'rgb(56 189 248 / 0)')

    ctx.beginPath()
    ctx.arc(0, 0, 1, 0, Math.PI * 2)
    ctx.fillStyle = eye
    ctx.fill()

    /*
     * Three turning arcs. Radius, speed, direction, and how much of the circle each one covers.
     *
     * The middle one runs backwards, which is what makes the thing look like it has depth rather
     * than like a spinning plate. Line widths are divided by the scale so a ring on a wide portal
     * isn't drawn fatter than one on a narrow portal — `scale` multiplies stroke width too.
     */
    const rings: Array<[r: number, speed: number, arc: number, alpha: number]> = [
      [0.88, 1 / 900, 1.5, 0.85],
      [0.62, -1 / 640, 1.1, 0.7],
      [0.36, 1 / 420, 0.8, 0.55],
    ]

    for (const [r, speed, arc, alpha] of rings) {
      const from = now * speed
      const scale = Math.max(rx, ry)

      ctx.beginPath()
      ctx.arc(0, 0, r, from, from + arc)
      ctx.strokeStyle = `rgb(165 243 252 / ${alpha * (0.6 + pulse * 0.4)})`
      ctx.lineWidth = 2.2 / scale
      ctx.lineCap = 'round'
      ctx.stroke()

      // The same arc opposite, so each ring reads as a ring rather than as a comet.
      ctx.beginPath()
      ctx.arc(0, 0, r, from + Math.PI, from + Math.PI + arc * 0.6)
      ctx.strokeStyle = `rgb(196 181 253 / ${alpha * 0.5 * (0.6 + pulse * 0.4)})`
      ctx.stroke()
    }

    // The rim, which is what gives the hole an edge to stand at.
    ctx.beginPath()
    ctx.arc(0, 0, 1, 0, Math.PI * 2)
    ctx.strokeStyle = `rgb(125 211 252 / ${0.4 + pulse * 0.35})`
    ctx.lineWidth = 1.6 / Math.max(rx, ry)
    ctx.stroke()

    ctx.restore()

    const middle = toScreen(cam, portal.x + (portal.w - 1) / 2, portal.y - 0.5)

    ctx.fillStyle = theme.text
    ctx.font = `600 ${Math.max(10, size * 0.32)}px system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'top'
    ctx.fillText(portal.name, middle.x, middle.y + 4, portal.w * size - 6)
  }
}

/**
 * The frames — as a hint that there is something here, never as the artwork itself.
 *
 * Deliberately almost invisible. On a drawn map the painting is *already there*, in far better
 * detail than anything drawn over it, so painting the uploaded image onto the wall would cover
 * hand-drawn art with a stretched photograph. What the room needs is only the affordance: a
 * corner mark saying this one opens.
 *
 * An empty frame is drawn differently — dashed and named — because an empty frame is a job
 * somebody has not finished, and it should look like one to whoever is standing in the gallery
 * deciding what to hang next.
 */
function drawExhibits(
  ctx: CanvasRenderingContext2D,
  map: SpaceMap,
  cam: Camera,
  theme: MapTheme,
  size: number,
): void {
  const frames = map.exhibits ?? []
  if (!frames.length) return

  for (const f of frames) {
    const a = toScreen(cam, f.x - 0.5, f.y - 0.5)
    const b = toScreen(cam, f.x + f.w - 0.5, f.y + f.h - 0.5)

    const left = Math.min(a.x, b.x)
    const top = Math.min(a.y, b.y)
    const width = Math.abs(b.x - a.x)
    const height = Math.abs(b.y - a.y)

    if (width < 2 || height < 2) continue

    const hung = (map.exhibit_pieces ?? []).some(p => p.exhibit_id === f.id)

    if (!hung) {
      // Waiting for a picture. Said out loud, because nobody but the person curating can tell an
      // empty frame from a painting the map simply hasn't got a rectangle over.
      ctx.save()
      ctx.setLineDash([4, 3])
      ctx.strokeStyle = 'rgb(148 163 184 / 0.5)'
      ctx.lineWidth = 1.5
      ctx.strokeRect(left, top, width, height)
      ctx.setLineDash([])
      ctx.fillStyle = theme.muted
      ctx.font = `500 ${Math.max(8, Math.min(height * 0.3, width * 0.16))}px system-ui, sans-serif`
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      ctx.fillText(f.name, left + width / 2, top + height / 2, width - 4)
      ctx.restore()

      continue
    }

    // Hung: a small mark in the corner and nothing across the art itself.
    const mark = Math.max(3, Math.min(size * 0.18, 9))

    ctx.save()
    ctx.fillStyle = 'rgb(250 204 21 / 0.85)'
    ctx.beginPath()
    ctx.moveTo(left + width - mark, top)
    ctx.lineTo(left + width, top)
    ctx.lineTo(left + width, top + mark)
    ctx.closePath()
    ctx.fill()
    ctx.restore()
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
  // Drawn on the floor rather than on the screen — under `iso` a screen-space circle would claim
  // the voice carries twice as far north as east. See groundTransform.
  const size = groundTransform(ctx, cam, at.x, at.y)

  const gradient = ctx.createRadialGradient(0, 0, NEAR_TILES * size, 0, 0, FAR_TILES * size)
  gradient.addColorStop(0, from)
  gradient.addColorStop(1, to)

  ctx.fillStyle = gradient
  ctx.beginPath()
  ctx.arc(0, 0, FAR_TILES * size, 0, Math.PI * 2)
  ctx.fill()

  ctx.restore()
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

/**
 * A whole map, in the shape the editor holds one — the parts that can be stamped into another.
 *
 * Deliberately narrower than {@link SpaceMap}: no id, no channel, no locks. What travels when you
 * put one map inside another is its ground, its furniture, its rooms and its artwork, and nothing
 * about *which* room it came from.
 */
export interface MapPatch {
  width: number
  height: number
  tiles: string[]
  zones?: SpaceZone[]
  objects?: SpaceObject[]
  backdrops?: BackdropPlacement[]
}

/**
 * Crop the grid back at one edge — the opposite of growing it.
 *
 * Undo puts back the map you had a moment ago; this takes tiles off whichever edge you point at,
 * whenever you want, however the map got that big. They are different tools: one is "I regret
 * the last thing I did", the other is "this map is bigger than it needs to be".
 *
 * The new edge is re-walled, because a map has to stay closed — cropping into open floor would
 * otherwise leave the world with a hole in its side. Everything that no longer fits is the
 * caller's problem: see `reduce` in the editor, which drops the furniture and rooms that fell
 * off and pulls the entrance back inside.
 */
export function cropTiles(
  tiles: string[],
  edge: 'east' | 'west' | 'south' | 'north',
  by: number,
): { tiles: string[], dx: number, dy: number, width: number, height: number } {
  const oldH = tiles.length
  const oldW = tiles[0]?.length ?? 0

  const horizontal = edge === 'east' || edge === 'west'
  const width = Math.max(MIN_MAP, oldW - (horizontal ? by : 0))
  const height = Math.max(MIN_MAP, oldH - (horizontal ? 0 : by))

  // Cropping the west or north edge slides everything that survives left or up, so the caller
  // has to move the furniture with it — hence the negative offset.
  const dx = edge === 'west' ? -(oldW - width) : 0
  const dy = edge === 'north' ? -(oldH - height) : 0

  const cropped = Array.from({ length: height }, (_, y) => Array.from({ length: width }, (_, x) => {
    if (x === 0 || y === 0 || x === width - 1 || y === height - 1) return WALL

    return tiles[y - dy]?.[x - dx] ?? FLOOR
  }).join(''))

  return { tiles: cropped, dx, dy, width, height }
}

/**
 * Grow the grid until it contains `rect`, wherever that rectangle happens to be.
 *
 * The one way a map grows. The rectangle may sit anywhere, including off the top or left of the
 * map — negative coordinates are the whole point, and growing to meet them shifts the existing
 * map right and down, which is why the offset comes back.
 *
 * Both ways of extending a map go through this: naming an edge and a number is only a second way
 * of describing a rectangle, and keeping them as two functions is how the drag version ended up
 * with different join behaviour from the form version.
 *
 * The same seam rule applies as for a plain extension: the old border wall is opened on any side
 * the map grew, because a wall left standing between the old ground and the new is an extension
 * nobody can walk into. Sides that didn't grow keep their wall.
 */
export function growToFit(
  tiles: string[],
  rect: { x: number, y: number, w: number, h: number },
  max = MAX_MAP,
): { tiles: string[], dx: number, dy: number, width: number, height: number } {
  const oldH = tiles.length
  const oldW = tiles[0]?.length ?? 0

  const left = Math.max(0, -rect.x)
  const top = Math.max(0, -rect.y)
  const right = Math.max(0, rect.x + rect.w - oldW)
  const bottom = Math.max(0, rect.y + rect.h - oldH)

  // Clamped, and the *left* and *top* growth is kept in preference to the right and bottom: if a
  // drag asks for more map than the grid may hold, the part under the cursor is the part somebody
  // is looking at.
  const dx = Math.min(left, Math.max(0, max - oldW))
  const dy = Math.min(top, Math.max(0, max - oldH))
  const width = Math.min(max, oldW + dx + right)
  const height = Math.min(max, oldH + dy + bottom)

  const grown = Array.from({ length: height }, (_, y) => Array.from({ length: width }, (_, x) => {
    if (x === 0 || y === 0 || x === width - 1 || y === height - 1) return WALL

    const ox = x - dx
    const oy = y - dy
    if (ox < 0 || oy < 0 || ox >= oldW || oy >= oldH) return FLOOR

    // The old rim, on a side that has new ground beyond it, is now interior — open it.
    const opens = (ox === 0 && dx > 0)
      || (oy === 0 && dy > 0)
      || (ox === oldW - 1 && width > oldW + dx)
      || (oy === oldH - 1 && height > oldH + dy)

    return opens ? FLOOR : (tiles[oy]?.[ox] ?? FLOOR)
  }).join(''))

  return { tiles: grown, dx, dy, width, height }
}

/**
 * What it costs to dig one tile, for the route-finder below.
 *
 * Not a uniform cost, because "shortest" and "sensible" are different questions. Water and empty
 * space are cheap: a causeway across a harbour is what you would actually build, and it reads as
 * one. A building is expensive, so a route only tunnels through the Financial District when there
 * is genuinely no way round it — which is the difference between a bridge and a hole in a bank.
 */
function digCost(tile: string): number {
  if (isWalkableTile(tile)) return 0
  if (tile === WATER || tile === VOID) return 1

  return 6
}

/**
 * Make every part of a map reachable from every other part, digging the cheapest links needed.
 *
 * ## Why this replaced connecting two points
 *
 * The previous version joined *one pair* of tiles: somewhere in the old map, and the first
 * walkable tile found inside whatever had just been stamped. That is the wrong contract, and the
 * way it failed is instructive. Scanning the New York grid top-left-first finds `(11, 3)` — a
 * misclassified speck in the **night sky band**, part of a five-tile scrap. So the map was dutifully
 * joined to a piece of sky, and the 849-tile city next to it stayed sealed. Everything looked
 * connected and you still walked into an invisible wall.
 *
 * The honest requirement was never "join these two tiles", it is **"leave no pocket stranded"**.
 * So this finds every walkable region, treats the largest as the mainland, and links each of the
 * others to it in turn — each link growing the mainland, so the result is one connected place
 * however many pieces it started as.
 *
 * ## The routes it picks
 *
 * Dijkstra over {@link digCost}, so crossing ground you can already walk on is free, crossing
 * water costs a little and cutting through a building costs a lot. The cheapest route is therefore
 * the one that disturbs the map least and prefers the water — and water crossings are laid as
 * boards rather than floor, so a link across a harbour comes out looking like the pier it is.
 *
 * Scraps below `minRegion` are left alone. A single stranded tile is a rooftop or a sliver of
 * pavement behind a building; digging a boardwalk to it would be worse than leaving it.
 */
export function repairConnectivity(
  tiles: string[],
  { minRegion = 4, width = 3 }: { minRegion?: number, width?: number } = {},
): { tiles: string[], links: number, dug: number } {
  const height = tiles.length
  const size = tiles[0]?.length ?? 0
  const grid = tiles.map(row => [...row])

  const inside = (x: number, y: number) => x > 0 && y > 0 && x < size - 1 && y < height - 1
  const tileAtIndex = (i: number) => grid[Math.floor(i / size)]![i % size]!
  const free = (i: number) => isWalkableTile(tileAtIndex(i))

  // --- the walkable regions, biggest first ---
  const seen = new Uint8Array(size * height)
  const regions: number[][] = []

  for (let y = 1; y < height - 1; y++) {
    for (let x = 1; x < size - 1; x++) {
      const start = y * size + x
      if (seen[start] || !free(start)) continue

      const group: number[] = []
      const stack = [start]
      seen[start] = 1

      while (stack.length) {
        const at = stack.pop()!
        group.push(at)

        const ax = at % size
        const ay = Math.floor(at / size)

        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
          const nx = ax + dx!
          const ny = ay + dy!
          const next = ny * size + nx
          if (!inside(nx, ny) || seen[next] || !free(next)) continue

          seen[next] = 1
          stack.push(next)
        }
      }

      regions.push(group)
    }
  }

  regions.sort((a, b) => b.length - a.length)
  if (regions.length < 2) return { tiles, links: 0, dug: 0 }

  const mainland = new Set(regions[0]!)
  let links = 0
  let dug = 0

  for (const region of regions.slice(1)) {
    if (region.length < minRegion) continue

    const target = new Set(region)

    /*
     * Multi-source Dijkstra from the whole mainland at once. Sourcing from every mainland tile
     * rather than from one chosen point is what makes the link land at the *narrowest* crossing
     * instead of wherever the chosen point happened to be — the difference between a bridge at
     * the obvious place and a bridge diagonally across the bay.
     *
     * A bucket queue, not a heap: costs are small integers, so "the cheapest unvisited tile" is
     * just the next non-empty bucket.
     */
    const cost = new Map<number, number>()
    const parent = new Map<number, number>()
    const buckets: number[][] = []

    const push = (at: number, c: number) => {
      ;(buckets[c] ??= []).push(at)
    }

    for (const at of mainland) {
      cost.set(at, 0)
      push(at, 0)
    }

    let found: number | null = null

    for (let c = 0; c < buckets.length && found === null; c++) {
      const bucket = buckets[c]
      if (!bucket) continue

      while (bucket.length) {
        const at = bucket.pop()!
        if (cost.get(at)! < c) continue

        if (target.has(at)) {
          found = at
          break
        }

        const ax = at % size
        const ay = Math.floor(at / size)

        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
          const nx = ax + dx!
          const ny = ay + dy!
          if (!inside(nx, ny)) continue

          const next = ny * size + nx
          const step = c + digCost(tileAtIndex(next))
          if (cost.has(next) && cost.get(next)! <= step) continue

          cost.set(next, step)
          parent.set(next, at)
          push(next, step)
        }
      }
    }

    if (found === null) continue

    // Walk the route back and lay it. Water becomes boards — a link across a harbour should look
    // like a pier, not like somebody painted a corridor on the sea.
    const route: number[] = []
    for (let at: number | undefined = found; at !== undefined; at = parent.get(at)) route.push(at)

    const spread = Math.max(0, Math.floor((width - 1) / 2))

    for (const at of route) {
      const ax = at % size
      const ay = Math.floor(at / size)

      for (let ox = -spread; ox <= spread; ox++) {
        for (let oy = -spread; oy <= spread; oy++) {
          const x = ax + ox
          const y = ay + oy
          if (!inside(x, y) || isWalkableTile(grid[y]![x]!)) continue

          grid[y]![x] = grid[y]![x] === WATER || grid[y]![x] === VOID ? WOOD : FLOOR
          dug++
        }
      }
    }

    // Everything just laid, and the region it reached, are mainland for the next round.
    for (const at of route) mainland.add(at)
    for (const at of region) mainland.add(at)
    links++
  }

  return { tiles: grid.map(row => row.join('')), links, dug }
}

/** The first tile you can stand on inside a rectangle, or null if it is solid throughout. */
export function firstWalkableIn(
  tiles: string[],
  rect: { x: number, y: number, w: number, h: number },
): { x: number, y: number } | null {
  for (let y = rect.y; y < rect.y + rect.h; y++) {
    for (let x = rect.x; x < rect.x + rect.w; x++) {
      if (isWalkableTile(tiles[y]?.[x] ?? WALL)) return { x, y }
    }
  }

  return null
}

/**
 * Stamp one map into another at a tile offset.
 *
 * What "add the New York map to the right of my office" actually is. The patch's ground is
 * written over the host's, and its furniture, rooms and artwork are copied across with their
 * coordinates shifted — so a preset authored at the origin lands wherever it was dropped.
 *
 * Anything that would fall outside the host is dropped rather than clamped. Clamping would pile
 * a district's worth of furniture against the edge in a heap; dropping it leaves a map that is
 * simply smaller than what was offered, which is what somebody who stamped a 64-wide city into
 * 40 tiles of space can see and fix.
 *
 * Ids are re-minted for zones and objects, because the host may already contain a stamp of the
 * same patch and two rooms sharing an id is a lock that opens both.
 */
export function stampMap(host: MapPatch, patch: MapPatch, atX: number, atY: number): MapPatch {
  const fits = (x: number, y: number, w = 1, h = 1) =>
    x >= 0 && y >= 0 && x + w <= host.width && y + h <= host.height

  const tiles = host.tiles.map((row, y) => {
    const py = y - atY
    if (py < 0 || py >= patch.height) return row

    const source = patch.tiles[py] ?? ''
    const chars = [...row]

    for (let px = 0; px < patch.width; px++) {
      const x = atX + px
      if (x >= 0 && x < host.width) chars[x] = source[px] ?? FLOOR
    }

    return chars.join('')
  })

  const stamped = `s${Math.random().toString(36).slice(2, 7)}`

  return {
    width: host.width,
    height: host.height,
    tiles,
    zones: [
      ...(host.zones ?? []),
      ...(patch.zones ?? [])
        .map((z, i) => ({ ...z, id: `${stamped}-z${i}`, x: z.x + atX, y: z.y + atY }))
        .filter(z => fits(z.x, z.y, z.w, z.h)),
    ],
    objects: [
      ...(host.objects ?? []),
      ...(patch.objects ?? [])
        .map((o, i) => ({ ...o, id: `${stamped}-o${i}`, x: o.x + atX, y: o.y + atY }))
        .filter(o => fits(o.x, o.y)),
    ],
    backdrops: [
      ...(host.backdrops ?? []),
      ...(patch.backdrops ?? []).map(b => ({ ...b, x: b.x + atX, y: b.y + atY })),
    ],
  }
}
