/**
 * The drawing half of a side chat's shared whiteboard — deliberately framework-agnostic so
 * its geometry can be reasoned about (and unit-tested) under plain Node, with the Vue card
 * only feeding it a canvas, pointer input, and the strokes to paint. It's the whiteboard
 * sibling of {@link file://./raceEngine.ts raceEngine} / {@link file://./squadronEngine.ts
 * squadronEngine}, and shares their honesty rule: everyone paints the exact same board from the
 * exact same data.
 *
 * The one thing that makes a *shared* board work is a coordinate system that doesn't depend
 * on anyone's panel size. All stroke coordinates live in a **logical space** whose width is
 * fixed at {@link LOGICAL_WIDTH}; each client scales that to its own canvas width. So a mark
 * at logical x=500 sits at the horizontal midpoint for everyone, whatever their column
 * width, without anyone streaming pixels — the same "deterministic world, local render"
 * trick the games use for their tracks and arenas.
 */

/** The board's logical width. A client renders at `scale = cssWidth / LOGICAL_WIDTH`. */
export const LOGICAL_WIDTH = 1000

/**
 * `bg` is the board's own backdrop rather than a mark on it: one full-surface wash, painted
 * before everything else and hit-testable by nothing (you reach it by clicking empty board
 * with the fill tool, not by clicking "it").
 */
export type StrokeKind = 'pen' | 'rect' | 'ellipse' | 'line' | 'arrow' | 'text' | 'note' | 'bg'

export interface Point { x: number, y: number }

/** A stroke's geometry + style, in logical coordinates. Which fields matter depends on kind. */
export interface StrokePayload {
  color?: string
  fill?: string
  width?: number
  text?: string
  points?: Point[]
  x1?: number
  y1?: number
  x2?: number
  y2?: number
  x?: number
  y?: number
}

export interface Stroke {
  kind: StrokeKind
  payload: StrokePayload
}

const DEFAULT_COLOR = '#111827'
const DEFAULT_WIDTH = 3
/** Default logical side length of a sticky note. A note may override it via `payload.w`. */
export const NOTE_SIZE = 150
/** Sticky-note text scales with the note so a big note reads big; clamped to sane bounds. */
function noteFont(side: number): number {
  return Math.max(11, Math.min(40, side * 0.093))
}

/** Distance from point p to the segment ab, in the same units as its inputs. */
export function distToSegment(p: Point, a: Point, b: Point): number {
  const dx = b.x - a.x
  const dy = b.y - a.y
  const len2 = dx * dx + dy * dy
  if (len2 === 0) return Math.hypot(p.x - a.x, p.y - a.y)
  let t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / len2
  t = Math.max(0, Math.min(1, t))
  return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy))
}

/**
 * Ramer–Douglas–Peucker: drop points that don't change the shape, so a committed pen path
 * is a handful of vertices rather than the hundreds a pointer emits. Kept well under the
 * server's per-stroke point cap, and small enough that a whispered live preview never
 * approaches Reverb's client-event size limit.
 */
export function simplify(points: Point[], tolerance = 1.5): Point[] {
  if (points.length <= 2) return points.slice()

  const first = points[0]!
  const last = points[points.length - 1]!
  let maxDist = 0
  let index = 0
  for (let i = 1; i < points.length - 1; i++) {
    const d = distToSegment(points[i]!, first, last)
    if (d > maxDist) {
      maxDist = d
      index = i
    }
  }

  if (maxDist > tolerance) {
    const left = simplify(points.slice(0, index + 1), tolerance)
    const right = simplify(points.slice(index), tolerance)
    return [...left.slice(0, -1), ...right]
  }
  return [first, last]
}

/** The logical-space bounding box of a stroke, or null if it has no geometry. */
export function boundingBox(stroke: Stroke): { x: number, y: number, w: number, h: number } | null {
  const p = stroke.payload
  // The backdrop is everywhere, which is the same as nowhere for anything that measures the
  // board's extent or draws a selection around a mark.
  if (stroke.kind === 'bg') return null
  if (stroke.kind === 'pen') {
    const pts = p.points ?? []
    if (!pts.length) return null
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
    for (const pt of pts) {
      minX = Math.min(minX, pt.x); minY = Math.min(minY, pt.y)
      maxX = Math.max(maxX, pt.x); maxY = Math.max(maxY, pt.y)
    }
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY }
  }
  if (stroke.kind === 'note') {
    const side = p.w ?? NOTE_SIZE
    return { x: p.x ?? 0, y: p.y ?? 0, w: side, h: side }
  }
  if (stroke.kind === 'text') {
    // Approximate the rendered box from the font size and text length — enough for the
    // selection outline and eraser; exact metrics would need a canvas measurement.
    const font = p.width ?? 16
    const w = Math.max(font, (p.text?.length ?? 4) * font * 0.55)
    return { x: p.x ?? 0, y: p.y ?? 0, w, h: font * 1.3 }
  }
  const x1 = p.x1 ?? 0, y1 = p.y1 ?? 0, x2 = p.x2 ?? 0, y2 = p.y2 ?? 0
  return { x: Math.min(x1, x2), y: Math.min(y1, y2), w: Math.abs(x2 - x1), h: Math.abs(y2 - y1) }
}

/**
 * Does a logical point land on a stroke? Used by the eraser, which removes whole strokes
 * rather than pixels. `threshold` is the forgiveness radius in logical units.
 */
export function hitStroke(stroke: Stroke, point: Point, threshold = 8): boolean {
  const p = stroke.payload
  switch (stroke.kind) {
    case 'pen': {
      const pts = p.points ?? []
      const pad = threshold + (p.width ?? DEFAULT_WIDTH) / 2
      for (let i = 1; i < pts.length; i++) {
        if (distToSegment(point, pts[i - 1]!, pts[i]!) <= pad) return true
      }
      // A single-dot pen stroke.
      return pts.length === 1 && Math.hypot(point.x - pts[0]!.x, point.y - pts[0]!.y) <= pad
    }
    case 'line':
    case 'arrow':
      return distToSegment(point, { x: p.x1 ?? 0, y: p.y1 ?? 0 }, { x: p.x2 ?? 0, y: p.y2 ?? 0 })
        <= threshold + (p.width ?? DEFAULT_WIDTH) / 2
    // Never picked by a click: the eraser must not swallow the backdrop out from under the
    // board, and the fill tool reaches it by finding *nothing* under the cursor.
    case 'bg':
      return false
    case 'rect':
    case 'ellipse':
    case 'text':
    case 'note': {
      const box = boundingBox(stroke)
      if (!box) return false
      return point.x >= box.x - threshold && point.x <= box.x + box.w + threshold
        && point.y >= box.y - threshold && point.y <= box.y + box.h + threshold
    }
  }
}

function line(ctx: CanvasRenderingContext2D, a: Point, b: Point, s: number) {
  ctx.beginPath()
  ctx.moveTo(a.x * s, a.y * s)
  ctx.lineTo(b.x * s, b.y * s)
  ctx.stroke()
}

/** Paint one stroke onto a 2D context, scaling logical coordinates by `scale`. */
export function renderStroke(ctx: CanvasRenderingContext2D, stroke: Stroke, scale: number) {
  const p = stroke.payload
  const color = p.color ?? DEFAULT_COLOR
  const width = (p.width ?? DEFAULT_WIDTH) * scale

  ctx.save()
  ctx.strokeStyle = color
  ctx.fillStyle = color
  ctx.lineWidth = width
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'

  switch (stroke.kind) {
    case 'bg': {
      // The canvas' own pixels, not logical units — the wash covers the whole surface however
      // far it has grown, and the device-pixel size is always at least the css one.
      ctx.fillStyle = p.color ?? '#ffffff'
      ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height)
      break
    }
    case 'pen': {
      const pts = p.points ?? []
      if (pts.length === 1) {
        ctx.beginPath()
        ctx.arc(pts[0]!.x * scale, pts[0]!.y * scale, Math.max(width / 2, 1), 0, Math.PI * 2)
        ctx.fill()
        break
      }
      ctx.beginPath()
      pts.forEach((pt, i) => {
        const x = pt.x * scale
        const y = pt.y * scale
        i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y)
      })
      ctx.stroke()
      break
    }
    case 'rect': {
      const box = boundingBox(stroke)!
      if (p.fill) { ctx.fillStyle = p.fill; ctx.fillRect(box.x * scale, box.y * scale, box.w * scale, box.h * scale) }
      ctx.strokeRect(box.x * scale, box.y * scale, box.w * scale, box.h * scale)
      break
    }
    case 'ellipse': {
      const box = boundingBox(stroke)!
      ctx.beginPath()
      ctx.ellipse((box.x + box.w / 2) * scale, (box.y + box.h / 2) * scale, (box.w / 2) * scale, (box.h / 2) * scale, 0, 0, Math.PI * 2)
      if (p.fill) { ctx.fillStyle = p.fill; ctx.fill() }
      ctx.stroke()
      break
    }
    case 'line':
      line(ctx, { x: p.x1 ?? 0, y: p.y1 ?? 0 }, { x: p.x2 ?? 0, y: p.y2 ?? 0 }, scale)
      break
    case 'arrow': {
      const a = { x: p.x1 ?? 0, y: p.y1 ?? 0 }
      const b = { x: p.x2 ?? 0, y: p.y2 ?? 0 }
      line(ctx, a, b, scale)
      const angle = Math.atan2(b.y - a.y, b.x - a.x)
      const head = 12 * scale + width
      ctx.beginPath()
      ctx.moveTo(b.x * scale, b.y * scale)
      ctx.lineTo(b.x * scale - head * Math.cos(angle - Math.PI / 6), b.y * scale - head * Math.sin(angle - Math.PI / 6))
      ctx.moveTo(b.x * scale, b.y * scale)
      ctx.lineTo(b.x * scale - head * Math.cos(angle + Math.PI / 6), b.y * scale - head * Math.sin(angle + Math.PI / 6))
      ctx.stroke()
      break
    }
    case 'text': {
      const size = (p.width ?? 16) * scale
      ctx.font = `${size}px ui-sans-serif, system-ui, sans-serif`
      ctx.textBaseline = 'top'
      ctx.fillText(p.text ?? '', (p.x ?? 0) * scale, (p.y ?? 0) * scale)
      break
    }
    case 'note': {
      const sideLogical = p.w ?? NOTE_SIZE
      const x = (p.x ?? 0) * scale
      const y = (p.y ?? 0) * scale
      const side = sideLogical * scale
      ctx.fillStyle = p.color ?? '#fde68a'
      ctx.fillRect(x, y, side, side)
      ctx.fillStyle = '#1f2937'
      const size = noteFont(sideLogical) * scale
      ctx.font = `${size}px ui-sans-serif, system-ui, sans-serif`
      ctx.textBaseline = 'top'
      wrapText(ctx, p.text ?? '', x + 8 * scale, y + 8 * scale, side - 16 * scale, size * 1.3)
      break
    }
  }
  ctx.restore()
}

/** Naive word-wrap for sticky-note text, so a note stays inside its square. */
function wrapText(ctx: CanvasRenderingContext2D, text: string, x: number, y: number, maxWidth: number, lineHeight: number) {
  let line = ''
  let cursorY = y
  for (const word of text.split(/\s+/)) {
    const test = line ? `${line} ${word}` : word
    if (ctx.measureText(test).width > maxWidth && line) {
      ctx.fillText(line, x, cursorY)
      line = word
      cursorY += lineHeight
    } else {
      line = test
    }
  }
  if (line) ctx.fillText(line, x, cursorY)
}

/** Repaint the whole board. Clears first, then paints strokes in order (oldest underneath). */
export function renderBoard(ctx: CanvasRenderingContext2D, strokes: Stroke[], scale: number, cssWidth: number, cssHeight: number) {
  ctx.clearRect(0, 0, cssWidth, cssHeight)
  for (const stroke of strokes) renderStroke(ctx, stroke, scale)
}
