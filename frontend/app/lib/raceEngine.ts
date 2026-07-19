/**
 * A tiny top-down racing engine for the Side Grand Prix widget — the driving half of the
 * game, deliberately framework-agnostic so its maths can be unit-tested under plain Node
 * and the Vue card only has to feed it input, a canvas and rival ghost cars. It's the
 * racing sibling of {@link file://./squadronEngine.ts squadronEngine}, and shares its honesty rules:
 *
 *   - The **track is deterministic** — every client builds the exact same circuit from the
 *     shared per-race `seed` ({@see makeTrack}), so "the fast left-hander before the pit
 *     straight" is the same corner for everyone, without anyone streaming track geometry.
 *   - Rivals are shown as **real ghost cars** from whispered positions (the card's job), so
 *     you genuinely race the people in the channel — the same trick the shooter uses for
 *     teammates.
 *   - Lap counting is **local and cheat-resistant**: a lap only counts if you've passed the
 *     far side of the circuit and then cross the start/finish line going forwards, so you
 *     can't farm laps by reversing over the line.
 *
 * World units are arbitrary track units; angles are radians, 0 = +x, growing clockwise on
 * screen (y grows downward, as canvas does).
 */

export interface Vec { x: number, y: number }

export interface Track {
  /** The closed-loop centerline, sampled evenly enough to drive and to project onto. */
  center: Vec[]
  /** Half the road width — beyond this from the centerline you're on the grass. */
  half: number
  /** Number of centerline samples (the loop's "length" in projection units). */
  length: number
  /** Where a car lines up on the grid, and the heading it starts pointing. */
  start: { x: number, y: number, a: number }
}

/** A rival as we render them: their whispered car pose plus which lap they're on. */
export interface Ghost { id: number, name: string, x: number, y: number, a: number, lap: number }

/** Per-frame intent from the card. `throttle` −1..1 (brake/reverse..accelerate); `steer` −1..1. */
export interface Input { throttle: number, steer: number }

/** What one `update` produced, so the card can drive lap timing / widget actions. */
export interface RaceTick { lapCompleted: boolean, offTrack: boolean }

/** Deterministic RNG (mulberry32): the same seed gives the same track everywhere. */
function rng(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a |= 0
    a = (a + 0x6D2B79F5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

function catmull(p0: Vec, p1: Vec, p2: Vec, p3: Vec, t: number): Vec {
  const t2 = t * t
  const t3 = t2 * t
  return {
    x: 0.5 * ((2 * p1.x) + (-p0.x + p2.x) * t + (2 * p0.x - 5 * p1.x + 4 * p2.x - p3.x) * t2 + (-p0.x + 3 * p1.x - 3 * p2.x + p3.x) * t3),
    y: 0.5 * ((2 * p1.y) + (-p0.y + p2.y) * t + (2 * p0.y - 5 * p1.y + 4 * p2.y - p3.y) * t2 + (-p0.y + 3 * p1.y - 3 * p2.y + p3.y) * t3),
  }
}

const CONTROLS = 11 // wobbly control points spun into a smooth closed loop
const SUB = 9 // Catmull-Rom samples per control segment → ~100 centerline points
const RADIUS = 230
const HALF = 34

/**
 * Build the shared circuit from a seed: a wobbly closed loop of control points, smoothed
 * with Catmull-Rom into an even centerline. The wobble is bounded so every seed is a track
 * you can actually drive, never a hairpin knot.
 */
export function makeTrack(seed: number): Track {
  const rand = rng(seed || 1)
  const ctrl: Vec[] = []
  for (let i = 0; i < CONTROLS; i++) {
    const ang = (i / CONTROLS) * Math.PI * 2
    const r = RADIUS * (0.72 + rand() * 0.5) // 0.72–1.22 × base radius
    ctrl.push({ x: Math.cos(ang) * r, y: Math.sin(ang) * r * 0.82 }) // squash y for a wider-than-tall oval
  }

  const center: Vec[] = []
  for (let i = 0; i < CONTROLS; i++) {
    const p0 = ctrl[(i - 1 + CONTROLS) % CONTROLS]
    const p1 = ctrl[i]
    const p2 = ctrl[(i + 1) % CONTROLS]
    const p3 = ctrl[(i + 2) % CONTROLS]
    for (let s = 0; s < SUB; s++) center.push(catmull(p0, p1, p2, p3, s / SUB))
  }

  // Face the car along the track at the start/finish line (centerline sample 0).
  const a = Math.atan2(center[1].y - center[0].y, center[1].x - center[0].x)
  return { center, half: HALF, length: center.length, start: { x: center[0].x, y: center[0].y, a } }
}

/**
 * Project a point onto the centerline loop. Returns the continuous position `t` along the
 * loop in [0, length) and the perpendicular `dist` to it (the off-track measure). Used both
 * for grass detection and for lap counting.
 */
export function project(track: Track, x: number, y: number): { t: number, dist: number } {
  const c = track.center
  const n = c.length
  let bestD = Infinity
  let bestT = 0
  for (let i = 0; i < n; i++) {
    const a = c[i]
    const b = c[(i + 1) % n]
    const dx = b.x - a.x
    const dy = b.y - a.y
    const len2 = dx * dx + dy * dy || 1e-6
    let u = ((x - a.x) * dx + (y - a.y) * dy) / len2
    u = Math.max(0, Math.min(1, u))
    const px = a.x + dx * u
    const py = a.y + dy * u
    const d = Math.hypot(x - px, y - py)
    if (d < bestD) { bestD = d; bestT = i + u }
  }
  return { t: bestT, dist: bestD }
}

/** A stable, cheery car colour per user id — rivals never blur together. */
export function carColor(id: number): string {
  const hue = (id * 47) % 360
  return `hsl(${hue}, 85%, 58%)`
}

const ACCEL = 260
const REVERSE_ACCEL = 150
const MAX_SPEED = 205
const REVERSE_MAX = 80
const ROLL_DRAG = 0.6 // coasting friction on tarmac, per second
const BRAKE_DRAG = 3.2 // throttle held negative while moving forward = brakes
const GRASS_DRAG = 2.6 // heavy scrub on the grass, per second
const GRASS_MAX = 78 // and a hard speed cap off track
const TURN_RATE = 2.9

/**
 * The stateful car + lap machine: owns one player's car and their lap progress on a given
 * track. The card news one up per canvas, sets the track with {@link start}, then calls
 * {@link update} / {@link render} each frame. Everything shared (best laps, finishing order)
 * lives outside it, in the widget's state.
 */
export class RaceEngine {
  player = { x: 0, y: 0, a: 0, speed: 0 }
  track: Track
  lap = 0
  offTrack = false
  private ctx: CanvasRenderingContext2D
  private w: number
  private h: number
  private zoom = 1.35
  private prevT = 0
  private passedHalf = false
  private started = false

  constructor(ctx: CanvasRenderingContext2D, w: number, h: number, track: Track) {
    this.ctx = ctx
    this.w = w
    this.h = h
    this.track = track
    this.reset()
  }

  /** Drop the car back on the grid line, lap counter and all — used on (re)spawn. */
  reset() {
    const s = this.track.start
    this.player = { x: s.x, y: s.y, a: s.a, speed: 0 }
    this.lap = 0
    this.prevT = 0
    this.passedHalf = false
    this.started = false
    this.offTrack = false
  }

  /** Advance the car one step and report whether it just completed a lap. */
  update(dt: number, input: Input): RaceTick {
    const p = this.player
    const out: RaceTick = { lapCompleted: false, offTrack: false }

    // Throttle / brake.
    if (input.throttle > 0) {
      p.speed += ACCEL * input.throttle * dt
    } else if (input.throttle < 0) {
      // Brakes while rolling forward, reverse once stopped.
      p.speed += (p.speed > 0 ? BRAKE_DRAG * 60 : REVERSE_ACCEL) * input.throttle * dt
    }

    // Steering follows the direction of travel and needs some pace to bite.
    const grip = Math.max(-1, Math.min(1, p.speed / 45))
    p.a += input.steer * TURN_RATE * dt * grip

    // Rolling + surface friction, then clamp to the surface's top speed.
    const onGrass = this.offTrack
    p.speed *= 1 - (onGrass ? GRASS_DRAG : ROLL_DRAG) * dt
    const top = onGrass ? GRASS_MAX : MAX_SPEED
    p.speed = Math.max(-REVERSE_MAX, Math.min(top, p.speed))
    if (Math.abs(p.speed) < 0.5) p.speed = 0

    p.x += Math.cos(p.a) * p.speed * dt
    p.y += Math.sin(p.a) * p.speed * dt

    // Where are we on the loop now — grass check + lap check.
    const { t, dist } = project(this.track, p.x, p.y)
    this.offTrack = out.offTrack = dist > this.track.half
    const len = this.track.length

    if (t > len * 0.35 && t < len * 0.65) this.passedHalf = true
    if (this.started && this.passedHalf && this.prevT > len * 0.78 && t < len * 0.22) {
      this.lap++
      this.passedHalf = false
      out.lapCompleted = true
    }
    this.prevT = t
    this.started = true
    return out
  }

  /** How far the car has to lean into the current corner — for a little chassis roll on screen. */
  speedFrac(): number {
    return Math.max(0, Math.min(1, this.player.speed / MAX_SPEED))
  }

  /** Draw one frame: grass, the tarmac ribbon, the start/finish line, rivals, then our car. */
  render(ghosts: Ghost[], now: number) {
    const { ctx, w, h } = this
    const p = this.player

    // Grass.
    ctx.fillStyle = '#14351f'
    ctx.fillRect(0, 0, w, h)

    ctx.save()
    // Camera: centre on the car, look "up" the direction it's heading.
    ctx.translate(w / 2, h * 0.62)
    ctx.scale(this.zoom, this.zoom)
    ctx.rotate(-p.a - Math.PI / 2)
    ctx.translate(-p.x, -p.y)

    this.drawTrack(now)
    for (const g of ghosts) this.drawCar(g.x, g.y, g.a, carColor(g.id), g.name)
    this.drawCar(p.x, p.y, p.a, '#f8fafc', null, true)

    ctx.restore()
  }

  private drawTrack(now: number) {
    const { ctx, track } = this
    const c = track.center

    const path = () => {
      ctx.beginPath()
      ctx.moveTo(c[0].x, c[0].y)
      for (let i = 1; i < c.length; i++) ctx.lineTo(c[i].x, c[i].y)
      ctx.closePath()
    }

    ctx.lineJoin = 'round'
    ctx.lineCap = 'round'

    // Kerb / verge.
    ctx.strokeStyle = '#3b4252'
    ctx.lineWidth = track.half * 2 + 8
    path()
    ctx.stroke()

    // Tarmac.
    ctx.strokeStyle = '#2b2f36'
    ctx.lineWidth = track.half * 2
    path()
    ctx.stroke()

    // Centre dashes.
    ctx.strokeStyle = 'rgba(250, 204, 21, 0.35)'
    ctx.lineWidth = 2
    ctx.setLineDash([14, 20])
    path()
    ctx.stroke()
    ctx.setLineDash([])

    // Start / finish line — a checkered band across the road at sample 0.
    const a = c[0]
    const b = c[1 % c.length]
    const ang = Math.atan2(b.y - a.y, b.x - a.x)
    ctx.save()
    ctx.translate(a.x, a.y)
    ctx.rotate(ang)
    const rows = 2
    const cols = 8
    const cw = (track.half * 2) / cols
    for (let r = 0; r < rows; r++) {
      for (let col = 0; col < cols; col++) {
        ctx.fillStyle = (r + col) % 2 ? '#f8fafc' : '#0b0e13'
        ctx.fillRect(r * cw, -track.half + col * cw, cw, cw)
      }
    }
    ctx.restore()
  }

  /** A little top-down car: body, a bright nose, and a name tag for rivals. */
  private drawCar(x: number, y: number, a: number, color: string, name: string | null, isSelf = false) {
    const ctx = this.ctx
    ctx.save()
    ctx.translate(x, y)
    ctx.rotate(a)

    const L = 20
    const W = 11
    // Shadow.
    ctx.fillStyle = 'rgba(0,0,0,0.35)'
    this.roundRect(-L / 2 + 1.5, -W / 2 + 2, L, W, 3)
    ctx.fill()
    // Body.
    ctx.fillStyle = color
    this.roundRect(-L / 2, -W / 2, L, W, 3)
    ctx.fill()
    // Windscreen.
    ctx.fillStyle = 'rgba(15, 23, 42, 0.75)'
    this.roundRect(-1, -W / 2 + 1.5, 6, W - 3, 1.5)
    ctx.fill()
    // Nose highlight (points forward, +x).
    ctx.fillStyle = isSelf ? '#38bdf8' : 'rgba(255,255,255,0.85)'
    this.roundRect(L / 2 - 3, -W / 2 + 1.5, 2.5, W - 3, 1)
    ctx.fill()
    ctx.restore()

    if (name) {
      ctx.save()
      ctx.translate(x, y)
      ctx.rotate(this.player.a + Math.PI / 2) // keep the tag upright against the camera
      ctx.font = 'bold 9px system-ui, sans-serif'
      ctx.textAlign = 'center'
      ctx.fillStyle = 'rgba(0,0,0,0.55)'
      ctx.fillText(name, 0, -15)
      ctx.fillStyle = color
      ctx.fillText(name, 0, -16)
      ctx.restore()
    }
  }

  private roundRect(x: number, y: number, w: number, h: number, r: number) {
    const ctx = this.ctx
    ctx.beginPath()
    ctx.moveTo(x + r, y)
    ctx.arcTo(x + w, y, x + w, y + h, r)
    ctx.arcTo(x + w, y + h, x, y + h, r)
    ctx.arcTo(x, y + h, x, y, r)
    ctx.arcTo(x, y, x + w, y, r)
    ctx.closePath()
  }
}
