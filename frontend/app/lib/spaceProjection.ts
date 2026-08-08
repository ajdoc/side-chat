/**
 * How tile space becomes screen space — the one place that knows what a room *looks* like from.
 *
 * Until now there was nothing to put here: a Side Space was drawn straight down at its grid, so
 * "tile 3,4" was "96,128" and the conversion was two multiplications inlined into
 * {@link file://./spaceMapEngine.ts spaceMapEngine}. A second projection changes that from an
 * arithmetic detail into a *decision the map makes*, and a decision has to live somewhere one
 * file wide, because the moment two files disagree about where a tile is you get a room where
 * the floor is in one place and the people standing on it are in another.
 *
 * So everything — drawing, hit testing, the editor's brush, the HTML overlays the stage pins over
 * people's heads — goes through {@link project} and {@link unproject} here, and nothing anywhere
 * else multiplies a tile by a size.
 *
 * ## The two projections
 *
 * **`flat`** is what every existing room is: axes on the screen axes, one tile one square. It is
 * the right way to draw a map you can *read* — an editor, a thumbnail, an overworld — and it is
 * the default, so no map that already exists changes by a pixel.
 *
 * **`iso`** is a 2:1 dimetric view: the grid is turned 45° and squashed to half height, which is
 * the projection the whole pixel-art tradition uses and the one the artwork people actually hand
 * you is drawn in. A tile stops being a square and becomes a diamond twice as wide as it is tall.
 *
 * ## Why the tile art didn't have to be redrawn
 *
 * The obvious way to draw an isometric floor is to author every tile as a diamond, which would
 * have meant a second copy of {@link file://./spaceTiles.ts spaceTiles} — a hundred rectangles
 * restated in a coordinate system where "the top-left sixteenth" no longer means anything.
 *
 * Instead the *canvas* is turned. {@link tileTransform} sets an affine transform under which the
 * upright unit square maps exactly onto the diamond, and then the existing tile code draws its
 * axis-aligned rectangles into the upright square it has always drawn into and comes out as a
 * diamond on screen. One tile of art, two projections, and a new tile added tomorrow is
 * isometric for free.
 *
 * Sprites are the exception and are deliberately *not* transformed: a person, a pet or a piece of
 * furniture is drawn upright at their projected anchor. Skewing them would lie about the
 * projection — in a real dimetric scene the ground recedes and the things standing on it do not —
 * and it would also shear every hand-drawn sprite in the app into a parallelogram.
 *
 * ## Depth
 *
 * Flat rooms sort north to south, because on a flat grid nothing overlaps anything but the row
 * above it. Isometric ones sort by `x + y`: screen depth runs along the diagonal, so a bookshelf
 * at 8,2 and one at 2,8 are the same distance "into" the picture and either may occlude what is
 * at 5,5. {@link depthOf} is that number, and it is the only ordering rule in the app.
 */

/** One tile, in pixels, before the camera's zoom. */
export const TILE = 32

/**
 * Which way a map is drawn. Stored on the map, so it's a property of the *room* rather than of
 * the viewer — two people standing in the same space have to see the same space.
 */
export type Projection = 'flat' | 'iso'

export const PROJECTIONS: Projection[] = ['flat', 'iso']

/**
 * How tall an isometric tile is relative to its width.
 *
 * A half, which is what makes it *2:1 dimetric* rather than a true isometric 1:1.15. The reason is
 * pixels, not geometry: at 2:1 the diamond's edge advances exactly two across for one down, so it
 * rasterises as a clean staircase with no stray pixels, and every sprite sheet and tileset drawn
 * in the last thirty years assumes it. True isometry looks marginally more correct and shimmers.
 */
const ISO_RATIO = 0.5

/**
 * The area of a diamond half as tall as its square is only half the square, so an isometric room
 * drawn at the same tile size as a flat one reads as smaller and further away. This scales the
 * basis back up so the two projections put roughly the same amount of room on the screen, which
 * is what stops the view lurching when a map is switched between them.
 */
const ISO_SCALE = 1.35

export interface Camera {
  /** Tile coordinates at the centre of the view. */
  x: number
  y: number
  /** Pixels per tile = {@link TILE} × zoom. */
  zoom: number
  /** Canvas size in CSS pixels. */
  width: number
  height: number
  /**
   * Which projection to draw with. Optional, and absent means `flat` — every camera constructed
   * before this existed, and every place that only ever shows the plain grid, keeps working
   * without naming it.
   */
  projection?: Projection
}

export function projectionOf(cam: Camera): Projection {
  return cam.projection ?? 'flat'
}

/**
 * The basis: where one step east and one step south land on the screen, in pixels.
 *
 * Everything else in this file is these four numbers. Flat is the identity times the tile size;
 * iso turns them 45° and halves the vertical.
 */
function basis(cam: Camera): { ex: number, ey: number, sx: number, sy: number } {
  const size = TILE * cam.zoom

  if (projectionOf(cam) === 'iso') {
    const w = (size * ISO_SCALE) / 2
    const h = w * ISO_RATIO

    // East goes down-right, south goes down-left. Both descend, which is what puts the far
    // corner of the room at the top of the screen.
    return { ex: w, ey: h, sx: -w, sy: h }
  }

  return { ex: size, ey: 0, sx: 0, sy: size }
}

/**
 * Tile coordinates → canvas pixels.
 *
 * The camera's tile is the centre of the canvas in both projections, so panning, following
 * somebody and centring on a zone all work the same either way.
 */
export function project(cam: Camera, x: number, y: number): { x: number, y: number } {
  const { ex, ey, sx, sy } = basis(cam)
  const dx = x - cam.x
  const dy = y - cam.y

  return {
    x: dx * ex + dy * sx + cam.width / 2,
    y: dx * ey + dy * sy + cam.height / 2,
  }
}

/**
 * Canvas pixels → a fractional position in the room. The true inverse of {@link project}.
 *
 * Inverting the 2×2 basis rather than hard-coding the isometric formula, because the two have to
 * stay inverses of each other and the only way to guarantee that is to derive one from the other.
 * A click that resolves to a different tile than the one drawn under the cursor is the single
 * most maddening bug this feature can have.
 */
export function unproject(cam: Camera, px: number, py: number): { x: number, y: number } {
  const { ex, ey, sx, sy } = basis(cam)
  const cx = px - cam.width / 2
  const cy = py - cam.height / 2

  const det = ex * sy - sx * ey

  return {
    x: (cx * sy - sx * cy) / det + cam.x,
    y: (ex * cy - cx * ey) / det + cam.y,
  }
}

/** Canvas pixels → the tile you clicked. {@link unproject} rounded, for the editor's brush. */
export function unprojectTile(cam: Camera, px: number, py: number): { x: number, y: number } {
  const w = unproject(cam, px, py)

  return { x: Math.round(w.x), y: Math.round(w.y) }
}

/**
 * Put the canvas into the frame of one tile, so upright square art comes out as a floor tile.
 *
 * The caller draws into a `size × size` box with its origin at 0,0 exactly as it always has, and
 * `restore`s when it's done. Under `flat` this is a plain translate and the drawing is unchanged;
 * under `iso` the axes are the isometric basis and the same rectangles land on the diamond.
 *
 * The `overdraw` nudge is not cosmetic. Adjacent diamonds share an edge, and a shared edge drawn
 * by two antialiased fills leaves a half-transparent hairline that reads as a grid of cracks in
 * the floor. Growing every tile a whisker past its own boundary makes neighbours overlap instead
 * of abut, and the seam disappears.
 */
export function tileTransform(ctx: CanvasRenderingContext2D, cam: Camera, x: number, y: number, overdraw = 1.02): number {
  const size = TILE * cam.zoom

  ctx.save()

  if (projectionOf(cam) === 'flat') {
    const p = project(cam, x - 0.5, y - 0.5)
    ctx.translate(p.x, p.y)

    return size
  }

  // The centre of the tile, so the overdraw grows about the middle rather than dragging the
  // diamond off its own square.
  const c = project(cam, x, y)
  const { ex, ey, sx, sy } = basis(cam)

  ctx.translate(c.x, c.y)
  ctx.transform(
    (ex / size) * overdraw,
    (ey / size) * overdraw,
    (sx / size) * overdraw,
    (sy / size) * overdraw,
    0,
    0,
  )
  // Back off by half a tile on both axes, which is the top corner of the diamond — the point the
  // upright art treats as its origin.
  ctx.translate(-size / 2, -size / 2)

  return size
}

/**
 * Put the canvas into the frame of the *floor*, centred on a tile — so a shape drawn as a circle
 * comes out lying on the ground rather than standing up facing the viewer.
 *
 * The distinction only exists once the ground recedes. The earshot ring is the case that needs
 * it: it marks a radius measured in tiles, and a plain screen-space circle under `iso` claims
 * your voice carries twice as far north as it does east. Drawn through this it's the ellipse the
 * projection implies, which is the same set of tiles the audio code actually uses.
 *
 * Returns the pixels-per-tile the caller should measure its radii in. `restore` when done.
 */
export function groundTransform(ctx: CanvasRenderingContext2D, cam: Camera, x: number, y: number): number {
  const size = TILE * cam.zoom
  const p = project(cam, x, y)
  const { ex, ey, sx, sy } = basis(cam)

  ctx.save()
  ctx.translate(p.x, p.y)
  ctx.transform(ex / size, ey / size, sx / size, sy / size, 0, 0)

  return size
}

/**
 * How far "into" the picture a tile is. Bigger is nearer the viewer, and therefore drawn later.
 *
 * See the note at the top: flat rooms only ever occlude down the screen, isometric ones occlude
 * along the diagonal.
 */
export function depthOf(cam: Camera, x: number, y: number): number {
  return projectionOf(cam) === 'iso' ? x + y : y
}

/**
 * The tiles the camera can actually see, as an inclusive box.
 *
 * Deliberately a *box* in tile space rather than the true visible rhombus. Under `iso` the
 * visible region is a diamond and the box around it holds about twice as many tiles as are on
 * screen, so this over-visits — but the alternative is scan-converting a rotated polygon on every
 * frame to save some `drawImage` calls that are clipped anyway, and the correct cull for the
 * cheap half of a frame is the one you can be sure has no gaps at the corners.
 *
 * The margin is generous for the same reason it always was: a tall sprite reaches up out of its
 * own footprint, so a tight cull pops tree canopies and bookshelves in as you scroll.
 */
export function visibleTiles(cam: Camera, width: number, height: number, margin = 2) {
  const corners = [
    unproject(cam, 0, 0),
    unproject(cam, cam.width, 0),
    unproject(cam, 0, cam.height),
    unproject(cam, cam.width, cam.height),
  ]

  const xs = corners.map(c => c.x)
  const ys = corners.map(c => c.y)

  return {
    size: TILE * cam.zoom,
    x0: Math.max(0, Math.floor(Math.min(...xs)) - margin),
    x1: Math.min(width - 1, Math.ceil(Math.max(...xs)) + margin),
    y0: Math.max(0, Math.floor(Math.min(...ys)) - margin),
    y1: Math.min(height - 1, Math.ceil(Math.max(...ys)) + margin),
  }
}
