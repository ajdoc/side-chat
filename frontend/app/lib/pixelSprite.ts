/**
 * Pixel art, written as text and rasterised once.
 *
 * Everything drawn inside a Side Space that isn't ground — trainers, pets, furniture — is
 * authored the same way: a list of strings where each character names a palette slot, `.` being
 * transparent. It's the only form in which pixel art stays legible in a source file, and it
 * diffs like code rather than like a binary.
 *
 * The rasteriser exists because the naive version is genuinely expensive. A 16×16 sprite is 256
 * one-pixel `fillRect`s; a room with fifty people, their pets and forty pieces of furniture would
 * be north of twenty thousand fills a frame, for a picture that never changes. So each *variant*
 * — the same grid with the same palette — is drawn once into its own little canvas and thereafter
 * blitted with a single `drawImage`.
 *
 * The cache key is therefore whatever changes the pixels, and callers are responsible for saying
 * so honestly: get it wrong in one direction and you rasterise every frame, in the other and
 * everybody wears the same shirt.
 */

/** How many device pixels each sprite pixel is rasterised at. 4× survives any sane zoom. */
const SCALE = 4

/** Bounded by how many distinct sprites a room can hold; a few hundred at the very worst. */
const cache = new Map<string, HTMLCanvasElement>()

export interface SpriteLayer {
  /** Rows of palette-slot characters. `.` is transparent, as is any character with no colour. */
  rows: string[]
  /** Slot character → CSS colour. A slot with no entry is skipped, which is how layers mask. */
  palette: Record<string, string>
}

/**
 * Rasterise one or more layers, stacked in order, into a cached canvas.
 *
 * Layering is what makes a customisable avatar tractable: the body is one grid, the hair another
 * drawn over it, and neither has to know about the other's colours. Later layers simply paint
 * over earlier ones — no blending, no alpha, because at this scale a hard edge is the style.
 */
export function sprite(key: string, width: number, height: number, layers: SpriteLayer[]): HTMLCanvasElement {
  const cached = cache.get(key)
  if (cached) return cached

  const canvas = document.createElement('canvas')
  canvas.width = width * SCALE
  canvas.height = height * SCALE

  const ctx = canvas.getContext('2d')!

  for (const layer of layers) {
    for (let y = 0; y < height; y++) {
      const row = layer.rows[y] ?? ''

      for (let x = 0; x < width; x++) {
        const slot = row[x]
        if (!slot || slot === '.') continue

        const colour = layer.palette[slot]
        if (!colour) continue

        ctx.fillStyle = colour
        ctx.fillRect(x * SCALE, y * SCALE, SCALE, SCALE)
      }
    }
  }

  cache.set(key, canvas)

  return canvas
}

/**
 * Blit a rasterised sprite into the world.
 *
 * `imageSmoothingEnabled` goes off for the duration: this is pixel art, and letting the browser
 * interpolate it is exactly the mush the style exists to avoid. `flip` mirrors horizontally,
 * which is how one side-facing grid serves both left and right — drawing the same pixels
 * backwards is free, and maintaining two copies of one sprite is not.
 */
export function blit(
  ctx: CanvasRenderingContext2D,
  canvas: HTMLCanvasElement,
  x: number,
  y: number,
  w: number,
  h: number,
  flip = false,
): void {
  ctx.save()
  ctx.imageSmoothingEnabled = false
  ctx.translate(x, y)

  if (flip) ctx.scale(-1, 1)

  ctx.drawImage(canvas, -w / 2, -h, w, h)
  ctx.restore()
}

/**
 * A small, stable pseudo-random number for a tile — 0…1.
 *
 * Ground detail (which way a tuft of grass leans, where the specks in a path sit) has to look
 * scattered but be *the same scatter* every frame and in every browser, or the floor shimmers as
 * you walk over it. So it's hashed from the coordinates rather than drawn from `Math.random`.
 */
export function tileNoise(x: number, y: number, salt = 0): number {
  const n = Math.sin(x * 127.1 + y * 311.7 + salt * 74.7) * 43758.5453

  return n - Math.floor(n)
}
