/**
 * A tiny DOOM-style raycaster engine for the Side Raid widget — the graphics half of the
 * game, deliberately framework-agnostic so its maths can be unit-tested under plain Node
 * (see raidEngine.test) and the Vue card only has to feed it input, canvas and teammate
 * ghosts.
 *
 * Design notes that make the co-op honest within this app's sync model:
 *   - The **map is fixed** — everyone plays the same arena, so "I'll cover the north
 *     pillar" means the same thing to all of us.
 *   - Enemies spawn **deterministically** from a shared per-raid `seed` plus the wave
 *     number ({@see spawnEnemies}), so every client fighting wave 3 of raid #42 faces the
 *     same demons in the same spots — without anyone streaming enemy positions.
 *   - Enemies are then simulated **locally** (each client's demons chase that client's own
 *     player). That's the deliberate trade: real enemy-position consensus needs a game
 *     server we don't have, whereas teammates are shown as *real* ghosts from whispered
 *     positions. So the arena and the spawn are shared; the chase is your own copy.
 *
 * Coordinates are in map cells (floats); angles are radians, 0 = +x, growing clockwise on
 * screen (y grows downward, as canvas does).
 */

/** The shared arena. `#` is a wall, `.` open floor. Symmetric, with pillars for cover. */
const MAP_ROWS = [
  '####################',
  '#..................#',
  '#..##..........##..#',
  '#..##..........##..#',
  '#..................#',
  '#........##........#',
  '#........##........#',
  '#..................#',
  '#..................#',
  '#..................#',
  '#..................#',
  '#..................#',
  '#........##........#',
  '#........##........#',
  '#..................#',
  '#..##..........##..#',
  '#..##..........##..#',
  '#..................#',
  '#..................#',
  '####################',
]

export const MAP: number[][] = MAP_ROWS.map(r => [...r].map(c => (c === '#' ? 1 : 0)))
export const MAP_W = MAP[0].length
export const MAP_H = MAP.length

/** Where a fresh (or respawning) player drops in — the open centre. */
export const SPAWN = { x: MAP_W / 2, y: MAP_H / 2, dir: -Math.PI / 2 }

export type EnemyKind = 'demon' | 'boss'

export interface Enemy {
  x: number
  y: number
  hp: number
  maxHp: number
  alive: boolean
  hurt: number
  kind: EnemyKind
  /** Half-width for both the aim/hit test and contact range — bigger = easier to hit. */
  radius: number
  speed: number
  /** Damage per second while touching the player. */
  dmg: number
  points: number
}

/** A teammate as we render them: their whispered position + a firing timestamp for flash. */
export interface Ghost { id: number, name: string, x: number, y: number, dir: number, hp: number, firing: number }

/** Per-frame intent from the card, each component in −1..1. */
export interface Input { forward: number, strafe: number, turn: number }

/** What a single `update` produced, so the card can drive networking / widget actions. */
export interface Tick { playerDied: boolean }

/** The result of pulling the trigger — kills (and so wave clears) only ever happen here. */
export interface Shot { killed: boolean, points: number, waveCleared: boolean }

export function isWall(x: number, y: number): boolean {
  const mx = Math.floor(x)
  const my = Math.floor(y)
  if (mx < 0 || my < 0 || mx >= MAP_W || my >= MAP_H) return true
  return MAP[my][mx] === 1
}

/** Deterministic RNG (mulberry32): the same seed + wave gives the same arena everywhere. */
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

/** How many demons a wave throws at you. */
export function waveCount(wave: number): number {
  return 4 + wave * 2
}

/** Every third wave is a boss wave. */
export function isBossWave(wave: number): boolean {
  return wave % 3 === 0
}

/** A grunt demon: dies in two shots, and a touch wider than before so it's easier to hit. */
function makeDemon(x: number, y: number): Enemy {
  return { x, y, hp: 80, maxHp: 80, alive: true, hurt: 0, kind: 'demon', radius: 0.6, speed: 1.5, dmg: 18, points: 100 }
}

/** The boss: a big, slow-but-hard-hitting bullet sponge that scales with the wave. */
function makeBoss(x: number, y: number, wave: number): Enemy {
  const hp = 420 + wave * 110
  return { x, y, hp, maxHp: hp, alive: true, hurt: 0, kind: 'boss', radius: 1.05, speed: 1.05, dmg: 34, points: 600 }
}

/**
 * Spawn a wave's enemies, deterministically, well away from the player's drop point so
 * nothing materialises in your face. Boss waves swap a few grunts for one boss.
 */
export function spawnEnemies(wave: number, seed: number): Enemy[] {
  const rand = rng(seed * 1000 + wave)
  const out: Enemy[] = []
  const boss = isBossWave(wave)
  const demons = boss ? Math.max(3, waveCount(wave) - 3) : waveCount(wave)
  const total = demons + (boss ? 1 : 0)
  let guard = 0
  while (out.length < total && guard++ < 4000) {
    const x = 1 + rand() * (MAP_W - 2)
    const y = 1 + rand() * (MAP_H - 2)
    if (isWall(x, y)) continue
    if (Math.hypot(x - SPAWN.x, y - SPAWN.y) < 4) continue
    // The boss goes in last, so its slot is deterministic too.
    out.push(boss && out.length === demons ? makeBoss(x, y, wave) : makeDemon(x, y))
  }
  return out
}

/** Move a point by (dx,dy) but slide along walls instead of sticking to them. */
export function slideMove(x: number, y: number, dx: number, dy: number): { x: number, y: number } {
  const pad = 0.18
  let nx = x
  let ny = y
  if (!isWall(x + dx + Math.sign(dx) * pad, y)) nx = x + dx
  if (!isWall(nx, y + dy + Math.sign(dy) * pad)) ny = y + dy
  return { x: nx, y: ny }
}

export interface RayHit { dist: number, side: 0 | 1 }

/** DDA raycast from (px,py) along a unit ray to the first wall — the core of the renderer. */
export function castRay(px: number, py: number, rayDirX: number, rayDirY: number): RayHit {
  let mapX = Math.floor(px)
  let mapY = Math.floor(py)
  const deltaX = Math.abs(1 / (rayDirX || 1e-9))
  const deltaY = Math.abs(1 / (rayDirY || 1e-9))
  let stepX: number
  let stepY: number
  let sideX: number
  let sideY: number
  if (rayDirX < 0) { stepX = -1; sideX = (px - mapX) * deltaX }
  else { stepX = 1; sideX = (mapX + 1 - px) * deltaX }
  if (rayDirY < 0) { stepY = -1; sideY = (py - mapY) * deltaY }
  else { stepY = 1; sideY = (mapY + 1 - py) * deltaY }

  let side: 0 | 1 = 0
  for (let i = 0; i < 64; i++) {
    if (sideX < sideY) { sideX += deltaX; mapX += stepX; side = 0 }
    else { sideY += deltaY; mapY += stepY; side = 1 }
    if (mapX < 0 || mapY < 0 || mapX >= MAP_W || mapY >= MAP_H || MAP[mapY][mapX] === 1) break
  }
  const dist = side === 0
    ? (mapX - px + (1 - stepX) / 2) / (rayDirX || 1e-9)
    : (mapY - py + (1 - stepY) / 2) / (rayDirY || 1e-9)
  return { dist: Math.abs(dist), side }
}

const FOV = 1.05 // ~60°
const PLANE = Math.tan(FOV / 2)
const PLAYER_SPEED = 3.1
const TURN_SPEED = 2.6
const GUN_RANGE = 14
const GUN_DAMAGE = 45 // grunts (80hp) die in two, the boss takes a magazine

/**
 * The stateful engine: owns the player, the current wave's enemies, and all drawing. The
 * card news one up per canvas, calls {@link startWave} / {@link update} / {@link render}
 * each frame, and {@link shoot} on a trigger. Everything shared (score, lives, who cleared
 * a wave) lives outside it, in the widget's state.
 */
export class RaidEngine {
  player = { x: SPAWN.x, y: SPAWN.y, dir: SPAWN.dir, hp: 100 }
  enemies: Enemy[] = []
  wave = 0
  seed = 1
  muzzle = 0 // timestamp of last shot, for flash + recoil
  private bob = 0
  private ctx: CanvasRenderingContext2D
  private w: number
  private h: number

  constructor(ctx: CanvasRenderingContext2D, w: number, h: number) {
    this.ctx = ctx
    this.w = w
    this.h = h
  }

  respawn() {
    this.player.x = SPAWN.x
    this.player.y = SPAWN.y
    this.player.hp = 100
  }

  startWave(wave: number, seed: number) {
    this.wave = wave
    this.seed = seed
    this.enemies = spawnEnemies(wave, seed)
  }

  aliveEnemies(): number {
    return this.enemies.reduce((n, e) => n + (e.alive ? 1 : 0), 0)
  }

  /** The live boss, if this wave has one — for the on-screen boss health bar. */
  boss(): Enemy | null {
    for (const e of this.enemies) if (e.kind === 'boss' && e.alive) return e
    return null
  }

  /** Advance the world one step. Returns what happened for the card to act on. */
  update(dt: number, input: Input): Tick {
    const p = this.player
    const out: Tick = { playerDied: false }
    if (p.hp <= 0) return out

    // Look + move.
    p.dir += input.turn * TURN_SPEED * dt
    const cos = Math.cos(p.dir)
    const sin = Math.sin(p.dir)
    const mv = input.forward * PLAYER_SPEED * dt
    const st = input.strafe * PLAYER_SPEED * dt
    const dx = cos * mv - sin * st
    const dy = sin * mv + cos * st
    if (dx || dy) {
      const moved = slideMove(p.x, p.y, dx, dy)
      p.x = moved.x
      p.y = moved.y
      this.bob += Math.hypot(dx, dy)
    }

    // Enemies chase and bite.
    for (const e of this.enemies) {
      if (!e.alive) continue
      if (e.hurt > 0) e.hurt -= dt
      const ex = p.x - e.x
      const ey = p.y - e.y
      const d = Math.hypot(ex, ey) || 1e-6
      if (d > e.radius + 0.2) {
        const moved = slideMove(e.x, e.y, (ex / d) * e.speed * dt, (ey / d) * e.speed * dt)
        e.x = moved.x
        e.y = moved.y
      } else {
        p.hp -= e.dmg * dt
      }
    }

    if (p.hp <= 0) {
      p.hp = 0
      out.playerDied = true
    }
    return out
  }

  /** Hitscan straight down the crosshair. Kills (and the wave clear they cause) surface here. */
  shoot(now: number): Shot {
    this.muzzle = now
    const p = this.player
    const cos = Math.cos(p.dir)
    const sin = Math.sin(p.dir)
    const wall = castRay(p.x, p.y, cos, sin).dist

    let best = -1
    let bestD = Infinity
    for (let i = 0; i < this.enemies.length; i++) {
      const e = this.enemies[i]
      if (!e.alive) continue
      const rx = e.x - p.x
      const ry = e.y - p.y
      const along = rx * cos + ry * sin // distance projected onto aim
      if (along <= 0 || along > GUN_RANGE || along > wall) continue
      const perp = Math.abs(-rx * sin + ry * cos) // lateral offset from the aim line
      if (perp > e.radius) continue // wider targets (the boss) are easier to hit
      if (along < bestD) { bestD = along; best = i }
    }
    if (best < 0) return { killed: false, points: 0, waveCleared: false }

    const e = this.enemies[best]
    e.hp -= GUN_DAMAGE
    e.hurt = 0.12
    if (e.hp <= 0) {
      e.alive = false
      return { killed: true, points: e.points, waveCleared: this.aliveEnemies() === 0 }
    }
    return { killed: false, points: 0, waveCleared: false }
  }

  /** Draw one frame: walls, floor/ceiling, enemy + teammate sprites, then the gun/HUD. */
  render(ghosts: Ghost[], now: number) {
    const { ctx, w, h } = this
    const p = this.player
    const cos = Math.cos(p.dir)
    const sin = Math.sin(p.dir)
    const planeX = -sin * PLANE
    const planeY = cos * PLANE
    const zbuf = new Float32Array(w)

    // Ceiling + floor.
    const ceil = ctx.createLinearGradient(0, 0, 0, h / 2)
    ceil.addColorStop(0, '#0b1020')
    ceil.addColorStop(1, '#1c2438')
    ctx.fillStyle = ceil
    ctx.fillRect(0, 0, w, h / 2)
    const floor = ctx.createLinearGradient(0, h / 2, 0, h)
    floor.addColorStop(0, '#2a1f1a')
    floor.addColorStop(1, '#0d0906')
    ctx.fillStyle = floor
    ctx.fillRect(0, h / 2, w, h / 2)

    // Walls, column by column.
    for (let x = 0; x < w; x++) {
      const cx = (2 * x) / w - 1
      const rdx = cos + planeX * cx
      const rdy = sin + planeY * cx
      const hit = castRay(p.x, p.y, rdx, rdy)
      const dist = Math.max(0.05, hit.dist)
      zbuf[x] = dist
      const lh = Math.min(h * 4, h / dist)
      const y0 = (h - lh) / 2
      const fog = Math.max(0, 1 - dist / 16)
      const base = hit.side === 1 ? 150 : 200 // darken y-facing walls, DOOM-style
      const r = Math.round(base * fog * 0.55)
      const g = Math.round(base * fog * 0.68)
      const b = Math.round(base * fog)
      ctx.fillStyle = `rgb(${r},${g},${b})`
      ctx.fillRect(x, y0, 1, lh)
    }

    // Sprites: enemies + teammate ghosts, far-to-near so nearer ones overlap.
    interface S { x: number, y: number, glyph: string, scale: number, tint?: string, label?: string, labelColor?: string, hpFrac?: number, barColor?: string }
    const sprites: S[] = []
    for (const e of this.enemies) {
      if (!e.alive) continue
      const tint = e.hurt > 0 ? '#ff5555' : undefined
      if (e.kind === 'boss') {
        sprites.push({ x: e.x, y: e.y, glyph: '🐲', scale: 1.5, tint, label: 'BOSS', labelColor: '#fca5a5', hpFrac: e.hp / e.maxHp, barColor: '#ef4444' })
      } else {
        sprites.push({ x: e.x, y: e.y, glyph: '👹', scale: 0.72, tint })
      }
    }
    for (const gh of ghosts) {
      sprites.push({ x: gh.x, y: gh.y, glyph: now - gh.firing < 120 ? '🤠' : '🧑‍🚀', scale: 0.7, label: gh.name, labelColor: '#a5f3fc', hpFrac: gh.hp / 100, barColor: '#34d399' })
    }
    sprites.sort((a, b) => Math.hypot(b.x - p.x, b.y - p.y) - Math.hypot(a.x - p.x, a.y - p.y))

    const invDet = 1 / (planeX * sin - cos * planeY)
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    for (const s of sprites) {
      const rx = s.x - p.x
      const ry = s.y - p.y
      const tx = invDet * (sin * rx - cos * ry)
      const ty = invDet * (-planeY * rx + planeX * ry) // depth
      if (ty <= 0.2) continue
      const screenX = (w / 2) * (1 + tx / ty)
      const col = Math.floor(screenX)
      if (col < 0 || col >= w || zbuf[col] < ty) continue // behind a wall
      const size = Math.min(h * 2.5, (h / ty) * s.scale)
      const fog = Math.max(0.15, 1 - ty / 16)
      ctx.globalAlpha = fog
      if (s.tint) {
        ctx.globalAlpha = fog * 0.5
        ctx.fillStyle = s.tint
        ctx.fillRect(screenX - size / 3, h / 2 - size / 2, (size * 2) / 3, size)
        ctx.globalAlpha = fog
      }
      ctx.font = `${size}px serif`
      ctx.fillText(s.glyph, screenX, h / 2)
      if (s.hpFrac != null) {
        const bw = Math.min(72, size * 0.7)
        ctx.globalAlpha = fog
        ctx.fillStyle = 'rgba(0,0,0,.6)'
        ctx.fillRect(screenX - bw / 2, h / 2 - size / 2 - 4, bw, 3)
        ctx.fillStyle = s.barColor ?? '#34d399'
        ctx.fillRect(screenX - bw / 2, h / 2 - size / 2 - 4, bw * Math.max(0, Math.min(1, s.hpFrac)), 3)
      }
      if (s.label) {
        ctx.globalAlpha = fog
        ctx.font = 'bold 10px system-ui, sans-serif'
        ctx.fillStyle = s.labelColor ?? '#fff'
        ctx.fillText(s.label, screenX, h / 2 - size / 2 - 10)
      }
      ctx.globalAlpha = 1
    }

    this.drawGun(now)
    this.drawCrosshair()
  }

  private drawGun(now: number) {
    const { ctx, w, h } = this
    const bob = Math.sin(this.bob * 3) * 4
    const kick = now - this.muzzle < 90 ? 8 : 0
    const gx = w / 2
    const gy = h - 6 + bob + kick
    // Muzzle flash.
    if (now - this.muzzle < 70) {
      ctx.globalAlpha = 0.9
      ctx.fillStyle = '#ffe27a'
      ctx.beginPath()
      ctx.arc(gx, gy - 46, 12, 0, Math.PI * 2)
      ctx.fill()
      ctx.globalAlpha = 1
    }
    // A blocky pistol.
    ctx.fillStyle = '#26292e'
    ctx.fillRect(gx - 9, gy - 44, 18, 44)
    ctx.fillStyle = '#3a3f47'
    ctx.fillRect(gx - 16, gy - 20, 32, 20)
    ctx.fillStyle = '#15171a'
    ctx.fillRect(gx - 4, gy - 52, 8, 12)
  }

  private drawCrosshair() {
    const { ctx, w, h } = this
    ctx.strokeStyle = 'rgba(255,255,255,.75)'
    ctx.lineWidth = 2
    ctx.beginPath()
    ctx.moveTo(w / 2 - 8, h / 2)
    ctx.lineTo(w / 2 - 3, h / 2)
    ctx.moveTo(w / 2 + 3, h / 2)
    ctx.lineTo(w / 2 + 8, h / 2)
    ctx.moveTo(w / 2, h / 2 - 8)
    ctx.lineTo(w / 2, h / 2 - 3)
    ctx.moveTo(w / 2, h / 2 + 3)
    ctx.lineTo(w / 2, h / 2 + 8)
    ctx.stroke()
  }
}
