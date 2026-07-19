/**
 * A tiny Galaga-style fixed-screen shooter engine for the Side Squadron widget — the
 * graphics + gameplay half of the game, deliberately framework-agnostic so its maths can
 * be exercised under plain Node and the Vue card only has to feed it input, a canvas and
 * teammate ghosts.
 *
 * Design notes that make the co-op honest within this app's sync model (the same rules the
 * old raycaster raid followed):
 *   - The **play field is fixed** — everyone flies the same arena, so positions mean the
 *     same thing to all of us.
 *   - Invaders spawn **deterministically** from a shared per-run `seed` plus the wave
 *     number ({@see spawnFormation}), so every client fighting wave 3 of run #42 faces the
 *     same formation in the same slots — without anyone streaming enemy positions.
 *   - Invaders are then simulated **locally** (each client's aliens dive at that client's
 *     own ship). That's the deliberate trade: real enemy consensus needs a game server we
 *     don't have, whereas teammates are shown as *real* ghosts from whispered positions. So
 *     the formation and the spawn are shared; the dives are your own copy.
 *
 * Coordinates are canvas pixels: x grows right, y grows down (0,0 top-left). Your ship sits
 * near the bottom and fires upward; invaders hang in a formation up top and peel off to dive.
 */

export type InvaderKind = 'grunt' | 'boss'

export interface Invader {
  /** Live position. */
  x: number
  y: number
  /** Resting slot in the swaying formation grid. */
  homeX: number
  homeY: number
  hp: number
  maxHp: number
  alive: boolean
  hurt: number
  kind: InvaderKind
  /** Half-width, for hit tests and ship-contact range. */
  radius: number
  /** 'formation' hangs in the grid; 'diving' peels off toward the player and loops back. */
  mode: 'formation' | 'diving'
  /** Progress + shape of the current dive. */
  diveT: number
  diveDir: number
  /** Seconds until this invader may fire again. */
  cooldown: number
  points: number
}

/** A player bullet (vy < 0, travels up) or an enemy bullet (vy > 0, travels down). */
export interface Bullet { x: number, y: number, vy: number, enemy: boolean }

/** A teammate as we render them: their whispered horizontal position (0..1) + firing flash. */
export interface Ghost { id: number, name: string, x: number, hp: number, firing: number }

/** Per-frame intent from the card. */
export interface Input {
  /** Keyboard steering, −1 (left) .. 1 (right). */
  moveX: number
  /** Mouse steering target in canvas px, or null when the pointer isn't steering. */
  aimX: number | null
  /** Whether the fire button is held. */
  firing: boolean
}

/** What a single `update` produced, so the card can drive networking / widget actions. */
export interface Tick { playerDied: boolean, kills: number, points: number, waveCleared: boolean }

/** Deterministic RNG (mulberry32): the same seed + wave gives the same formation everywhere. */
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

/** How many invaders a wave fields. */
export function waveCount(wave: number): number {
  return 6 + wave * 2
}

/** Every third wave is a flagship (boss) wave. */
export function isBossWave(wave: number): boolean {
  return wave % 3 === 0
}

// --- tuned play-feel constants (canvas is RES_W×RES_H, see the card) ---
const PLAYER_SPEED = 260 // px/s
const PLAYER_MARGIN = 16
const BULLET_SPEED = 380
const ENEMY_BULLET_SPEED = 165
const FIRE_COOLDOWN = 0.22 // s between the player's shots
const MAX_BULLETS = 4 // classic Galaga: only a few of your shots on screen at once
const GRID_TOP = 34
const GRID_ROW_H = 24
const SWAY_AMP = 14
const SWAY_SPEED = 0.9

/**
 * Build a wave's formation, deterministically. Invaders are laid out in centred rows of up
 * to 8; boss waves crown the top-centre slot with a flagship worth soaking a magazine.
 */
export function spawnFormation(wave: number, seed: number, w: number): Invader[] {
  const rand = rng(seed * 1000 + wave)
  const boss = isBossWave(wave)
  const grunts = boss ? Math.max(4, waveCount(wave) - 4) : waveCount(wave)
  const out: Invader[] = []

  const perRow = Math.min(8, Math.max(4, Math.ceil(grunts / 2)))
  const colGap = Math.min(48, (w - 2 * PLAYER_MARGIN) / perRow)
  const rowWidth = (perRow - 1) * colGap
  const x0 = (w - rowWidth) / 2

  for (let i = 0; i < grunts; i++) {
    const row = Math.floor(i / perRow)
    const col = i % perRow
    const hx = x0 + col * colGap
    const hy = GRID_TOP + row * GRID_ROW_H
    out.push(makeGrunt(hx, hy, rand()))
  }
  if (boss) {
    const hx = w / 2
    const hy = GRID_TOP - 4
    out.push(makeBoss(hx, hy, wave, rand()))
  }
  return out
}

/** A grunt: dies in one clean hit, dives now and then, dropping the odd bolt. */
function makeGrunt(hx: number, hy: number, phase: number): Invader {
  return {
    x: hx, y: hy, homeX: hx, homeY: hy,
    hp: 20, maxHp: 20, alive: true, hurt: 0, kind: 'grunt',
    radius: 11, mode: 'formation', diveT: 0, diveDir: phase < 0.5 ? -1 : 1,
    cooldown: 1 + phase * 3, points: 100,
  }
}

/** The flagship: a big, tanky bullet-sponge that scales with the wave and fires often. */
function makeBoss(hx: number, hy: number, wave: number, phase: number): Invader {
  const hp = 420 + wave * 110
  return {
    x: hx, y: hy, homeX: hx, homeY: hy,
    hp, maxHp: hp, alive: true, hurt: 0, kind: 'boss',
    radius: 20, mode: 'formation', diveT: 0, diveDir: phase < 0.5 ? -1 : 1,
    cooldown: 1.5, points: 600,
  }
}

/**
 * The stateful engine: owns the player ship, the current wave's invaders, all bullets, and
 * all drawing. The card news one up per canvas, calls {@link startWave} / {@link update} /
 * {@link render} each frame. Everything shared (score, lives, who cleared a wave) lives
 * outside it, in the widget's state.
 */
export class SquadronEngine {
  player = { x: 0, y: 0, hp: 100, alive: true }
  invaders: Invader[] = []
  bullets: Bullet[] = []
  wave = 0
  seed = 1
  muzzle = 0 // timestamp of the player's last shot, for the muzzle flash
  private sway = 0 // formation oscillation phase
  private fireTimer = 0
  private stars: { x: number, y: number, z: number }[] = []
  private ctx: CanvasRenderingContext2D
  private w: number
  private h: number

  constructor(ctx: CanvasRenderingContext2D, w: number, h: number) {
    this.ctx = ctx
    this.w = w
    this.h = h
    this.player.x = w / 2
    this.player.y = h - 22
    for (let i = 0; i < 48; i++) {
      this.stars.push({ x: Math.random() * w, y: Math.random() * h, z: 0.3 + Math.random() * 0.7 })
    }
  }

  respawn() {
    this.player.x = this.w / 2
    this.player.y = this.h - 22
    this.player.hp = 100
    this.player.alive = true
    // Clear enemy fire so you don't respawn straight into a bolt.
    this.bullets = this.bullets.filter(b => !b.enemy)
  }

  startWave(wave: number, seed: number) {
    this.wave = wave
    this.seed = seed
    this.invaders = spawnFormation(wave, seed, this.w)
    this.bullets = this.bullets.filter(b => !b.enemy)
  }

  aliveInvaders(): number {
    return this.invaders.reduce((n, e) => n + (e.alive ? 1 : 0), 0)
  }

  /** The live flagship, if this wave has one — for the on-screen boss health bar. */
  boss(): Invader | null {
    for (const e of this.invaders) if (e.kind === 'boss' && e.alive) return e
    return null
  }

  /** Advance the world one step. Returns what happened for the card to act on. */
  update(dt: number, input: Input): Tick {
    const out: Tick = { playerDied: false, kills: 0, points: 0, waveCleared: false }
    const p = this.player
    if (!p.alive) return out

    // --- move the ship (mouse steering wins over the keyboard when present) ---
    if (input.aimX != null) {
      const diff = input.aimX - p.x
      const step = PLAYER_SPEED * dt
      p.x += Math.max(-step, Math.min(step, diff))
    } else {
      p.x += input.moveX * PLAYER_SPEED * dt
    }
    p.x = Math.max(PLAYER_MARGIN, Math.min(this.w - PLAYER_MARGIN, p.x))

    // --- fire ---
    this.fireTimer -= dt
    if (input.firing && this.fireTimer <= 0 && this.bullets.filter(b => !b.enemy).length < MAX_BULLETS) {
      this.bullets.push({ x: p.x, y: p.y - 12, vy: -BULLET_SPEED, enemy: false })
      this.fireTimer = FIRE_COOLDOWN
      this.muzzle = performance.now()
    }

    this.sway += SWAY_SPEED * dt
    const offset = Math.sin(this.sway) * SWAY_AMP

    // --- invaders: hold formation, occasionally dive, sometimes fire ---
    const diving = this.invaders.reduce((n, e) => n + (e.alive && e.mode === 'diving' ? 1 : 0), 0)
    const diveBudget = 1 + Math.floor(this.wave / 2)
    for (const e of this.invaders) {
      if (!e.alive) continue
      if (e.hurt > 0) e.hurt -= dt

      if (e.mode === 'formation') {
        e.x = e.homeX + offset
        e.y = e.homeY
        // Peel off into a dive now and then; keep only a few in the air at once.
        if (diving < diveBudget && Math.random() < (e.kind === 'boss' ? 0.06 : 0.22) * dt) {
          e.mode = 'diving'
          e.diveT = 0
          e.diveDir = p.x >= e.x ? 1 : -1
        }
      } else {
        // A swooping arc: accelerate downward while weaving toward the player's column.
        e.diveT += dt
        const toward = (p.x - e.x) * 0.9 * dt
        const weave = Math.cos(e.diveT * 5) * 90 * dt * e.diveDir
        e.x += toward + weave
        e.y += (110 + this.wave * 8) * dt
        // Off the bottom → loop back into its formation slot.
        if (e.y > this.h + 24) {
          e.mode = 'formation'
          e.x = e.homeX + offset
          e.y = e.homeY
        }
        // Contact with the ship: a hard hit.
        if (Math.abs(e.x - p.x) < e.radius + 8 && Math.abs(e.y - p.y) < e.radius + 8) {
          p.hp -= e.kind === 'boss' ? 60 : 34
          e.mode = 'formation'
          e.x = e.homeX + offset
          e.y = e.homeY
        }
      }

      // Fire downward — divers shoot eagerly, formation invaders only rarely.
      e.cooldown -= dt
      if (e.cooldown <= 0) {
        const chance = e.mode === 'diving' ? 1 : (e.kind === 'boss' ? 0.5 : 0.12)
        if (Math.random() < chance) {
          this.bullets.push({ x: e.x, y: e.y + e.radius, vy: ENEMY_BULLET_SPEED, enemy: true })
        }
        e.cooldown = e.kind === 'boss' ? 0.7 : 1.4 + Math.random() * 1.6
      }
    }

    // --- bullets ---
    const bw = this.w
    const bh = this.h
    const surviving: Bullet[] = []
    for (const b of this.bullets) {
      b.y += b.vy * dt
      if (b.y < -8 || b.y > bh + 8 || b.x < -8 || b.x > bw + 8) continue

      if (b.enemy) {
        // Enemy bolt vs the ship.
        if (Math.abs(b.x - p.x) < 9 && Math.abs(b.y - p.y) < 9) {
          p.hp -= 16
          continue
        }
        surviving.push(b)
      } else {
        // Player shot vs the nearest invader it overlaps.
        let hit: Invader | null = null
        for (const e of this.invaders) {
          if (!e.alive) continue
          if (Math.abs(b.x - e.x) < e.radius && Math.abs(b.y - e.y) < e.radius) { hit = e; break }
        }
        if (hit) {
          hit.hp -= 20
          hit.hurt = 0.12
          if (hit.hp <= 0) {
            hit.alive = false
            out.kills++
            out.points += hit.points
          }
          continue
        }
        surviving.push(b)
      }
    }
    this.bullets = surviving

    if (p.hp <= 0) {
      p.hp = 0
      p.alive = false
      out.playerDied = true
    }
    if (out.kills > 0 && this.aliveInvaders() === 0) out.waveCleared = true
    return out
  }

  /** Draw one frame: starfield, invaders, teammate ships, bullets, then your ship. */
  render(ghosts: Ghost[], now: number) {
    const { ctx, w, h } = this
    const p = this.player

    // Space backdrop + drifting stars.
    ctx.fillStyle = '#05060f'
    ctx.fillRect(0, 0, w, h)
    for (const s of this.stars) {
      s.y += s.z * 0.4
      if (s.y > h) { s.y = 0; s.x = Math.random() * w }
      ctx.globalAlpha = 0.3 + s.z * 0.5
      ctx.fillStyle = '#c7d2fe'
      ctx.fillRect(s.x, s.y, s.z < 0.6 ? 1 : 2, s.z < 0.6 ? 1 : 2)
    }
    ctx.globalAlpha = 1

    // Invaders.
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    for (const e of this.invaders) {
      if (!e.alive) continue
      const size = e.kind === 'boss' ? 34 : 20
      if (e.hurt > 0) {
        ctx.globalAlpha = 0.6
        ctx.fillStyle = '#ff5555'
        ctx.beginPath()
        ctx.arc(e.x, e.y, size * 0.6, 0, Math.PI * 2)
        ctx.fill()
        ctx.globalAlpha = 1
      }
      ctx.font = `${size}px serif`
      ctx.fillText(e.kind === 'boss' ? '🛸' : '👾', e.x, e.y)
      if (e.kind === 'boss') {
        const bw = 40
        ctx.fillStyle = 'rgba(0,0,0,.6)'
        ctx.fillRect(e.x - bw / 2, e.y - size / 2 - 7, bw, 3)
        ctx.fillStyle = '#ef4444'
        ctx.fillRect(e.x - bw / 2, e.y - size / 2 - 7, bw * Math.max(0, e.hp / e.maxHp), 3)
      }
    }

    // Teammate ships along the bottom.
    for (const g of ghosts) {
      const gx = g.x * w
      this.drawShip(gx, p.y, '#38bdf8', g.hp / 100)
      if (now - g.firing < 120) {
        ctx.globalAlpha = 0.9
        ctx.fillStyle = '#7dd3fc'
        ctx.fillRect(gx - 1, p.y - 22, 2, 8)
        ctx.globalAlpha = 1
      }
      ctx.font = 'bold 9px system-ui, sans-serif'
      ctx.fillStyle = '#a5f3fc'
      ctx.fillText(g.name, gx, p.y + 16)
    }

    // Bullets.
    for (const b of this.bullets) {
      ctx.fillStyle = b.enemy ? '#f97316' : '#fde047'
      ctx.fillRect(b.x - 1.5, b.y - 5, 3, 10)
    }

    // Your ship (drawn last so it's never hidden) + a muzzle flash.
    if (p.alive) {
      this.drawShip(p.x, p.y, '#f8fafc', p.hp / 100)
      if (now - this.muzzle < 60) {
        ctx.globalAlpha = 0.9
        ctx.fillStyle = '#fff7ae'
        ctx.beginPath()
        ctx.arc(p.x, p.y - 14, 4, 0, Math.PI * 2)
        ctx.fill()
        ctx.globalAlpha = 1
      }
    }
  }

  /** A small vector fighter, nose up, tinted per pilot. */
  private drawShip(x: number, y: number, color: string, hpFrac: number) {
    const { ctx } = this
    ctx.save()
    ctx.translate(x, y)
    ctx.fillStyle = color
    ctx.beginPath()
    ctx.moveTo(0, -12)
    ctx.lineTo(9, 8)
    ctx.lineTo(0, 3)
    ctx.lineTo(-9, 8)
    ctx.closePath()
    ctx.fill()
    // Cockpit + thruster glow.
    ctx.fillStyle = '#0ea5e9'
    ctx.fillRect(-2, -4, 4, 6)
    ctx.restore()
    // A slim health pip under the ship.
    ctx.fillStyle = 'rgba(0,0,0,.5)'
    ctx.fillRect(x - 10, y + 10, 20, 2)
    ctx.fillStyle = hpFrac > 0.35 ? '#34d399' : '#ef4444'
    ctx.fillRect(x - 10, y + 10, 20 * Math.max(0, Math.min(1, hpFrac)), 2)
  }
}
