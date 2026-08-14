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
