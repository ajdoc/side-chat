/**
 * What a sticker is made of.
 *
 * A background shape plus a list of freehand paths, and nothing else. That narrowness is the
 * design: a sticker is a doodle somebody makes in twenty seconds and slaps on a wall, so the
 * editor has to be learnable at a glance. A general drawing tool already exists — it's the
 * Board — and the two shouldn't converge.
 *
 * The shape is stored as free-form JSON (`app_stickers.content`), so this file is the only
 * definition of it. Everything that reads or writes a sticker goes through here.
 */

export type StickerShape =
  | 'square' | 'wide' | 'tall' | 'circle' | 'diamond' | 'heart'
  | 'star' | 'burst' | 'ellipse' | 'triangle' | 'pentagon' | 'hexagon' | 'none'

export interface StickerPath {
  /** Points in the sticker's own 0–100 space, so a sticker scales without redrawing. */
  points: Array<[number, number]>
  color: string
  width: number
}

/**
 * A named group of paths, drawn in order.
 *
 * The same idea as the Board's layers and a different implementation on purpose: a board's
 * layers are shared state that people hide from each other, so they live in a column and
 * broadcast. A sticker is made by one person in one sitting, so its layers are just part of the
 * drawing — no endpoint, no sync, nothing to reconcile.
 *
 * What they buy is the thing that makes a sticker maker feel like a tool rather than a toy:
 * outline on one layer, colour on another, so you can redo the colour without redrawing the
 * outline.
 */
export interface StickerLayer {
  name: string
  visible: boolean
  paths: StickerPath[]
}

export interface StickerContent {
  shape: StickerShape
  fill: string
  fillOpacity: number
  stroke: string
  /**
   * The drawing, oldest layer first.
   *
   * `paths` is the pre-layers shape and is still read — see {@link stickerLayers}. Stickers
   * drawn before layers existed have a flat `paths` array and no `layers`, and they must keep
   * rendering exactly as they did.
   */
  layers?: StickerLayer[]
  /** @deprecated Superseded by `layers`; still read for stickers drawn before them. */
  paths?: StickerPath[]
  /** A short caption drawn across the middle. Optional — most stickers are just a drawing. */
  text?: string
  textColor?: string
}

/**
 * A sticker's layers, however it was stored.
 *
 * The one place that knows about the old flat `paths` shape. Everything else — the renderer,
 * the editor — reads this and never sees the difference.
 */
export function stickerLayers(content: StickerContent): StickerLayer[] {
  if (content.layers?.length) return content.layers
  return [{ name: 'Layer 1', visible: true, paths: content.paths ?? [] }]
}

export const STICKER_SHAPES: { id: StickerShape, label: string }[] = [
  { id: 'square', label: 'Square' },
  { id: 'wide', label: 'Wide' },
  { id: 'tall', label: 'Tall' },
  { id: 'circle', label: 'Circle' },
  { id: 'diamond', label: 'Diamond' },
  { id: 'heart', label: 'Heart' },
  { id: 'star', label: 'Star' },
  { id: 'burst', label: 'Burst' },
  { id: 'ellipse', label: 'Ellipse' },
  { id: 'triangle', label: 'Triangle' },
  { id: 'pentagon', label: 'Pentagon' },
  { id: 'hexagon', label: 'Hexagon' },
  { id: 'none', label: 'Transparent' },
]

/**
 * A detached, plain copy of anything a sticker is made of.
 *
 * `structuredClone` is the obvious tool and the wrong one, for the same reason the whiteboard
 * documents next to its own copy helper: anything reached through a `ref` is a Vue reactive
 * *proxy*, and the structured-clone algorithm refuses proxies outright — "could not be cloned",
 * thrown from the middle of an undo. `toRaw` doesn't save it either, since it unwraps only the
 * top level and every element of a layer array is still a proxy.
 *
 * A sticker is plain JSON by construction — strings, numbers and lists of points — so a round
 * trip through JSON is both sufficient and immune to what it's wrapped in.
 *
 * @see snapshot in useWhiteboard, which is the same fix for the same reason.
 */
/**
 * How big a sticker's drawing may get, serialised.
 *
 * A wall is a collage of doodles, not a file host, and an unbounded blob is a row that gets
 * slower to load for everybody forever. 24KB is roughly a very busy sticker — a few hundred
 * simplified strokes — and well under what a request should carry.
 *
 * Deliberately larger than the 10KB event cap: the drawing never travels over the socket (see
 * AppStickerResource), so the two limits are unrelated.
 */
export const MAX_STICKER_BYTES = 24_000

/** Serialised size of a sticker's drawing, for the editor's guard. */
export function stickerSize(content: StickerContent): number {
  return JSON.stringify(content).length
}

export function plainCopy<T>(value: T): T {
  return JSON.parse(JSON.stringify(value))
}

export function emptySticker(): StickerContent {
  return {
    shape: 'square',
    fill: '#ffffff',
    fillOpacity: 1,
    stroke: '#000000',
    layers: [{ name: 'Layer 1', visible: true, paths: [] }],
  }
}

/**
 * A shape as an SVG path in a 0–100 box.
 *
 * Paths rather than `<circle>`/`<polygon>` elements so every shape renders through one code
 * path — the wall draws hundreds of these, and one element type means one renderer.
 */
export function shapePath(shape: StickerShape): string {
  switch (shape) {
    case 'square': return 'M4 4 H96 V96 H4 Z'
    case 'wide': return 'M2 24 H98 V76 H2 Z'
    case 'tall': return 'M24 2 H76 V98 H24 Z'
    case 'circle': return 'M50 4 A46 46 0 1 1 49.9 4 Z'
    case 'ellipse': return 'M50 22 A44 28 0 1 1 49.9 22 Z'
    case 'diamond': return 'M50 3 L97 50 L50 97 L3 50 Z'
    case 'triangle': return 'M50 6 L95 92 H5 Z'
    case 'pentagon': return 'M50 4 L95 37 L78 92 H22 L5 37 Z'
    case 'hexagon': return 'M28 6 H72 L95 50 L72 94 H28 L5 50 Z'
    case 'heart':
      return 'M50 92 C18 68 6 50 6 33 C6 18 18 8 30 8 C39 8 46 13 50 21 C54 13 61 8 70 8 C82 8 94 18 94 33 C94 50 82 68 50 92 Z'
    case 'star':
      return 'M50 4 L61 36 H95 L67 56 L78 90 L50 69 L22 90 L33 56 L5 36 H39 Z'
    case 'burst':
      return 'M50 2 L58 18 L74 8 L74 26 L92 24 L83 40 L98 50 L83 60 L92 76 L74 74 L74 92 L58 82 L50 98 L42 82 L26 92 L26 74 L8 76 L17 60 L2 50 L17 40 L8 24 L26 26 L26 8 L42 18 Z'
    case 'none': return ''
  }
}

/**
 * Drop points a stroke doesn't need — Ramer–Douglas–Peucker, on the 0–100 space.
 *
 * A pointer emits a sample every few milliseconds, so a single flick is easily 200 points
 * describing a line that four would draw identically. Left alone that is most of a sticker's
 * weight, in the database, in every wall load, and in the editor's undo snapshots.
 *
 * Run once on save rather than while drawing: simplifying mid-stroke fights the pen, and the
 * cost of the full-resolution copy is only borne until you press Place.
 *
 * The tolerance is in the same units the points are — 0–100 across the whole sticker — so 0.35
 * is about a third of a percent of its width, below what any wall zoom can show.
 */
export function simplifyPoints(points: Array<[number, number]>, tolerance = 0.35): Array<[number, number]> {
  if (points.length <= 2) return points

  const first = points[0]!
  const last = points[points.length - 1]!

  let maxDist = 0
  let index = 0

  for (let i = 1; i < points.length - 1; i++) {
    const d = perpendicularDistance(points[i]!, first, last)
    if (d > maxDist) {
      maxDist = d
      index = i
    }
  }

  if (maxDist <= tolerance) return [first, last]

  return [
    ...simplifyPoints(points.slice(0, index + 1), tolerance).slice(0, -1),
    ...simplifyPoints(points.slice(index), tolerance),
  ]
}

function perpendicularDistance(p: [number, number], a: [number, number], b: [number, number]): number {
  const dx = b[0] - a[0]
  const dy = b[1] - a[1]
  const lenSq = dx * dx + dy * dy

  // A degenerate segment (the stroke doubled back to where it started) has no perpendicular —
  // fall back to plain distance from the point, which is what the caller means by "how far off
  // the line is this".
  if (lenSq === 0) return Math.hypot(p[0] - a[0], p[1] - a[1])

  const t = Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / lenSq))

  return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy))
}

/**
 * A sticker with every stroke simplified and every coordinate rounded to one decimal.
 *
 * One decimal is a tenth of a percent of the sticker's width — finer than a screen pixel at any
 * zoom the wall offers — and it halves the length of a typical coordinate in JSON.
 */
export function compactSticker(content: StickerContent): StickerContent {
  const round = (n: number) => Math.round(n * 10) / 10

  return {
    ...content,
    layers: stickerLayers(content).map(layer => ({
      ...layer,
      paths: layer.paths
        .map(path => ({
          ...path,
          points: simplifyPoints(path.points).map(([x, y]) => [round(x), round(y)] as [number, number]),
        }))
        // A stroke reduced to a single point draws nothing.
        .filter(path => path.points.length >= 2),
    })),
    paths: undefined,
  }
}

/** A freehand path as SVG, in the same 0–100 space. */
export function pathData(path: StickerPath): string {
  if (!path.points.length) return ''
  const [first, ...rest] = path.points
  return `M${first![0]} ${first![1]}` + rest.map(p => ` L${p[0]} ${p[1]}`).join('')
}

/** The palette both the shape fill and the pen draw from. Named for consistency with the app. */
export const STICKER_COLORS = [
  '#ffffff', '#000000', '#ef4444', '#f97316', '#eab308',
  '#22c55e', '#3b82f6', '#8b5cf6', '#ec4899', '#78716c',
]
