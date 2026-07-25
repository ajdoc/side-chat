/**
 * The ground a Side Space is drawn on.
 *
 * ## Why this isn't the theme
 *
 * Every other canvas in the app takes its colours from the app's own accent so it follows the
 * light/dark switch. This one deliberately doesn't. A room is trying to look like a *place* —
 * grass that reads as grass, water that reads as water — and no amount of the user's chosen
 * indigo makes a pond. So the palette below is fixed, warm, and slightly desaturated, in the
 * idiom of a Game Boy Color overworld: a handful of hues, each with a light and a dark, and
 * black outlines doing most of the work. What follows the theme is the UI *around* the room.
 *
 * ## How a tile is drawn
 *
 * In sixteenths of a tile, always. Every rectangle is placed on a 16×16 grid scaled to whatever
 * the camera's zoom makes a tile, which is what keeps the art chunky and aligned instead of
 * turning into soft rounded blobs when somebody zooms. It's the same reason the sprites are
 * rasterised at a fixed scale and blitted with smoothing off.
 *
 * Detail is *hashed from the tile's coordinates* ({@link tileNoise}), never random: a floor whose
 * speckles move each frame shimmers horribly, and one where they differ between two people's
 * screens is a room that isn't quite the same room.
 *
 * ## What's animated, and what isn't
 *
 * Water and tall grass, and nothing else. Both are cheap (a couple of extra rectangles whose
 * offset comes from a sine of the clock) and both are what stop a still frame looking like a
 * screenshot of a spreadsheet. Everything else is static, because a floor that moves is a floor
 * you look at instead of walking on.
 */

import { tileNoise } from './pixelSprite'

// --- the alphabet, mirrored from App\Support\SideSpace\Tiles ---

export const FLOOR = '.'
export const WALL = '#'
export const VOID = ' '
export const GRASS = ','
export const TALL_GRASS = '"'
export const FLOWERS = '^'
export const PATH = '-'
export const WOOD = '='
export const CARPET = '%'
export const WATER = '~'
export const TREE = 'T'
export const SAND = ':'

/**
 * The tiles you can stand on.
 *
 * An allow list, exactly as on the server, and for the same reason: a tile added to the alphabet
 * and forgotten here comes out solid, which is a room with an odd wall in it. The other way
 * round it would be people walking through the sea.
 */
const WALKABLE = new Set([FLOOR, GRASS, TALL_GRASS, FLOWERS, PATH, WOOD, CARPET, SAND])

export function isWalkableTile(tile: string): boolean {
  return WALKABLE.has(tile)
}

/** Tiles drawn taller than their own square, and therefore painted after everything else. */
export function isTallTile(tile: string): boolean {
  return tile === TREE
}

// --- the palette ---

const C = {
  grass: '#7cb342',
  grassDark: '#5f9134',
  grassLight: '#9ccc65',
  tall: '#4e7d2a',
  tallLight: '#6ba03a',
  flower: '#fff59d',
  flowerAlt: '#f48fb1',
  path: '#d7bc8a',
  pathDark: '#c0a271',
  sand: '#ecd9a8',
  sandDark: '#d8c28c',
  water: '#4a90d9',
  waterDark: '#2f6fb5',
  waterLight: '#8fc4ef',
  wood: '#c69565',
  woodLight: '#d5aa7f',
  woodDark: '#b0855a',
  woodSeam: '#9a6c42',
  carpet: '#b95f6a',
  carpetDark: '#9c4b56',
  carpetEdge: '#d98c93',
  floor: '#e8e2d4',
  floorAlt: '#ded7c6',
  floorSeam: '#c9c0ab',
  wall: '#8d8677',
  wallLight: '#b3ac9c',
  wallDark: '#5f5a4f',
  trunk: '#7b5230',
  trunkDark: '#5d3d23',
  leaf: '#3f7d2c',
  leafLight: '#5aa03a',
  leafDark: '#2c5c1e',
  outline: '#2b2a24',
  shadow: 'rgb(0 0 0 / 0.16)',
}

/** What the editor's tile buttons show, and the order they show it in. */
export const TILE_BRUSHES: { tile: string, label: string, hint: string, swatch: string }[] = [
  { tile: FLOOR, label: 'Floor', hint: 'Indoor floor', swatch: C.floor },
  { tile: WOOD, label: 'Boards', hint: 'Wooden floor', swatch: C.wood },
  { tile: CARPET, label: 'Carpet', hint: 'Marks out a corner', swatch: C.carpet },
  { tile: GRASS, label: 'Grass', hint: 'Outdoors', swatch: C.grass },
  { tile: TALL_GRASS, label: 'Tall grass', hint: 'Rustles as you walk', swatch: C.tall },
  { tile: FLOWERS, label: 'Flowers', hint: 'Grass, in bloom', swatch: C.flowerAlt },
  { tile: PATH, label: 'Path', hint: 'Tells people where to walk', swatch: C.path },
  { tile: SAND, label: 'Sand', hint: 'The shore', swatch: C.sand },
  { tile: WATER, label: 'Water', hint: 'You can stand at the edge of it', swatch: C.water },
  { tile: WALL, label: 'Wall', hint: 'Blocks movement', swatch: C.wall },
  { tile: TREE, label: 'Tree', hint: 'Solid, and taller than its tile', swatch: C.leaf },
  { tile: VOID, label: 'Nothing', hint: 'Outside the room — solid, and drawn as nothing', swatch: 'transparent' },
]

// --- drawing ---

/**
 * The four neighbours of a tile, as the characters they hold. Enough for every autotile
 * decision here: whether a wall wears a cap, which sides a carpet edges, where a shadow falls.
 */
export interface Neighbours {
  up: string
  down: string
  left: string
  right: string
}

/**
 * Paint one tile.
 *
 * `px`/`py` is its top-left corner in canvas pixels and `size` its side — the caller owns the
 * camera, so this needs no knowledge of one. `t` is seconds since the room opened, and is the
 * only reason two calls with the same arguments can differ.
 */
export function drawTile(
  ctx: CanvasRenderingContext2D,
  tile: string,
  x: number,
  y: number,
  px: number,
  py: number,
  size: number,
  n: Neighbours,
  t: number,
): void {
  // One sixteenth of a tile: the unit every rectangle below is measured in.
  const u = size / 16
  const rect = (ax: number, ay: number, aw: number, ah: number, colour: string) => {
    ctx.fillStyle = colour
    ctx.fillRect(px + ax * u, py + ay * u, Math.max(1, aw * u), Math.max(1, ah * u))
  }

  switch (tile) {
    case VOID:
      return
    case GRASS:
      return grass(rect, x, y)
    case TALL_GRASS:
      return tallGrass(rect, x, y, t)
    case FLOWERS:
      return flowers(rect, x, y)
    case PATH:
      return speckled(rect, x, y, C.path, C.pathDark)
    case SAND:
      return speckled(rect, x, y, C.sand, C.sandDark)
    case WATER:
      return water(rect, x, y, t)
    case WOOD:
      return boards(rect, x, y)
    case CARPET:
      return carpet(rect, x, y, n)
    case TREE:
      // The trunk only. The canopy overhangs the tile above, so it's painted in a later pass —
      // see drawTallTile.
      return grass(rect, x, y)
    case WALL:
      return wall(rect, n)
    default:
      return floor(rect, x, y, n)
  }
}

type Rect = (x: number, y: number, w: number, h: number, colour: string) => void

/** Short grass: a flat base with two or three tufts leaning whichever way the hash says. */
function grass(rect: Rect, x: number, y: number): void {
  rect(0, 0, 16, 16, C.grass)

  const a = tileNoise(x, y)
  const b = tileNoise(x, y, 1)

  rect(Math.floor(a * 11) + 1, Math.floor(b * 5) + 2, 3, 1, C.grassDark)
  rect(Math.floor(b * 11) + 2, Math.floor(a * 5) + 9, 2, 1, C.grassLight)
  if (a > 0.6) rect(Math.floor(b * 12) + 1, 6, 2, 1, C.grassDark)
}

/**
 * Tall grass: darker, denser, and swaying.
 *
 * The sway is one column of blades shifted by a sine whose phase includes the tile's own hash,
 * so a field ripples rather than pulsing in unison. It's the cheapest possible motion — an
 * integer offset on four rectangles — and it does more for "this room is alive" than anything
 * else on screen.
 */
function tallGrass(rect: Rect, x: number, y: number, t: number): void {
  // Short grass underneath, so a patch of it sits *in* the field rather than on top of it.
  rect(0, 0, 16, 16, C.grassDark)

  const phase = tileNoise(x, y) * Math.PI * 2
  const sway = Math.round(Math.sin(t * 1.6 + phase))

  // Three clumps rather than four even columns: evenly spaced blades read as a barcode, and the
  // whole point of this tile is that it should look like something you'd wade through.
  for (let i = 0; i < 3; i++) {
    const bx = Math.floor(tileNoise(x, y, i + 3) * 4) + i * 5
    const top = 3 + Math.floor(tileNoise(y, x, i) * 3)
    const lean = i % 2 === 0 ? sway : -sway

    rect(bx + lean, top, 1, 4, C.tallLight)
    rect(bx + 2 + lean, top + 1, 1, 3, C.tallLight)
    rect(bx + 1, top + 2, 2, 16 - top - 2, C.tall)
    rect(bx, top + 4, 4, 3, C.tall)
  }
}

/** Grass with a couple of blooms in it. Colour picked by hash, so a bed isn't all one flower. */
function flowers(rect: Rect, x: number, y: number): void {
  grass(rect, x, y)

  const a = tileNoise(x, y, 2)
  const b = tileNoise(x, y, 3)
  const petal = a > 0.5 ? C.flower : C.flowerAlt

  rect(Math.floor(a * 9) + 2, Math.floor(b * 9) + 2, 2, 2, petal)
  rect(Math.floor(b * 9) + 5, Math.floor(a * 9) + 6, 2, 2, a > 0.5 ? C.flowerAlt : C.flower)
}

/** Dirt and sand: a base with a scatter of darker grains. */
function speckled(rect: Rect, x: number, y: number, base: string, grain: string): void {
  rect(0, 0, 16, 16, base)

  for (let i = 0; i < 3; i++) {
    const a = tileNoise(x, y, i)
    const b = tileNoise(x, y, i + 8)

    rect(Math.floor(a * 14) + 1, Math.floor(b * 14) + 1, 2, 1, grain)
  }
}

/**
 * Water: a dark base with two pale highlights sliding across it.
 *
 * The highlights wrap by tile rather than by map, which means a large pond has a visible
 * repetition — and that is fine, and rather the point. It's what water looked like in the games
 * this is drawn after.
 */
function water(rect: Rect, x: number, y: number, t: number): void {
  rect(0, 0, 16, 16, C.water)
  rect(0, 0, 16, 3, C.waterDark)

  const drift = Math.floor((t * 3 + tileNoise(x, y) * 8) % 16)

  rect(drift - 12, 5, 6, 1, C.waterLight)
  rect((drift + 6) % 16 - 4, 10, 5, 1, C.waterLight)
  rect(drift % 8, 13, 3, 1, C.waterDark)
}

/**
 * Floorboards: long horizontal planks with a single staggered end-joint.
 *
 * The first attempt drew a joint in both halves of every tile and came out looking like
 * brickwork — which is the failure mode of drawing a repeating texture at tile resolution.
 * One joint per tile, alternating side to side, and a seam only a shade darker than the board
 * is enough: the eye reads long boards running across the room rather than a wall lying down.
 */
function boards(rect: Rect, x: number, y: number): void {
  rect(0, 0, 16, 16, C.wood)

  // The gap between courses. Two of the four board edges, so a course reads as one thickness.
  rect(0, 0, 16, 1, C.woodDark)
  rect(0, 8, 16, 1, C.woodDark)

  // Grain: a couple of long, barely-there streaks.
  const a = tileNoise(x, y, 4)
  rect(2, 3, 9, 1, C.woodLight)
  rect(Math.floor(a * 6) + 5, 11, 7, 1, C.woodLight)
  if (a > 0.8) rect(Math.floor(a * 9) + 3, 5, 2, 1, C.woodSeam)

  // One end-joint, on alternating sides, so the courses stagger like real boards.
  rect((x + y) % 2 === 0 ? 5 : 12, 1, 1, 7, C.woodSeam)
}

/**
 * Carpet, with a lighter binding wherever it meets something that isn't carpet.
 *
 * Autotiled purely so a carpeted area reads as a *rug the size of the area* rather than as a
 * differently-coloured patch of floor. Four one-pixel edges is a small price for that.
 */
function carpet(rect: Rect, x: number, y: number, n: Neighbours): void {
  rect(0, 0, 16, 16, C.carpet)

  for (let i = 0; i < 4; i++) {
    const a = tileNoise(x, y, i + 12)
    rect(Math.floor(a * 14) + 1, Math.floor(tileNoise(y, x, i) * 14) + 1, 1, 1, C.carpetDark)
  }

  if (n.up !== CARPET) rect(0, 0, 16, 1, C.carpetEdge)
  if (n.down !== CARPET) rect(0, 15, 16, 1, C.carpetEdge)
  if (n.left !== CARPET) rect(0, 0, 1, 16, C.carpetEdge)
  if (n.right !== CARPET) rect(15, 0, 1, 16, C.carpetEdge)
}

/** Indoor floor: big pale tiles with a seam, checkered faintly so movement has something to bite on. */
function floor(rect: Rect, x: number, y: number, n: Neighbours): void {
  rect(0, 0, 16, 16, (x + y) % 2 === 0 ? C.floor : C.floorAlt)
  rect(0, 15, 16, 1, C.floorSeam)
  rect(15, 0, 1, 16, C.floorSeam)

  // A shadow under a wall, so walls look like they stand on the floor rather than beside it.
  if (n.up === WALL) rect(0, 0, 16, 2, C.shadow)
}

/**
 * A wall block: a lit cap, a body, and a dark base.
 *
 * The cap is only drawn where there's nothing above — which is the whole of the autotiling here,
 * and it's what makes a run of wall read as one wall with a top rather than as a column of
 * bricks. The vertical seams break up long runs.
 */
function wall(rect: Rect, n: Neighbours): void {
  rect(0, 0, 16, 16, C.wall)

  if (n.up !== WALL && n.up !== TREE) {
    rect(0, 0, 16, 4, C.wallLight)
    rect(0, 4, 16, 1, C.wallDark)
  }

  rect(0, 14, 16, 2, C.wallDark)
  if (n.left !== WALL) rect(0, 0, 1, 16, C.wallDark)
  if (n.right !== WALL) rect(15, 0, 1, 16, C.wallDark)
}

/**
 * The parts of a tile that stick up out of it — today, a tree's canopy.
 *
 * Drawn in its own pass over the whole visible grid, *after* every ground tile, because a canopy
 * overhangs the tile above it and would otherwise be painted over by whatever is up there. It's
 * the same painter's-algorithm problem the people have, one row at a time.
 */
export function drawTallTile(
  ctx: CanvasRenderingContext2D,
  tile: string,
  x: number,
  y: number,
  px: number,
  py: number,
  size: number,
): void {
  if (tile !== TREE) return

  const u = size / 16
  const rect = (ax: number, ay: number, aw: number, ah: number, colour: string) => {
    ctx.fillStyle = colour
    ctx.fillRect(px + ax * u, py + ay * u, Math.max(1, aw * u), Math.max(1, ah * u))
  }

  // The trunk sits in this tile…
  rect(6, 9, 4, 7, C.trunk)
  rect(6, 9, 1, 7, C.trunkDark)

  // …and the canopy rises a whole tile above it, which is why negative rows are legal here.
  const a = tileNoise(x, y, 5)

  rect(2, -10, 12, 14, C.leaf)
  rect(0, -6, 16, 8, C.leaf)
  rect(3, -11, 10, 2, C.leafLight)
  rect(1, -5, 3, 6, C.leafDark)
  rect(12, -5, 3, 6, C.leafDark)
  rect(4, -8, 3, 2, C.leafLight)
  rect(Math.floor(a * 6) + 5, Math.floor(a * 4) - 4, 2, 2, C.leafDark)
  rect(2, 2, 12, 1, C.shadow)
}
