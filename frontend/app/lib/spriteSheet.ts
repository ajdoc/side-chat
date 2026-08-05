/**
 * Sprites that come from a PNG rather than from a grid of characters.
 *
 * Everything else drawn in a Side Space is authored here in code — sixteen rows of sixteen
 * letters, painted through a palette by {@link file://./pixelSprite.ts pixelSprite}. That is the
 * right way to hold artwork you *wrote*: it diffs, it recolours, and a costume costs a constant
 * rather than a binary. It is the wrong way to hold artwork you were *handed*, which is what this
 * is for.
 *
 * ## The layout
 *
 * The sheets follow the PMD / SpriteCollab convention, because that is the shape the artwork
 * arrives in: one image per animation, frames left to right, **eight directions top to bottom**
 * in a fixed order. Each set also ships `-Offsets` and `-Shadow` sheets, which are ignored — they
 * position held items and the drop shadow, and the room draws its own shadow already.
 *
 * The frame size is *derived* from the image rather than declared, so a sheet re-exported at a
 * different resolution keeps working: divide the width by the column count and the height by
 * eight. That is also the only reason this needs no `AnimData.xml`.
 *
 * ## Loading
 *
 * The room draws sixty times a second and cannot await anything, so this is a cache with a
 * synchronous read: ask for a sheet and you get the image if it has arrived, or null if it
 * hasn't (and the load starts on the first ask). Callers draw their fallback on null, which is
 * what makes a missing file a hand-drawn Espurr rather than a hole in the room. A failed load is
 * remembered as failed — retrying every frame would be sixty requests a second for a 404.
 */

/** Where the artwork lives, relative to the public root. */
export const SHEET_ROOT = '/sprites'

/**
 * The eight rows, in the order PMD sheets store them. Only four are ever asked for — the room
 * has no diagonals — but the *indices* have to be right, so the whole order is written down.
 */
const DIRECTION_ROWS = ['down', 'downright', 'right', 'upright', 'up', 'upleft', 'left', 'downleft'] as const

export const SHEET_ROWS = DIRECTION_ROWS.length

/** Which row of a sheet holds a given facing. */
export function sheetRow(facing: 'up' | 'down' | 'left' | 'right'): number {
  return DIRECTION_ROWS.indexOf(facing)
}

export interface SheetSpec {
  /** Path under {@link SHEET_ROOT}, without the extension — e.g. `espurr/Walk`. */
  name: string
  /** Frames per direction. */
  columns: number
  /**
   * How tall to draw one frame, in tiles.
   *
   * Not derived, because it can't be: a sheet's frames are padded by however much the artist
   * needed, so the pixel height says nothing about how big the creature should look standing in
   * a room. One number per sheet, tuned by eye against the sprites already in the room.
   */
  scale: number
}

/** url → the image, `null` while it's loading, `false` once it has failed. */
const cache = new Map<string, HTMLImageElement | null | false>()

/**
 * Who wants to know when a sheet finishes arriving.
 *
 * The room needs none of this — it repaints sixty times a second, so a sheet that lands mid-frame
 * is simply drawn on the next one. A *still* picture does need it: the appearance dialog paints
 * its previews once on mount, and without a nudge a sheet that arrived a moment later would show
 * the fallback art until something else happened to trigger a repaint.
 */
const loadListeners = new Set<() => void>()

/** Call `listen` whenever a sheet becomes drawable. Returns the unsubscribe. */
export function onSheetLoaded(listen: () => void): () => void {
  loadListeners.add(listen)

  return () => loadListeners.delete(listen)
}

function urlFor(spec: SheetSpec): string {
  return `${SHEET_ROOT}/${spec.name}-Anim.png`
}

/**
 * The sheet's image if it's ready to draw, else null.
 *
 * Starts the load on the first call. Never throws and never waits — see the note above about
 * being called from a render loop.
 */
export function sheetImage(spec: SheetSpec): HTMLImageElement | null {
  return imageAt(urlFor(spec))
}

/**
 * A still picture: one image, no frames, no directions.
 *
 * The other shape artwork arrives in. A sheet is for something that *moves* — it has to declare
 * its columns and it is read as eight rows of directions — and a great deal of what a room is
 * made of doesn't move and has one view: a throne, a statue, a sign. Making those pretend to be
 * a one-frame animation would mean an eight-row PNG that is seven-eighths empty, and a `columns`
 * count that means nothing.
 *
 * Same cache and the same rule about a missing file: it falls back to whatever the caller would
 * have drawn anyway.
 */
export interface StillSpec {
  /** Path under {@link SHEET_ROOT}, *with* the extension — e.g. `decor/iron-throne.png`. */
  src: string
  /** How tall to draw it, in tiles. Width follows from the image's own aspect ratio. */
  scale: number
}

export function stillImage(spec: StillSpec): HTMLImageElement | null {
  return imageAt(`${SHEET_ROOT}/${spec.src}`)
}

/**
 * Draw a still, anchored at the bottom centre of `px, py` — the same anchor every sprite in the
 * room uses, so a piece of furniture stands on its tiles rather than hovering over them.
 *
 * Returns whether it drew anything, so a caller can fall through to its own artwork in one line.
 */
export function drawStill(
  ctx: CanvasRenderingContext2D,
  spec: StillSpec,
  px: number,
  py: number,
  size: number,
): boolean {
  const img = stillImage(spec)
  if (!img) return false

  const dh = size * spec.scale
  const dw = dh * (img.width / img.height)

  ctx.drawImage(img, px - dw / 2, py - dh, dw, dh)

  return true
}

/**
 * The cache behind both. Synchronous read, load started on the first ask.
 *
 * Never throws and never waits — see the note at the top about being called from a render loop.
 * A file nobody has added yet is the expected case rather than an error worth reporting, and a
 * failed load is remembered as failed: retrying every frame would be sixty requests a second
 * for a 404.
 */
function imageAt(url: string): HTMLImageElement | null {
  if (!import.meta.client) return null

  const held = cache.get(url)

  if (held !== undefined) return held || null

  cache.set(url, null)

  const img = new Image()
  img.onload = () => {
    cache.set(url, img)
    for (const listen of loadListeners) listen()
  }
  img.onerror = () => cache.set(url, false)
  img.src = url

  return null
}

/** Whether this sheet is on disk and drawable — what a caller checks before skipping its fallback. */
export function sheetReady(spec: SheetSpec): boolean {
  return sheetImage(spec) !== null
}

/**
 * Draw one frame, anchored at the bottom centre of `px, py` — the same anchor every sprite in
 * the room uses, so a creature stands on its tile rather than hovering over it.
 *
 * `frame` and `row` are taken modulo the sheet's real dimensions, so a walk cycle that assumed
 * four frames still draws something sensible against a sheet exported with six.
 */
export function drawSheetFrame(
  ctx: CanvasRenderingContext2D,
  spec: SheetSpec,
  frame: number,
  row: number,
  px: number,
  py: number,
  size: number,
  mirror = false,
): boolean {
  const img = sheetImage(spec)
  if (!img) return false

  const fw = img.width / spec.columns
  const fh = img.height / SHEET_ROWS

  const sx = (((frame % spec.columns) + spec.columns) % spec.columns) * fw
  const sy = (((row % SHEET_ROWS) + SHEET_ROWS) % SHEET_ROWS) * fh

  // Height decides the size and width follows it, so a sheet whose frames are wider than they
  // are tall doesn't come out as a squashed creature.
  const dh = size * spec.scale
  const dw = dh * (fw / fh)

  ctx.save()
  ctx.imageSmoothingEnabled = false
  ctx.translate(px, py)
  if (mirror) ctx.scale(-1, 1)
  ctx.drawImage(img, sx, sy, fw, fh, -dw / 2, -dh, dw, dh)
  ctx.restore()

  return true
}
