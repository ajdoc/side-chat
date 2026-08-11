/**
 * Whole-map artwork, drawn under everything instead of the tile grid.
 *
 * The browser's half of {@link file://../../../backend/app/Support/SideSpace/Backdrops.php
 * App\Support\SideSpace\Backdrops} — same list, same keys, mirrored for the same reason the tile
 * alphabet and the furniture catalogue are: the server owns what's *allowed*, the browser owns
 * what it *looks like*, and neither can ask the other at the speed it needs the answer.
 *
 * ## What a backdrop replaces, and what it doesn't
 *
 * It replaces the ground art and nothing else. A backdrop map still has a full grid of tile
 * characters underneath it; that grid is simply never painted. It still decides where you can
 * walk, what a zone contains, where a door may stand and how far your voice carries, because
 * none of those ever asked what a tile *looked* like — see `drawMap`.
 *
 * This is the whole reason a picture this detailed can be dropped into a system built for
 * sixteen-pixel rectangles without touching proximity audio, pathing, the games or the editor.
 *
 * ## Why the key isn't a path
 *
 * A map is user-authored, and any member of the server may save one. A path stored in the
 * document would be an arbitrary string chosen by a member that every other browser in the room
 * is then told to fetch. So the document holds a key, this file holds the path, and a key nobody
 * recognises draws no backdrop rather than an unknown request.
 */

import { SHEET_ROOT, stillImage } from './spriteSheet'

export interface Backdrop {
  label: string
  /** Path under {@link SHEET_ROOT}. */
  src: string
  /**
   * The grid the artwork was cut for.
   *
   * Advisory: the image is stretched to whatever the map's own width and height are, so a
   * mismatch is a picture that still draws but whose streets no longer line up with the squares
   * you can walk on. The presets match; the editor warns if you resize away from it.
   */
  tiles: { w: number, h: number }
}

export const BACKDROPS: Record<string, Backdrop> = {
  'gather-town': {
    label: 'New York',
    src: 'backdrops/gather-town.png',
    tiles: { w: 64, h: 35 },
  },
  'nyc-street': {
    label: 'NYC street',
    src: 'backdrops/nyc-street.png',
    tiles: { w: 36, h: 24 },
  },
  'nyc-loft': {
    label: 'NYC loft',
    src: 'backdrops/nyc-loft.png',
    tiles: { w: 14, h: 8 },
  },
  'nyc-skyline': {
    label: 'NYC skyline, rain',
    src: 'backdrops/nyc-skyline.png',
    tiles: { w: 48, h: 32 },
  },
  'nyc-island': {
    label: 'NYC island, night',
    src: 'backdrops/nyc-island.png',
    tiles: { w: 48, h: 32 },
  },
}

export function backdropOf(key: string | null | undefined): Backdrop | null {
  return (key && BACKDROPS[key]) || null
}

/**
 * A piece of artwork and the rectangle of tiles it covers.
 *
 * The rectangle is the whole reason this isn't just a key on the map. "This map is a city" can be
 * said with a key; "this map is the office I already built, and the city starts thirty tiles to
 * the right" cannot. Tiles outside every placement are drawn as tile art, so one map can be both.
 */
export interface BackdropPlacement {
  key: string
  x: number
  y: number
  w: number
  h: number
}

/**
 * A placement's artwork if it has arrived, else null.
 *
 * Exposed because the minimap draws the same images at a different size, and having it reach
 * into the sprite cache directly would be a second place that knows how a backdrop key becomes
 * a path.
 */
export function backdropImage(key: string): HTMLImageElement | null {
  const art = backdropOf(key)

  return art ? stillImage({ src: art.src, scale: 1 }) : null
}

/** Whether a tile falls inside a placement — i.e. whether artwork is already covering it. */
export function coveredBy(placements: BackdropPlacement[] | undefined, x: number, y: number): boolean {
  if (!placements?.length) return false

  for (const p of placements) {
    if (x >= p.x && x < p.x + p.w && y >= p.y && y < p.y + p.h) return true
  }

  return false
}

/**
 * Paint one placement.
 *
 * `topLeft` and `bottomRight` are the outer corners of *its rectangle* already in screen pixels,
 * so this needs to know nothing about the camera or the projection — which matters, because it
 * must line up with tiles drawn by a projection it has no opinion about.
 *
 * Smoothing is off for the same reason it's off everywhere else: this is pixel art, and a
 * browser's default filter turns a hard-edged street into a smear at any zoom but exactly 1.
 *
 * Returns whether it drew, so the caller can fall through to painting tiles in one line. A
 * backdrop that hasn't finished loading draws nothing and the room comes up as its tile art,
 * which is a room rather than a blank canvas for the one frame it takes to arrive.
 */
export function drawBackdrop(
  ctx: CanvasRenderingContext2D,
  backdrop: Backdrop,
  topLeft: { x: number, y: number },
  bottomRight: { x: number, y: number },
): boolean {
  const img = stillImage({ src: backdrop.src, scale: 1 })
  if (!img) return false

  ctx.save()
  ctx.imageSmoothingEnabled = false
  ctx.drawImage(img, topLeft.x, topLeft.y, bottomRight.x - topLeft.x, bottomRight.y - topLeft.y)
  ctx.restore()

  return true
}
