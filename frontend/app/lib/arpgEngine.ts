/**
 * The Labyrinth — a Diablo-shaped dungeon crawl, played on a canvas over the room.
 *
 * The graphics and gameplay half of the crawl, deliberately framework-agnostic: no Vue, no
 * network, no Laravel. The panel feeds it a canvas, input, and whatever it knows about the other
 * players; it hands back what happened. Its cousins are {@link SquadronEngine} and
 * {@link RaceEngine}, and it follows the same three rules that make co-op honest in an app with
 * no game server:
 *
 *   1. **The world is a seed.** A floor is generated from `seed + depth` and nothing else
 *      ({@link generateLevel}), so four clients on floor 7 of run #42 are standing in the same
 *      dungeon, down to which corner the stairs are in — without a byte of map crossing the wire.
 *   2. **Monsters have one author.** Unlike the shooter, where everyone simulates their own
 *      aliens, a party has to agree about whether the skeleton is dead: loot and experience hang
 *      on it. So exactly one client — the host — runs the monster simulation and whispers
 *      snapshots ({@link monsterSnapshot}); everyone else applies them and asks the host to
 *      apply their hits ({@link applyHit}). Peer-to-peer, at whisper rates, never touching the
 *      server.
 *   3. **Only outcomes are durable.** Experience, gold, an item, a floor, a death — those go to
 *      the server in batches. The swing that caused them doesn't.
 *
 * Coordinates are *tiles*, fractional: the player at (10.5, 4.25) is in the middle of tile
 * (10, 4). Rendering multiplies by {@link TILE} and offsets by a camera that follows the hero.
 */

/** Pixels per tile at 1× zoom. */
import { drawBeast, drawFallen, drawHero, drawMinion } from './arpgSprites'

export const TILE = 28

/** Every floor is this many tiles square. Big enough to get lost in, small enough to clear. */
export const MAP = 56

/** How far the hero can see. Beyond this the floor is black — the genre's whole mood. */
const SIGHT = 9.5

export type Slot = 'weapon' | 'armour' | 'helm' | 'shield' | 'ring' | 'amulet'
export type Rarity = 'common' | 'magic' | 'rare' | 'unique'
/** The eight lines you can be born into. A class never changes; see {@link HeroJob}. */
export type HeroClass =
  | 'swordsman' | 'crusader' | 'archer' | 'thief' | 'mage' | 'priest' | 'necromancer' | 'druid'

/**
 * Where a hero stands along their line — mage at level 1, wizard from thirty.
 *
 * Kept as a plain string rather than a union of every job, because the job tree is the server's
 * (App\Support\Arpg\Jobs) and the day a third tier lands the client should need no edit at all.
 */
export type HeroJob = string

/** Which attribute a class's damage comes off. The server says; see ArpgGame::CLASSES. */
export type Attribute = 'strength' | 'dexterity' | 'magic'

/** The six things a skill can be. The engine implements these; the server owns the list. */
export type SkillKind = 'melee' | 'projectile' | 'nova' | 'heal' | 'summon' | 'buff'

/**
 * One skill, exactly as `/api/arpg/skills` serves it.
 *
 * Deliberately not a hard-coded table in here: a skill is a durable fact about a character
 * (learning one spends a point and is bounded by a cap), so the server owns what a skill *is* and
 * this file owns only what it does. Adding a skill is a row in App\Support\Arpg\Skills and no
 * change at all to this engine — as long as it's one of the six kinds.
 */
export interface SkillDef {
  id: string
  name: string
  /** The job it belongs to — a skill from outside your line is borrowed, and capped per tier. */
  job: HeroJob
  /** Which tier that job is. Derived server-side from the job tree, never stored on the skill. */
  tier: number
  kind: SkillKind
  level: number
  mana: number
  cooldown: number
  blurb: string
  params: Record<string, number | string>
}

/** A skill the hero actually knows, resolved against the catalogue and its own level. */
export interface KnownSkill { def: SkillDef, level: number }

/** A bolt in flight. Guests fire them too — the *hit* is what needs the host's blessing. */
export interface Projectile {
  x: number
  y: number
  vx: number
  vy: number
  damage: number
  /** How many more enemies it can pass through. */
  pierce: number
  /** Blast radius on impact, 0 for a clean hit. */
  splash: number
  life: number
  /** Monsters already hit, so a piercing shot doesn't hit the same skeleton twice. */
  struck: number[]
}

/** Something summoned that fights for you. Host-simulated, exactly like a monster. */
export interface Minion {
  id: number
  owner: number
  kind: string
  x: number
  y: number
  hp: number
  damage: number
  /** Seconds left before it goes back where it came from. */
  life: number
  cooldown: number
}

/** A temporary number on somebody: War Cry's damage, Shield Wall's armour, Shadow Step's speed. */
export interface ActiveBuff { stat: 'damage' | 'armour' | 'speed', amount: number, life: number }

/** An item, in exactly the shape the server sanitises and stores. */
export interface Item {
  name: string
  slot: Slot
  rarity: Rarity
  ilvl: number
  affixes: Partial<Record<'damage' | 'armour' | 'life' | 'mana' | 'strength' | 'dexterity' | 'magic' | 'vitality', number>>
}

/** The hero as the engine needs them: the character sheet, already totalled. */
export interface Hero {
  id: number
  name: string
  class: HeroClass
  level: number
  /** Which attribute this class swings on — a mage's staff is not a swordsman's arm. */
  primary: Attribute
  /** How far along their line they are. Second jobs wear the same silhouette in better colours. */
  tier: number
  strength: number
  dexterity: number
  magic: number
  vitality: number
  /** Totals from worn items. */
  bonusDamage: number
  bonusArmour: number
  bonusLife: number
  bonusMana: number
  /** What they've learned, resolved against the catalogue. The first is the default swing. */
  skills: KnownSkill[]
}

/** Another player, from their whispered position. */
export interface PartyGhost {
  id: number
  name: string
  x: number
  y: number
  hp: number
  /** Milliseconds since epoch of the last whisper — the panel prunes on this. */
  at: number
  attacking: number
  /** Their class and how far along it, so they're drawn as themselves rather than as a blob. */
  line?: string
  tier?: number
}

export type MonsterKind = 'fallen' | 'skeleton' | 'zombie' | 'bat' | 'lord'

export interface Monster {
  id: number
  kind: MonsterKind
  x: number
  y: number
  hp: number
  maxHp: number
  damage: number
  speed: number
  /** Seconds until it can swing again. */
  cooldown: number
  /** Frames of flinch left, purely cosmetic. */
  hurt: number
  alive: boolean
  xp: number
}

/** Loot lying on the floor, waiting to be walked over. */
export interface Drop { id: number, x: number, y: number, gold: number, item: Item | null }

/** One monster as it travels between clients. Deliberately tiny — this goes out ~8×/second. */
export interface MonsterSnap { i: number, x: number, y: number, h: number }

/** A minion, likewise. `o` is whose it is, so everyone draws it in the right colour. */
export interface MinionSnap { i: number, o: number, k: string, x: number, y: number, h: number }

/** Help sent to a party member — a heal or a buff that landed on somebody else's client. */
export interface Aid { to: number, heal: number, stat?: 'damage' | 'armour' | 'speed', amount?: number, duration?: number }

/** What a frame produced, for the panel to turn into network traffic and server actions. */
export interface Tick {
  /** Experience earned this frame (host only — the killer's client is the one that counts it). */
  xp: number
  gold: number
  kills: number
  /** Items walked over this frame. */
  loot: Item[]
  /** The hero fell this frame. */
  died: boolean
  /** Standing on the stairs down. */
  atStairs: boolean
  /** Hits this client wants the host to apply (guests only). */
  hits: { monster: number, damage: number }[]
  /** Minions this client wants the host to raise (guests only — the host owns every entity). */
  summons: { kind: string, count: number, hp: number, damage: number, duration: number, x: number, y: number }[]
  /** Heals and buffs to send to other players' clients. */
  aid: Aid[]
}

/** Per-frame intent from the panel. */
export interface Input {
  /** Where the pointer is, in canvas pixels, while held — the hero walks there. Null when idle. */
  pointer: { x: number, y: number } | null
  /** Whether the pointer is down (walk / attack). */
  down: boolean
  /**
   * Keyboard steering, each −1..1 — WASD or the arrows.
   *
   * Takes precedence over a click destination whenever it isn't zero, because a key you're
   * holding is a more present intention than a place you clicked a second ago. Which means the
   * two schemes coexist without a mode: steer with the keys, aim with the mouse.
   */
  moveX: number
  moveY: number
  /**
   * The skill on the bar the pointer will use, or null for the plain swing.
   *
   * One selected skill rather than a key per skill: the genre's own answer (Diablo's left and
   * right hand), and the only one that works on a phone.
   */
  skill: string | null
}

/** Deterministic RNG (mulberry32) — the same seed gives the same dungeon on every client. */
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

/** A floor: which tiles you can stand on, where you come in, and where you go down. */
export interface Level {
  /** MAP×MAP, row-major. True is floor, false is rock. */
  walkable: boolean[]
  rooms: { x: number, y: number, w: number, h: number }[]
  entrance: { x: number, y: number }
  stairs: { x: number, y: number }
}

const at = (x: number, y: number) => y * MAP + x

/**
 * Carve a floor out of the rock.
 *
 * Rooms scattered without overlapping, then corridors joining each room to the one before it in
 * an L — the oldest dungeon generator there is, and the right one here because it's cheap,
 * always connected (every room reaches room 0 through the chain), and reads as architecture
 * rather than as noise. The entrance is the first room's middle and the stairs are the last
 * room's, which puts them at opposite ends of the chain.
 */
export function generateLevel(seed: number, depth: number): Level {
  // The floor's own seed: mixing depth in means floor 2 of a run is nothing like floor 1, while
  // still being the *same* floor 2 for everyone in the party.
  const rand = rng((seed ^ (depth * 0x9E3779B1)) >>> 0)
  const walkable = new Array<boolean>(MAP * MAP).fill(false)
  const rooms: Level['rooms'] = []

  const target = 9 + Math.min(6, Math.floor(depth / 2))

  for (let tries = 0; tries < 260 && rooms.length < target; tries++) {
    const w = 5 + Math.floor(rand() * 7)
    const h = 5 + Math.floor(rand() * 7)
    const x = 1 + Math.floor(rand() * (MAP - w - 2))
    const y = 1 + Math.floor(rand() * (MAP - h - 2))

    // A one-tile gap between rooms, so two of them never share a wall and read as one blob.
    const clashes = rooms.some(r => x < r.x + r.w + 1 && x + w + 1 > r.x && y < r.y + r.h + 1 && y + h + 1 > r.y)
    if (clashes) continue

    for (let iy = y; iy < y + h; iy++) {
      for (let ix = x; ix < x + w; ix++) walkable[at(ix, iy)] = true
    }

    if (rooms.length > 0) {
      const prev = rooms[rooms.length - 1]!
      const ax = Math.floor(prev.x + prev.w / 2)
      const ay = Math.floor(prev.y + prev.h / 2)
      const bx = Math.floor(x + w / 2)
      const by = Math.floor(y + h / 2)

      // The corner goes one way or the other at random, so the L's don't all bend alike.
      const horizontalFirst = rand() < 0.5
      const cornerX = horizontalFirst ? bx : ax
      const cornerY = horizontalFirst ? ay : by

      for (let ix = Math.min(ax, cornerX); ix <= Math.max(ax, cornerX); ix++) walkable[at(ix, cornerY)] = true
      for (let iy = Math.min(ay, cornerY); iy <= Math.max(ay, cornerY); iy++) walkable[at(cornerX, iy)] = true
      for (let ix = Math.min(cornerX, bx); ix <= Math.max(cornerX, bx); ix++) walkable[at(ix, by)] = true
      for (let iy = Math.min(cornerY, by); iy <= Math.max(cornerY, by); iy++) walkable[at(bx, iy)] = true
    }

    rooms.push({ x, y, w, h })
  }

  const first = rooms[0] ?? { x: 2, y: 2, w: 3, h: 3 }
  const last = rooms[rooms.length - 1] ?? first

  return {
    walkable,
    rooms,
    entrance: { x: first.x + first.w / 2, y: first.y + first.h / 2 },
    stairs: { x: Math.floor(last.x + last.w / 2), y: Math.floor(last.y + last.h / 2) },
  }
}

/** What lives on a given floor, and how hard it hits. */
const BESTIARY: Record<MonsterKind, { hp: number, damage: number, speed: number, xp: number, from: number }> = {
  fallen: { hp: 14, damage: 3, speed: 3.1, xp: 8, from: 1 },
  bat: { hp: 10, damage: 4, speed: 4.2, xp: 11, from: 2 },
  skeleton: { hp: 26, damage: 7, speed: 2.6, xp: 20, from: 3 },
  zombie: { hp: 44, damage: 11, speed: 1.7, xp: 32, from: 5 },
  lord: { hp: 160, damage: 20, speed: 2.3, xp: 220, from: 4 },
}

/**
 * Populate a floor — the same monsters, in the same corners, for everyone on it.
 *
 * Generated from the seed like the map is, so a guest who joins mid-floor and a host who has
 * been there ten minutes agree about what the room *held*; the host's snapshots then say what's
 * still alive. Never in the entrance room: walking in to a face full of skeletons isn't
 * difficulty, it's a coin toss.
 */
export function populate(level: Level, seed: number, depth: number): Monster[] {
  const rand = rng((seed ^ (depth * 0x85EBCA6B)) >>> 0)
  const kinds = (Object.keys(BESTIARY) as MonsterKind[]).filter(k => k !== 'lord' && BESTIARY[k].from <= depth)
  const monsters: Monster[] = []
  let id = 1

  level.rooms.forEach((room, index) => {
    if (index === 0) return

    const count = 2 + Math.floor(rand() * (2 + Math.min(5, depth)))

    for (let i = 0; i < count; i++) {
      const kind = kinds[Math.floor(rand() * kinds.length)] ?? 'fallen'
      const base = BESTIARY[kind]
      // Everything gets tougher as you go down; the numbers are the same everywhere because
      // the depth is, so nobody's skeleton has more health than anybody else's.
      const scale = 1 + (depth - 1) * 0.22

      monsters.push({
        id: id++,
        kind,
        x: room.x + 0.5 + rand() * (room.w - 1),
        y: room.y + 0.5 + rand() * (room.h - 1),
        hp: Math.round(base.hp * scale),
        maxHp: Math.round(base.hp * scale),
        damage: Math.round(base.damage * scale),
        speed: base.speed,
        cooldown: 0,
        hurt: 0,
        alive: true,
        xp: Math.round(base.xp * scale),
      })
    }
  })

  // Every fourth floor keeps something bigger by the stairs. You'll have heard it before you see
  // it, because it's the one thing down here that outruns you.
  if (depth % 4 === 0) {
    const base = BESTIARY.lord
    const scale = 1 + (depth - 1) * 0.25
    monsters.push({
      id: id++,
      kind: 'lord',
      x: level.stairs.x + 1.5,
      y: level.stairs.y + 0.5,
      hp: Math.round(base.hp * scale),
      maxHp: Math.round(base.hp * scale),
      damage: Math.round(base.damage * scale),
      speed: base.speed,
      cooldown: 0,
      hurt: 0,
      alive: true,
      xp: Math.round(base.xp * scale),
    })
  }

  return monsters
}

const BASES: Record<Slot, string[]> = {
  weapon: ['Short Sword', 'Axe', 'Mace', 'Long Sword', 'War Hammer'],
  armour: ['Rags', 'Leather Armour', 'Chain Mail', 'Plate Mail'],
  helm: ['Cap', 'Skull Cap', 'Great Helm'],
  shield: ['Buckler', 'Small Shield', 'Kite Shield'],
  ring: ['Ring'],
  amulet: ['Amulet'],
}

const PREFIX = ['Bronze', 'Sharp', 'Jagged', 'Grim', 'Blessed', 'Vicious', 'Glowing', 'Ancient']
const SUFFIX = ['of the Bear', 'of Vigour', 'of the Fox', 'of the Mind', 'of Warding', 'of Thorns']

/**
 * Roll an item off a corpse.
 *
 * Rarity buys *affixes*, not a different table: a rare short sword is the same short sword with
 * three magical properties on it. That's the genre's actual loop — you're not waiting for the
 * legendary base, you're waiting for the roll — and it means one small table carries the whole
 * game. The server re-checks the shape of whatever comes out of here; see ArpgGame::cleanItem.
 */
export function rollItem(rand: () => number, depth: number): Item {
  const slots = Object.keys(BASES) as Slot[]
  const slot = slots[Math.floor(rand() * slots.length)]!
  const bases = BASES[slot]
  const base = bases[Math.min(bases.length - 1, Math.floor(rand() * (1 + depth / 3)))] ?? bases[0]!

  const roll = rand()
  const rarity: Rarity = roll > 0.985 ? 'unique' : roll > 0.88 ? 'rare' : roll > 0.55 ? 'magic' : 'common'
  const affixCount = rarity === 'unique' ? 4 : rarity === 'rare' ? 3 : rarity === 'magic' ? 1 : 0

  const pool: (keyof Item['affixes'])[] = slot === 'weapon'
    ? ['damage', 'damage', 'strength', 'dexterity', 'life']
    : ['armour', 'life', 'mana', 'strength', 'dexterity', 'magic', 'vitality']

  const affixes: Item['affixes'] = {}

  // A common weapon still hits harder than a fist, and common armour still stops something —
  // the base item is worth having; the affixes are why you're still down here at 2am.
  if (slot === 'weapon') affixes.damage = 2 + Math.floor(depth * 1.4 + rand() * depth)
  else if (slot !== 'ring' && slot !== 'amulet') affixes.armour = 1 + Math.floor(depth * 0.9 + rand() * depth)

  for (let i = 0; i < affixCount; i++) {
    const key = pool[Math.floor(rand() * pool.length)]!
    const magnitude = 1 + Math.floor(rand() * (2 + depth * 0.8))
    affixes[key] = (affixes[key] ?? 0) + magnitude
  }

  const name = rarity === 'common'
    ? base
    : rarity === 'magic'
      ? `${PREFIX[Math.floor(rand() * PREFIX.length)]} ${base}`
      : `${PREFIX[Math.floor(rand() * PREFIX.length)]} ${base} ${SUFFIX[Math.floor(rand() * SUFFIX.length)]}`

  return { name, slot, rarity, ilvl: depth, affixes }
}

/** A floating number over the fight — the only feedback the genre ever really needed. */
interface Splat { x: number, y: number, text: string, life: number, colour: string }

export class ArpgEngine {
  private ctx: CanvasRenderingContext2D
  private w: number
  private h: number

  readonly level: Level
  readonly depth: number
  private hero: Hero
  private isHost: boolean

  /** The hero, in tiles. */
  x: number
  y: number
  hp: number
  maxHp: number
  mana: number
  maxMana: number
  alive = true

  private monsters: Monster[]
  private drops: Drop[] = []
  private splats: Splat[] = []
  private dropId = 1

  private projectiles: Projectile[] = []
  private minions: Minion[] = []
  private minionId = 1
  private buffs: ActiveBuff[] = []
  /** Seconds left on each skill, keyed by id. */
  private cooldowns = new Map<string, number>()

  /**
   * Which tiles this hero has laid eyes on, for the minimap.
   *
   * A byte per tile rather than a Set of coordinates: it's 3KB for the whole floor, it's read
   * once per tile when the map is drawn, and it never allocates while you walk. Explored rather
   * than given, because a dungeon that hands you the whole map on arrival isn't one you explore —
   * the minimap should be a record of where you've been, not a solution to the floor.
   */
  private seen = new Uint8Array(MAP * MAP)

  /** Whether the corner map is up. Toggled from the panel; the engine draws it. */
  minimap = true

  /** Seconds until this hero can swing again, and how long the swing has left to draw. */
  private swing = 0
  private swingAnim = 0
  private facing = 0
  private lootRand: () => number

  /** Where the hero is walking, in tiles. Null when standing still. */
  private target: { x: number, y: number } | null = null

  /** Ghosts, as of the last frame — monster AI needs to know who else is in the room. */
  private ghosts: PartyGhost[] = []

  constructor(
    ctx: CanvasRenderingContext2D,
    w: number,
    h: number,
    opts: { seed: number, depth: number, hero: Hero, isHost: boolean },
  ) {
    this.ctx = ctx
    this.w = w
    this.h = h
    this.depth = opts.depth
    this.hero = opts.hero
    this.isHost = opts.isHost

    this.level = generateLevel(opts.seed, opts.depth)
    this.monsters = populate(this.level, opts.seed, opts.depth)
    // Loot is rolled on the killer's client, so its randomness is *not* shared — two players
    // killing two skeletons should not get the same sword.
    this.lootRand = rng((opts.seed ^ (opts.hero.id * 2654435761) ^ (opts.depth * 40503)) >>> 0)

    this.x = this.level.entrance.x
    this.y = this.level.entrance.y

    this.maxHp = this.lifeFor(opts.hero)
    this.hp = this.maxHp
    this.maxMana = this.manaFor(opts.hero)
    this.mana = this.maxMana
  }

  setSize(w: number, h: number) {
    this.w = w
    this.h = h
  }

  /** Promote this client to running the monsters — when the old host walks out. */
  setHost(isHost: boolean) {
    this.isHost = isHost
  }

  /** The sheet changed (a level, a new sword): re-total without disturbing the fight. */
  setHero(hero: Hero) {
    const fraction = this.hp / this.maxHp
    this.hero = hero
    this.maxHp = this.lifeFor(hero)
    this.maxMana = this.manaFor(hero)
    this.hp = Math.max(1, Math.round(this.maxHp * fraction))
  }

  private lifeFor(hero: Hero) {
    return 20 + hero.vitality * 2 + hero.level * 6 + hero.bonusLife
  }

  private manaFor(hero: Hero) {
    return 10 + hero.magic * 2 + hero.level * 3 + hero.bonusMana
  }

  /**
   * What one plain swing does, before the target's armour.
   *
   * Off the class's own attribute — the server says which in `primary`, because that's a fact
   * about the class rather than about the frame — plus what you're wearing and anything buffing
   * you at the moment.
   */
  private damageRoll() {
    const h = this.hero
    const stat = h.primary === 'magic' ? h.magic : h.primary === 'dexterity' ? h.dexterity : h.strength

    return 1 + Math.floor(stat / 3) + h.bonusDamage + this.buffed('damage')
      + Math.floor(Math.random() * (2 + h.level))
  }

  private armour() {
    return this.hero.bonusArmour + Math.floor(this.hero.dexterity / 5) + this.buffed('armour')
  }

  /** The total of every active buff on one stat. */
  private buffed(stat: ActiveBuff['stat']) {
    return this.buffs.reduce((total, buff) => (buff.stat === stat ? total + buff.amount : total), 0)
  }

  /** A skill's number at the level this hero has it: `base + per_level × (level − 1)`. */
  private scaled(skill: KnownSkill, key: string) {
    const base = Number(skill.def.params[key] ?? 0)
    const per = Number(skill.def.params[`${key}_per_level`] ?? 0)

    return base + per * (skill.level - 1)
  }

  /** Which line's art this hero wears — their class, which never changes. */
  private get line() {
    return this.hero.class
  }

  /** How far along it, which is only ever a palette shift. */
  private get tier() {
    return this.hero.tier
  }

  /** A learned skill by id, or null — a bar button for something unlearned does nothing. */
  private skill(id: string | null): KnownSkill | null {
    if (!id) return null

    return this.hero.skills.find(s => s.def.id === id) ?? null
  }

  /** Seconds until a skill is ready. 0 when it is. */
  cooldownLeft(id: string) {
    return Math.max(0, this.cooldowns.get(id) ?? 0)
  }

  /** Whether the hero could cast this right now — the whole of what greys a bar button out. */
  ready(id: string) {
    const skill = this.skill(id)
    if (!skill) return false

    return this.cooldownLeft(id) <= 0 && this.mana >= skill.def.mana
  }

  walkable(x: number, y: number) {
    const ix = Math.floor(x)
    const iy = Math.floor(y)
    if (ix < 0 || iy < 0 || ix >= MAP || iy >= MAP) return false

    return this.level.walkable[at(ix, iy)] === true
  }

  /** Tile under a canvas point — how a click becomes a destination. */
  toTile(px: number, py: number) {
    return {
      x: (px - this.w / 2) / TILE + this.x,
      y: (py - this.h / 2) / TILE + this.y,
    }
  }

  aliveMonsters() {
    return this.monsters.filter(m => m.alive).length
  }

  /** The monsters, small, for the host to whisper out. Only the living need describing. */
  monsterSnapshot(): MonsterSnap[] {
    return this.monsters
      .filter(m => m.alive)
      .map(m => ({ i: m.id, x: Math.round(m.x * 100) / 100, y: Math.round(m.y * 100) / 100, h: m.hp }))
  }

  /** The host's minions, for the same whisper the monsters ride on. */
  minionSnapshot(): MinionSnap[] {
    return this.minions.map(m => ({
      i: m.id,
      o: m.owner,
      k: m.kind,
      x: Math.round(m.x * 100) / 100,
      y: Math.round(m.y * 100) / 100,
      h: Math.round(m.hp),
    }))
  }

  /**
   * Take the host's word for the minions too.
   *
   * Replaced wholesale rather than reconciled: a minion has no local state worth preserving —
   * it's somebody else's pet, drawn where the host says it is — and a list this short is cheaper
   * to rebuild than to diff.
   */
  applyMinionSnapshot(snap: MinionSnap[]) {
    if (this.isHost) return

    this.minions = snap.map(m => ({
      id: m.i,
      owner: m.o,
      kind: m.k,
      x: m.x,
      y: m.y,
      hp: m.h,
      damage: 0,
      life: 99,
      cooldown: 0,
    }))
  }

  /**
   * Take the host's word for where the monsters are.
   *
   * Anything the host didn't mention is dead — that's how a guest learns about a kill, and why
   * the snapshot doesn't need a separate "these died" list. Positions are set rather than
   * interpolated: at ~8 snapshots a second over a local Reverb the stutter is small, and a wrong
   * position that looks smooth is worse than a right one that jumps.
   */
  applySnapshot(snap: MonsterSnap[]) {
    if (this.isHost) return

    const seen = new Map(snap.map(s => [s.i, s]))

    for (const monster of this.monsters) {
      const update = seen.get(monster.id)

      if (!update) {
        if (monster.alive) {
          monster.alive = false
          this.splat(monster.x, monster.y, '', '#f87171')
        }
        continue
      }

      monster.x = update.x
      monster.y = update.y
      monster.hp = update.h
      monster.alive = true
    }
  }

  /**
   * A hit somebody else landed, applied here because this client is the host.
   *
   * The guest is trusted about the swing — it saw the fight frame by frame and we didn't — but
   * not about the consequences: whether the monster dies, and therefore who may collect it, is
   * settled here and travels back out in the next snapshot.
   */
  applyHit(monsterId: number, damage: number) {
    if (!this.isHost) return

    const monster = this.monsters.find(m => m.id === monsterId)
    if (!monster?.alive) return

    monster.hp -= Math.max(0, Math.min(9999, damage))
    monster.hurt = 0.15
    if (monster.hp <= 0) monster.alive = false
  }

  /** Where this client's hero is, for the others' ghosts. */
  playerSnapshot() {
    return { x: Math.round(this.x * 100) / 100, y: Math.round(this.y * 100) / 100, hp: this.hp, maxHp: this.maxHp }
  }

  setGhosts(ghosts: PartyGhost[]) {
    this.ghosts = ghosts
  }

  /** Get back up, on the spot the party is standing — a new floor, or a companion's help. */
  revive() {
    this.alive = true
    this.hp = Math.max(1, Math.round(this.maxHp * 0.5))
  }

  /** Walk into the next floor down: same hero, new world. */
  descendTo(seed: number, depth: number): ArpgEngine {
    const next = new ArpgEngine(this.ctx, this.w, this.h, {
      seed,
      depth,
      hero: this.hero,
      isHost: this.isHost,
    })
    // Health follows you down. The stairs are not a rest stop; that's what potions were for.
    next.hp = this.alive ? this.hp : Math.round(this.maxHp * 0.5)
    next.mana = this.mana
    // Cooldowns come with you as well — a staircase is not a way to reset Fireball.
    next.cooldowns = this.cooldowns
    // The map does not: a new floor is a new floor, and it starts unexplored.
    next.minimap = this.minimap

    return next
  }

  /**
   * One frame of dungeon.
   *
   * Order matters: the hero moves and swings first, then the monsters answer, then the floor is
   * swept for loot — so a monster killed this frame drops something you can walk over on the
   * next one rather than immediately standing in.
   */
  update(dt: number, input: Input): Tick {
    const tick: Tick = {
      xp: 0, gold: 0, kills: 0, loot: [], died: false, atStairs: false,
      hits: [], summons: [], aid: [],
    }

    if (dt > 0.1) dt = 0.1
    this.swing = Math.max(0, this.swing - dt)
    this.swingAnim = Math.max(0, this.swingAnim - dt)
    this.mana = Math.min(this.maxMana, this.mana + dt * (1 + this.hero.magic / 20))

    for (const [id, left] of this.cooldowns) {
      if (left > 0) this.cooldowns.set(id, Math.max(0, left - dt))
    }

    for (const buff of this.buffs) buff.life -= dt
    this.buffs = this.buffs.filter(b => b.life > 0)

    this.explore()

    for (const splat of this.splats) splat.life -= dt
    this.splats = this.splats.filter(s => s.life > 0)

    if (!this.alive) {
      this.updateProjectiles(dt, tick)
      this.updateMonsters(dt, tick)

      return tick
    }

    // --- keys first: holding one cancels wherever you last clicked ---
    const steering = input.moveX !== 0 || input.moveY !== 0
    if (steering) this.target = null

    // --- what the pointer means ---
    if (input.pointer && input.down) {
      const aim = this.toTile(input.pointer.x, input.pointer.y)
      const quarry = this.monsterNear(aim.x, aim.y, 0.9)
      const chosen = this.skill(input.skill)

      if (chosen) {
        // A selected skill fires at where you pointed, whether or not anything is standing
        // there — a fireball at an empty corridor is a decision, not a misclick.
        this.cast(chosen, aim, quarry, tick)
      } else if (quarry && this.reaches(quarry)) {
        this.target = null
        this.attack(quarry, tick, null)
      } else if (!steering) {
        // Keys win: clicking to aim shouldn't drag you toward the cursor mid-stride.
        this.target = aim
      }
    } else if (!input.down && !steering) {
      this.target = null
    }

    if (steering) this.steer(dt, input.moveX, input.moveY)
    this.walk(dt)

    // --- the floor answers ---
    this.updateProjectiles(dt, tick)
    this.updateMinions(dt)
    this.updateMonsters(dt, tick)
    this.sweep(tick)

    if (this.hp <= 0 && this.alive) {
      this.alive = false
      this.target = null
      tick.died = true
    }

    tick.atStairs = Math.abs(this.x - (this.level.stairs.x + 0.5)) < 1.2
      && Math.abs(this.y - (this.level.stairs.y + 0.5)) < 1.2

    return tick
  }

  /**
   * Walk on the keys.
   *
   * The direction is normalised so that holding two keys isn't 41% faster than one — the oldest
   * bug in top-down movement. Otherwise it's the same axis-separated step as {@link walk}, so
   * sliding along a wall feels identical whichever way you're driving.
   */
  private steer(dt: number, moveX: number, moveY: number) {
    const length = Math.hypot(moveX, moveY) || 1
    const speed = this.speed()
    const dx = (moveX / length) * speed * dt
    const dy = (moveY / length) * speed * dt

    this.facing = Math.atan2(moveY, moveX)

    if (this.walkable(this.x + dx, this.y)) this.x += dx
    if (this.walkable(this.x, this.y + dy)) this.y += dy
  }

  /** How fast this hero moves, buffs included. */
  private speed() {
    return 3.6 + this.hero.dexterity / 60 + this.buffed('speed')
  }

  /** Step toward the walk target, sliding along walls rather than sticking to them. */
  private walk(dt: number) {
    if (!this.target) return

    const dx = this.target.x - this.x
    const dy = this.target.y - this.y
    const distance = Math.hypot(dx, dy)

    if (distance < 0.12) {
      this.target = null

      return
    }

    const step = Math.min(distance, this.speed() * dt)
    const nx = this.x + (dx / distance) * step
    const ny = this.y + (dy / distance) * step

    this.facing = Math.atan2(dy, dx)

    // Axis-separated so a diagonal into a corner still slides along one wall — without this,
    // click-to-move in a corridor feels like walking into glue.
    if (this.walkable(nx, this.y)) this.x = nx
    if (this.walkable(this.x, ny)) this.y = ny
  }

  private reaches(monster: Monster) {
    return Math.hypot(monster.x - this.x, monster.y - this.y) < 1.5
  }

  private monsterNear(x: number, y: number, radius: number): Monster | null {
    let best: Monster | null = null
    let bestDistance = radius

    for (const monster of this.monsters) {
      if (!monster.alive) continue
      const distance = Math.hypot(monster.x - x, monster.y - y)
      if (distance < bestDistance) {
        best = monster
        bestDistance = distance
      }
    }

    return best
  }

  /**
   * Swing at something.
   *
   * On the host the damage lands here and the kill is settled here; on a guest it goes out in
   * the tick for the host to apply, and the guest waits to be told. Either way the *reward* —
   * experience, gold, an item — is worked out on the swinging client, because it's the one that
   * knows whose swing it was. A monster killed by a guest is therefore credited on the guest and
   * removed by the host, which is the one place the two halves have to trust each other.
   */
  private attack(monster: Monster, tick: Tick, skill: KnownSkill | null) {
    // A skill has already paid its own cost and set its own cooldown; a plain swing pays here.
    if (!skill) {
      if (this.swing > 0) return
      this.swing = 0.72 - Math.min(0.35, this.hero.dexterity / 300)
      this.swingAnim = 0.16
      this.facing = Math.atan2(monster.y - this.y, monster.x - this.x)
    }

    const damage = Math.round(this.damageRoll() * (skill ? this.scaled(skill, 'damage') : 1))

    // Hits are shown immediately and corrected by the next snapshot: a swing that waits 120ms
    // to appear feels broken, and being briefly optimistic about health costs nothing.
    this.hurt(monster, damage, tick)

    // A cleaving skill takes the neighbours too, and they're worth the same as anything else.
    const radius = skill ? Number(skill.def.params.radius ?? 0) : 0
    if (radius > 0) {
      for (const other of this.monsters) {
        if (!other.alive || other.id === monster.id) continue
        if (Math.hypot(other.x - monster.x, other.y - monster.y) > radius) continue
        this.hurt(other, damage, tick)
      }
    }
  }

  /**
   * Use a skill: pay for it, start its cooldown, and hand it to whichever of the six verbs it is.
   *
   * The dispatch is the whole reason the catalogue can live on the server. Nothing here knows
   * what Fireball is — it knows that a `projectile` with a `splash` throws something that bursts,
   * and Fireball is a row that says so.
   */
  private cast(skill: KnownSkill, aim: { x: number, y: number }, quarry: Monster | null, tick: Tick) {
    const { def } = skill

    if (this.cooldownLeft(def.id) > 0 || this.mana < def.mana || this.swing > 0) return

    // Melee still has to reach: a skill doesn't extend your arm unless it's a bolt.
    if (def.kind === 'melee' && (!quarry || !this.reaches(quarry))) return

    this.mana -= def.mana
    this.cooldowns.set(def.id, def.cooldown)
    this.swing = Math.max(0.2, def.cooldown === 0 ? 0.4 : Math.min(0.6, def.cooldown))
    this.swingAnim = 0.16
    this.facing = Math.atan2(aim.y - this.y, aim.x - this.x)
    this.target = null

    const power = this.damageRoll()

    switch (def.kind) {
      case 'melee':
        this.attack(quarry!, tick, skill)
        break

      case 'projectile': {
        const count = Math.max(1, Number(def.params.count ?? 1))
        const spread = Number(def.params.spread ?? 0)
        const speed = Number(def.params.speed ?? 12)
        const damage = Math.round(power * this.scaled(skill, 'damage'))
        const base = Math.atan2(aim.y - this.y, aim.x - this.x)

        for (let i = 0; i < count; i++) {
          // A spread is centred on where you aimed, so a single arrow goes exactly there.
          const angle = base + (count === 1 ? 0 : (i - (count - 1) / 2) * spread)
          this.projectiles.push({
            x: this.x,
            y: this.y,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed,
            damage,
            pierce: Number(def.params.pierce ?? 0),
            splash: Number(def.params.splash ?? 0),
            life: 1.6,
            struck: [],
          })
        }
        break
      }

      case 'nova': {
        const radius = this.scaled(skill, 'radius') || Number(def.params.radius ?? 3)
        const damage = Math.round(power * this.scaled(skill, 'damage'))

        for (const monster of this.monsters) {
          if (!monster.alive) continue
          if (Math.hypot(monster.x - this.x, monster.y - this.y) > radius) continue
          this.hurt(monster, damage, tick)
        }
        this.splats.push({ x: this.x, y: this.y, text: def.name, life: 0.7, colour: '#93c5fd' })
        break
      }

      case 'heal': {
        const amount = Math.round(this.scaled(skill, 'amount'))
        const radius = Number(def.params.radius ?? 0)

        this.hp = Math.min(this.maxHp, this.hp + amount)
        this.splat(this.x, this.y - 0.5, `+${amount}`, '#4ade80')

        // A party heal lands on *their* client: we can't write to somebody else's health, so we
        // ask them to. Same shape as everything else in this engine that crosses a client.
        if (radius > 0) {
          for (const ghost of this.ghosts) {
            if (Math.hypot(ghost.x - this.x, ghost.y - this.y) <= radius) {
              tick.aid.push({ to: ghost.id, heal: amount })
            }
          }
        }
        break
      }

      case 'buff': {
        const stat = String(def.params.stat ?? 'damage') as ActiveBuff['stat']
        const amount = this.scaled(skill, 'amount')
        const duration = Number(def.params.duration ?? 10)
        const radius = Number(def.params.radius ?? 0)

        this.applyBuff(stat, amount, duration)
        this.splats.push({ x: this.x, y: this.y, text: def.name, life: 0.8, colour: '#fbbf24' })

        if (radius > 0) {
          for (const ghost of this.ghosts) {
            if (Math.hypot(ghost.x - this.x, ghost.y - this.y) <= radius) {
              tick.aid.push({ to: ghost.id, heal: 0, stat, amount, duration })
            }
          }
        }
        break
      }

      case 'summon': {
        const summon = {
          kind: String(def.params.minion ?? 'skeleton'),
          count: Math.max(1, Number(def.params.count ?? 1)),
          hp: Math.round(this.scaled(skill, 'hp')),
          damage: Math.round(this.scaled(skill, 'damage')),
          duration: Number(def.params.duration ?? 30),
          x: this.x,
          y: this.y,
        }

        // Minions are entities, and entities have one author. The host raises its own; a guest
        // asks — the same division that keeps the party agreeing about the monsters.
        if (this.isHost) this.raise(this.hero.id, summon)
        else tick.summons.push(summon)
        break
      }
    }
  }

  /** Put damage on a monster, settling the kill if it lands — the one path for every source. */
  private hurt(monster: Monster, damage: number, tick: Tick, credit = true) {
    const killing = monster.hp - damage <= 0

    if (this.isHost) {
      this.applyHit(monster.id, damage)
    } else {
      monster.hurt = 0.15
      monster.hp -= damage
      tick.hits.push({ monster: monster.id, damage })
    }

    this.splat(monster.x, monster.y, String(damage), '#fbbf24')

    if (killing) {
      monster.alive = false
      if (credit) {
        tick.kills += 1
        tick.xp += monster.xp
        this.spoils(monster, tick)
      }
    }
  }

  /** Add a buff, replacing a weaker one on the same stat rather than stacking duplicates. */
  applyBuff(stat: ActiveBuff['stat'], amount: number, duration: number) {
    const existing = this.buffs.find(b => b.stat === stat)

    if (existing && existing.amount >= amount) {
      existing.life = Math.max(existing.life, duration)

      return
    }

    if (existing) this.buffs = this.buffs.filter(b => b !== existing)
    this.buffs.push({ stat, amount, life: duration })
  }

  /** Somebody else's heal, arriving from their client. */
  receiveHeal(amount: number) {
    if (!this.alive) return
    this.hp = Math.min(this.maxHp, this.hp + amount)
    this.splat(this.x, this.y - 0.5, `+${amount}`, '#4ade80')
  }

  /** Raise minions. Only ever called on the host — see the summon case above. */
  raise(owner: number, summon: { kind: string, count: number, hp: number, damage: number, duration: number, x: number, y: number }) {
    if (!this.isHost) return

    for (let i = 0; i < Math.min(8, summon.count); i++) {
      this.minions.push({
        id: this.minionId++,
        owner,
        kind: summon.kind,
        x: summon.x + (Math.random() - 0.5) * 1.5,
        y: summon.y + (Math.random() - 0.5) * 1.5,
        hp: summon.hp,
        damage: summon.damage,
        life: summon.duration,
        cooldown: 0,
      })
    }
  }

  /** What a corpse leaves behind. */
  private spoils(monster: Monster, tick: Tick) {
    const gold = Math.floor(monster.xp * (0.5 + this.lootRand()))
    const drops = this.lootRand()
    // A lord always leaves something worth the fight; anything else is a one-in-six.
    const item = monster.kind === 'lord' || drops > 0.84 ? rollItem(this.lootRand, this.depth) : null

    this.drops.push({ id: this.dropId++, x: monster.x, y: monster.y, gold, item })
  }

  /** Walk over loot to take it — there is no pick-up button and the floor is dark enough. */
  private sweep(tick: Tick) {
    const kept: Drop[] = []

    for (const drop of this.drops) {
      if (Math.hypot(drop.x - this.x, drop.y - this.y) > 0.75) {
        kept.push(drop)
        continue
      }

      tick.gold += drop.gold
      if (drop.item) tick.loot.push(drop.item)
      this.splat(drop.x, drop.y, drop.item ? drop.item.name : `${drop.gold}g`, drop.item ? '#a78bfa' : '#fcd34d')
    }

    this.drops = kept
  }

  /**
   * Bolts in flight.
   *
   * Run on every client, not just the host: a projectile is *yours*, and its damage goes through
   * the same {@link hurt} path as a sword — the host lands it, or is asked to. Walls stop
   * everything, which is what makes a corridor a corridor.
   */
  private updateProjectiles(dt: number, tick: Tick) {
    const kept: Projectile[] = []

    for (const bolt of this.projectiles) {
      bolt.life -= dt
      bolt.x += bolt.vx * dt
      bolt.y += bolt.vy * dt

      if (bolt.life <= 0 || !this.walkable(bolt.x, bolt.y)) continue

      let spent = false

      for (const monster of this.monsters) {
        if (!monster.alive || bolt.struck.includes(monster.id)) continue
        if (Math.hypot(monster.x - bolt.x, monster.y - bolt.y) > 0.65) continue

        bolt.struck.push(monster.id)
        this.hurt(monster, bolt.damage, tick)

        // A splash catches everything around the impact, each thing once.
        if (bolt.splash > 0) {
          for (const other of this.monsters) {
            if (!other.alive || other.id === monster.id) continue
            if (Math.hypot(other.x - bolt.x, other.y - bolt.y) > bolt.splash) continue
            this.hurt(other, Math.round(bolt.damage * 0.6), tick)
          }
        }

        if (bolt.pierce > 0 && bolt.splash === 0) bolt.pierce -= 1
        else spent = true

        break
      }

      if (!spent) kept.push(bolt)
    }

    this.projectiles = kept
  }

  /**
   * Summoned things, which are monsters that like you.
   *
   * Host-only, exactly like the monster AI and for exactly the same reason: whether the wolf
   * killed the skeleton decides who gets the experience, so it can't be a matter of opinion.
   */
  private updateMinions(dt: number) {
    if (!this.isHost) {
      for (const minion of this.minions) minion.life -= dt
      this.minions = this.minions.filter(m => m.life > 0 && m.hp > 0)

      return
    }

    for (const minion of this.minions) {
      minion.life -= dt
      minion.cooldown = Math.max(0, minion.cooldown - dt)

      // Nearest living monster, or drift back toward its summoner when the room is clear.
      let quarry: Monster | null = null
      let best = 9

      for (const monster of this.monsters) {
        if (!monster.alive) continue
        const d = Math.hypot(monster.x - minion.x, monster.y - minion.y)
        if (d < best) {
          quarry = monster
          best = d
        }
      }

      const goal = quarry ?? { x: this.x, y: this.y }
      const dx = goal.x - minion.x
      const dy = goal.y - minion.y
      const distance = Math.hypot(dx, dy) || 1

      if (distance > (quarry ? 0.9 : 2.5)) {
        const step = 3.4 * dt
        const nx = minion.x + (dx / distance) * step
        const ny = minion.y + (dy / distance) * step
        if (this.walkable(nx, minion.y)) minion.x = nx
        if (this.walkable(minion.x, ny)) minion.y = ny
      } else if (quarry && minion.cooldown <= 0) {
        minion.cooldown = 1
        quarry.hp -= minion.damage
        quarry.hurt = 0.15
        // A minion's kill is the dungeon's, not a player's: no experience, no loot. Otherwise
        // the best build is the one that never fights, and that's not a game.
        if (quarry.hp <= 0) quarry.alive = false
      }
    }

    this.minions = this.minions.filter(m => m.life > 0 && m.hp > 0)
  }

  /**
   * The monsters' turn.
   *
   * Only the host thinks — everyone else is looking at whispered positions — but *everyone* runs
   * the part that hurts them, because being hit is a fact about you and your client already
   * knows where you're standing. So a guest takes damage from a monster the host is steering,
   * without a round trip, and the host is never asked to referee somebody else's health.
   */
  private updateMonsters(dt: number, tick: Tick) {
    for (const monster of this.monsters) {
      if (!monster.alive) continue

      monster.hurt = Math.max(0, monster.hurt - dt)
      monster.cooldown = Math.max(0, monster.cooldown - dt)

      const toMe = Math.hypot(this.x - monster.x, this.y - monster.y)

      if (this.isHost) {
        // Chase whoever's nearest, party included — a monster that only ever wants the host is
        // a monster that walks past three people to get to one.
        let prey = { x: this.x, y: this.y, d: this.alive ? toMe : Infinity }

        for (const ghost of this.ghosts) {
          const d = Math.hypot(ghost.x - monster.x, ghost.y - monster.y)
          if (d < prey.d) prey = { x: ghost.x, y: ghost.y, d }
        }

        if (prey.d < 11 && prey.d > 0.9) {
          const step = monster.speed * dt
          const nx = monster.x + ((prey.x - monster.x) / prey.d) * step
          const ny = monster.y + ((prey.y - monster.y) / prey.d) * step
          if (this.walkable(nx, monster.y)) monster.x = nx
          if (this.walkable(monster.x, ny)) monster.y = ny
        }

        // Minions can be killed, or they'd be a permanent free wall. Host-side, like the rest
        // of what a monster does.
        if (monster.cooldown <= 0) {
          const minion = this.minions.find(m => Math.hypot(m.x - monster.x, m.y - monster.y) < 1.1)
          if (minion) {
            monster.cooldown = 1.1
            minion.hp -= monster.damage
          }
        }
      }

      // The swing that lands on *me* — run by every client about its own hero.
      if (this.alive && toMe < 1.15 && monster.cooldown <= 0) {
        monster.cooldown = 1.1
        const damage = Math.max(1, monster.damage - Math.floor(this.armour() / 2))
        this.hp -= damage
        this.splat(this.x, this.y - 0.4, String(damage), '#ef4444')
      }
    }

    void tick
  }

  private splat(x: number, y: number, text: string, colour: string) {
    if (!text) return
    this.splats.push({ x, y, text, life: 0.9, colour })
  }

  // --- drawing ---

  /**
   * Draw the floor, by torchlight.
   *
   * Everything is drawn relative to a camera locked to the hero, then the whole thing is covered
   * by a radial gradient that fades to black at {@link SIGHT} tiles. That darkness isn't
   * decoration — it's the reason a corridor is tense and the reason a party spreads out.
   */
  render(now: number) {
    const ctx = this.ctx
    const camX = this.w / 2 - this.x * TILE
    const camY = this.h / 2 - this.y * TILE

    ctx.fillStyle = '#07060a'
    ctx.fillRect(0, 0, this.w, this.h)

    ctx.save()
    ctx.translate(camX, camY)

    // Only the tiles that could be on screen — MAP² every frame would be 3,136 fills.
    const x0 = Math.max(0, Math.floor(this.x - this.w / (2 * TILE) - 1))
    const x1 = Math.min(MAP - 1, Math.ceil(this.x + this.w / (2 * TILE) + 1))
    const y0 = Math.max(0, Math.floor(this.y - this.h / (2 * TILE) - 1))
    const y1 = Math.min(MAP - 1, Math.ceil(this.y + this.h / (2 * TILE) + 1))

    for (let ty = y0; ty <= y1; ty++) {
      for (let tx = x0; tx <= x1; tx++) {
        if (!this.level.walkable[at(tx, ty)]) continue

        // A cheap deterministic wobble in the flagstones, so a big room isn't one flat colour.
        const shade = ((tx * 7 + ty * 13) % 5) * 3
        ctx.fillStyle = `rgb(${34 + shade}, ${30 + shade}, ${38 + shade})`
        ctx.fillRect(tx * TILE, ty * TILE, TILE, TILE)
        ctx.strokeStyle = 'rgba(0,0,0,0.35)'
        ctx.strokeRect(tx * TILE + 0.5, ty * TILE + 0.5, TILE - 1, TILE - 1)
      }
    }

    // The way down, unmistakable from across a room.
    const stairs = this.level.stairs
    ctx.fillStyle = '#1e293b'
    ctx.fillRect(stairs.x * TILE, stairs.y * TILE, TILE, TILE)
    ctx.strokeStyle = '#64748b'
    ctx.lineWidth = 2
    for (let i = 1; i <= 3; i++) {
      ctx.beginPath()
      ctx.moveTo(stairs.x * TILE + 3, stairs.y * TILE + i * (TILE / 4))
      ctx.lineTo(stairs.x * TILE + TILE - 3, stairs.y * TILE + i * (TILE / 4))
      ctx.stroke()
    }
    ctx.lineWidth = 1

    for (const drop of this.drops) {
      ctx.fillStyle = drop.item ? '#a78bfa' : '#fcd34d'
      ctx.beginPath()
      ctx.arc(drop.x * TILE, drop.y * TILE, drop.item ? 6 : 4, 0, Math.PI * 2)
      ctx.fill()
    }

    for (const monster of this.monsters) {
      if (!monster.alive) continue
      this.drawMonster(monster, now)
    }

    // Minions: yours in green, somebody else's in the party blue, so a room full of skeletons
    // still reads at a glance.
    for (const minion of this.minions) {
      this.shadow(minion.x, minion.y, 6)
      drawMinion(
        ctx,
        minion.kind,
        minion.x * TILE,
        minion.y * TILE + 8,
        TILE * 1.1,
        this.leftOf(minion.x, minion.y),
        minion.owner === this.hero.id,
      )
    }

    for (const bolt of this.projectiles) {
      ctx.strokeStyle = '#fde68a'
      ctx.lineWidth = 2
      ctx.beginPath()
      ctx.moveTo(bolt.x * TILE, bolt.y * TILE)
      // Drawn as a streak along its own heading — a dot moving this fast reads as a stutter.
      ctx.lineTo(bolt.x * TILE - bolt.vx * 2.5, bolt.y * TILE - bolt.vy * 2.5)
      ctx.stroke()
      ctx.lineWidth = 1
    }

    for (const ghost of this.ghosts) {
      this.drawFigure(ghost.x, ghost.y, ghost.name, ghost.attacking > now, ghost.line ?? 'swordsman', ghost.tier ?? 1, true)
    }

    this.drawFigure(this.x, this.y, '', this.swingAnim > 0, this.line, this.tier, this.alive)

    for (const splat of this.splats) {
      ctx.globalAlpha = Math.max(0, splat.life)
      ctx.fillStyle = splat.colour
      ctx.font = '600 12px ui-sans-serif, system-ui, sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(splat.text, splat.x * TILE, splat.y * TILE - 18 * (1 - splat.life))
    }
    ctx.globalAlpha = 1

    ctx.restore()

    // Torchlight, last, over everything.
    const light = ctx.createRadialGradient(this.w / 2, this.h / 2, TILE * 2, this.w / 2, this.h / 2, SIGHT * TILE)
    light.addColorStop(0, 'rgba(0,0,0,0)')
    light.addColorStop(0.62, 'rgba(0,0,0,0.35)')
    light.addColorStop(1, 'rgba(0,0,0,0.97)')
    ctx.fillStyle = light
    ctx.fillRect(0, 0, this.w, this.h)

    // Last of all, so the dark never falls across the instrument you navigate by.
    if (this.minimap) this.renderMinimap()
  }

  /**
   * Remember the ground around the hero.
   *
   * A square rather than a circle, and a little wider than the torchlight: the map is a memory of
   * where you walked, not a light-cone trace, and a corridor you brushed past should be on it.
   * Only the tiles in the box are touched, so this is a few hundred byte writes a frame however
   * big the floor is.
   */
  private explore() {
    const reach = Math.ceil(SIGHT)
    const x0 = Math.max(0, Math.floor(this.x) - reach)
    const x1 = Math.min(MAP - 1, Math.floor(this.x) + reach)
    const y0 = Math.max(0, Math.floor(this.y) - reach)
    const y1 = Math.min(MAP - 1, Math.floor(this.y) + reach)

    for (let y = y0; y <= y1; y++) {
      for (let x = x0; x <= x1; x++) this.seen[at(x, y)] = 1
    }
  }

  /**
   * The corner map.
   *
   * Drawn in screen space, after the torchlight and outside the camera transform, because it's an
   * instrument rather than part of the world. It shows the floor you've *walked* plus the things
   * navigation actually needs — the stairs, and where the party is. Deliberately not monsters:
   * a map that tells you what's around the next corner is a map that removes the corner.
   */
  private renderMinimap() {
    const ctx = this.ctx
    // Proportional, with no pixel clamp: `w`/`h` are *device* pixels (the panel scales the canvas
    // by devicePixelRatio and the engine draws in those units, as TILE does), so a fixed 150 would
    // come out half the size on a retina screen. A fraction of the short side is DPR-independent.
    const size = Math.round(Math.min(this.w, this.h) * 0.28)
    const pad = Math.round(size * 0.07)
    const scale = size / MAP
    // Bottom right: the top strip already holds the party and the buttons, and the descend
    // prompt lands bottom-centre. This is the one corner nothing else wants.
    const left = this.w - size - pad
    const top = this.h - size - pad

    ctx.save()
    ctx.globalAlpha = 0.85
    ctx.fillStyle = '#07060a'
    ctx.fillRect(left, top, size, size)
    ctx.strokeStyle = 'rgba(148,163,184,0.35)'
    ctx.strokeRect(left + 0.5, top + 0.5, size - 1, size - 1)
    ctx.globalAlpha = 1

    // The floor as remembered. One fill per explored tile; at 56² that's a few thousand at the
    // very worst, and only the walkable ones are drawn at all.
    ctx.fillStyle = '#3f3f46'
    for (let y = 0; y < MAP; y++) {
      for (let x = 0; x < MAP; x++) {
        if (!this.seen[at(x, y)] || !this.level.walkable[at(x, y)]) continue
        ctx.fillRect(left + x * scale, top + y * scale, Math.ceil(scale), Math.ceil(scale))
      }
    }

    const dot = (x: number, y: number, colour: string, radius: number) => {
      ctx.fillStyle = colour
      ctx.beginPath()
      ctx.arc(left + x * scale, top + y * scale, radius, 0, Math.PI * 2)
      ctx.fill()
    }

    // The way down, once you've seen it — the single most useful thing on here.
    const stairs = this.level.stairs
    if (this.seen[at(stairs.x, stairs.y)]) {
      ctx.fillStyle = '#38bdf8'
      ctx.fillRect(left + stairs.x * scale - 2, top + stairs.y * scale - 2, 5, 5)
    }

    for (const ghost of this.ghosts) dot(ghost.x, ghost.y, '#60a5fa', 2)
    dot(this.x, this.y, '#f8fafc', 2.5)

    ctx.restore()
  }

  /** The soft ellipse everything stands on — what keeps a sprite from floating. */
  private shadow(x: number, y: number, radius: number) {
    const ctx = this.ctx
    ctx.fillStyle = 'rgba(0,0,0,0.45)'
    ctx.beginPath()
    ctx.ellipse(x * TILE, y * TILE + 7, radius, radius * 0.42, 0, 0, Math.PI * 2)
    ctx.fill()
  }

  /** Whether a thing at this spot should be drawn mirrored — i.e. is it to my left. */
  private leftOf(x: number, y: number) {
    void y

    return x < this.x
  }

  private drawFigure(
    x: number,
    y: number,
    label: string,
    swinging: boolean,
    line: string,
    tier: number,
    alive: boolean,
  ) {
    const ctx = this.ctx
    const px = x * TILE
    const py = y * TILE
    const mine = x === this.x && y === this.y

    this.shadow(x, y, 8)

    if (!alive) {
      drawFallen(ctx, px, py + 8, TILE * 1.4, line)

      return
    }

    // Your own facing comes from where you're heading; a teammate's from which side of you they
    // are, since their whispers carry a position and not a heading.
    const facingLeft = mine ? Math.cos(this.facing) < 0 : this.leftOf(x, y)
    drawHero(ctx, px, py + 8, TILE * 1.4, line, tier, facingLeft)

    if (swinging) {
      ctx.strokeStyle = '#fde68a'
      ctx.lineWidth = 3
      ctx.beginPath()
      ctx.arc(px, py, 16, this.facing - 0.7, this.facing + 0.7)
      ctx.stroke()
      ctx.lineWidth = 1
    }

    if (label) {
      ctx.fillStyle = 'rgba(226,232,240,0.85)'
      ctx.font = '10px ui-sans-serif, system-ui, sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(label, px, py - 20)
    }
  }

  private drawMonster(monster: Monster, now: number) {
    const ctx = this.ctx
    const px = monster.x * TILE
    const py = monster.y * TILE
    const big = monster.kind === 'lord'

    // A bat bobs; everything else trudges. It costs one sine and reads instantly.
    const bob = monster.kind === 'bat' ? Math.sin(now / 140 + monster.id) * 3 : 0

    this.shadow(monster.x, monster.y, big ? 11 : 7)
    drawBeast(
      ctx,
      monster.kind,
      px,
      py + 8 + bob,
      TILE * (big ? 2.1 : 1.3),
      this.leftOf(monster.x, monster.y),
      monster.hurt > 0,
    )

    if (monster.hp < monster.maxHp) {
      const width = big ? 30 : 18
      const top = py - (big ? 44 : 26) + bob
      ctx.fillStyle = 'rgba(0,0,0,0.6)'
      ctx.fillRect(px - width / 2, top, width, 3)
      ctx.fillStyle = '#dc2626'
      ctx.fillRect(px - width / 2, top, width * Math.max(0, monster.hp / monster.maxHp), 3)
    }
  }
}
