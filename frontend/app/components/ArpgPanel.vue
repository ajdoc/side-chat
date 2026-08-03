<script setup lang="ts">
import {
  Backpack,
  ChevronsDown,
  Heart,
  LogOut,
  Map as MapIcon,
  Skull,
  Sparkle,
  Sparkles,
  Swords,
  X,
  Zap,
} from 'lucide-vue-next'
import type { ArpgState, SpaceGamePayload } from '~/types'
import {
  ArpgEngine,
  type Aid,
  type Hero,
  type Input,
  type Item,
  type KnownSkill,
  type MinionSnap,
  type MonsterSnap,
  type PartyGhost,
} from '~/lib/arpgEngine'
import { Button } from '~/components/ui/button'

/**
 * The Labyrinth, over the room — the dungeon itself, and everything around it.
 *
 * The crawl's counterpart to {@link SpaceGamePanel}, and the most self-sufficient of the three
 * game panels, because a crawl is the one game whose moves are not rare. {@link ArpgEngine} runs
 * the floor; this component is the glue, and it does four jobs the engine deliberately doesn't:
 *
 *   - **input and the frame loop** — pointer to walk and to swing, a canvas that follows the
 *     window, an animation frame that stops when the run does;
 *   - **the party over whispers** — everyone's position at ~12Hz and, from the host alone, the
 *     monsters at ~8Hz, peer-to-peer over the channel's Reverb stream and never through Laravel,
 *     the same trick typing indicators use;
 *   - **outcomes to the server, in batches** — experience and gold every few seconds rather than
 *     per skeleton, and loot, death and descent as they happen;
 *   - **the sheet** — health, the party, the bag, and what to do when you're standing on stairs.
 *
 * ## Who runs the monsters
 *
 * The lowest user id in the run. That's an election with no messages in it: every client can
 * compute the same answer from state everyone already has, and it re-decides itself the moment
 * that player leaves. The host simulates and whispers; everyone else applies what arrives and
 * asks the host to land their hits. See the engine's note on why monsters need one author when
 * the shooter's aliens didn't.
 */
const props = defineProps<{
  game: SpaceGamePayload
  channelId: number
  myId: number | null
}>()

const emit = defineEmits<{
  /** A durable outcome for the server: progress, loot, equip, drop, died, revive, descend, leave. */
  act: [string, Record<string, unknown>]
  dismiss: []
}>()

const echo: any = import.meta.client ? useNuxtApp().$echo : null
const {
  jobs,
  foreignLimits,
  maxSkillLevel,
  byId,
  load: loadSkills,
  line,
  ownTrees,
  foreignTrees,
  foreignCount,
  tiersOpenTo,
} = useArpgSkills()

const state = computed(() => (props.game.type === 'arpg' ? props.game.state as ArpgState | null : null))
const me = computed(() => state.value?.me ?? null)
const winner = computed(() => state.value?.winner ?? null)

/** The party, for the roster strip. */
const party = computed(() => Object.entries(state.value?.players ?? {})
  .map(([id, p]) => ({ id: Number(id), ...p }))
  .sort((a, b) => a.id - b.id))

/** The host is simply the lowest id playing — an election nobody has to hold. */
const isHost = computed(() => party.value.length > 0 && party.value[0]!.id === props.myId)

const channelName = computed(() => `channel.${props.channelId}`)

// --- the sheet, totalled ---

/**
 * The hero the engine fights with: attributes plus everything worn.
 *
 * Totalled here rather than in the engine because the *server* owns the character and the engine
 * owns the fight; this is the seam between them.
 */
const hero = computed<Hero | null>(() => {
  const sheet = me.value
  if (!sheet || props.myId === null) return null

  const bonus = { damage: 0, armour: 0, life: 0, mana: 0, strength: 0, dexterity: 0, magic: 0, vitality: 0 }
  for (const item of Object.values(sheet.equipment ?? {})) {
    if (!item) continue
    for (const [key, value] of Object.entries(item.affixes ?? {})) {
      bonus[key as keyof typeof bonus] += value ?? 0
    }
  }

  // Learned ids resolved against the served catalogue — an id the client hasn't heard of (a skill
  // added while this page was open) is simply not in the bar rather than a crash mid-fight.
  const skills: KnownSkill[] = Object.entries(sheet.skills ?? {})
    .map(([id, level]) => ({ def: byId.value.get(id), level }))
    .filter((s): s is KnownSkill => s.def !== undefined)
    .sort((a, b) => a.def.level - b.def.level)

  return {
    id: props.myId,
    name: sheet.name,
    class: sheet.class,
    level: sheet.level,
    primary: sheet.primary ?? 'strength',
    tier: tier.value,
    skills,
    strength: (sheet.stats?.strength ?? 10) + bonus.strength,
    dexterity: (sheet.stats?.dexterity ?? 10) + bonus.dexterity,
    magic: (sheet.stats?.magic ?? 10) + bonus.magic,
    vitality: (sheet.stats?.vitality ?? 10) + bonus.vitality,
    bonusDamage: bonus.damage,
    bonusArmour: bonus.armour,
    bonusLife: bonus.life,
    bonusMana: bonus.mana,
  }
})

/** Experience is a quadratic curve (level n costs 100·n²), mirrored from ArpgGame::levelFor. */
const xpFraction = computed(() => {
  const sheet = me.value
  if (!sheet) return 0
  const floor = 100 * (sheet.level - 1) ** 2
  const ceiling = 100 * sheet.level ** 2

  return Math.max(0, Math.min(1, (sheet.xp - floor) / Math.max(1, ceiling - floor)))
})

// --- the frame loop ---

const canvas = ref<HTMLCanvasElement | null>(null)
const wrap = ref<HTMLElement | null>(null)
const showBag = ref(false)
const showSkills = ref(false)
const atStairs = ref(false)
const hp = ref(1)
const maxHp = ref(1)
const mana = ref(0)
const maxMana = ref(1)
const alive = ref(true)

/**
 * The skill the pointer will use, or null for the plain swing.
 *
 * One armed skill rather than a key per skill — Diablo's own answer, and the only one that works
 * on a phone. Cooldowns are read off the engine each frame so the bar can grey itself out.
 */
const armed = ref<string | null>(null)
const cooldowns = ref<Record<string, number>>({})

let engine: ArpgEngine | null = null
let raf = 0
let lastFrame = 0
let observer: ResizeObserver | null = null

const input: Input = { pointer: null, down: false, skill: null, moveX: 0, moveY: 0 }

/**
 * Keys currently held, by the direction they mean.
 *
 * A set rather than a pair of numbers so that pressing D while A is still down does the obvious
 * thing when A comes up — the axis is recomputed from what's held, not toggled.
 */
const held = new Set<string>()

const KEYS: Record<string, string> = {
  KeyW: 'up', ArrowUp: 'up',
  KeyS: 'down', ArrowDown: 'down',
  KeyA: 'left', ArrowLeft: 'left',
  KeyD: 'right', ArrowRight: 'right',
}

/** Don't steal WASD from somebody typing in the room's chat. */
function typing() {
  const el = document.activeElement as HTMLElement | null

  return !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)
}

function onKeyDown(event: KeyboardEvent) {
  // Number keys arm a skill, so the bar is reachable without letting go of the movement keys.
  // 0 (and any digit past the end of the bar) goes back to the plain swing.
  if (!typing() && event.code.startsWith('Digit')) {
    const index = Number(event.code.slice(5)) - 1
    const skills = hero.value?.skills ?? []
    armed.value = index >= 0 && index < skills.length ? skills[index]!.def.id : null

    return
  }

  // M for the map, the key every game in the genre uses.
  if (event.code === 'KeyM' && !typing()) {
    toggleMinimap()

    return
  }

  const direction = KEYS[event.code]
  if (!direction || typing()) return

  held.add(direction)
  applyKeys()
  // Arrows scroll the panel behind us otherwise.
  event.preventDefault()
}

function onKeyUp(event: KeyboardEvent) {
  const direction = KEYS[event.code]
  if (!direction) return

  held.delete(direction)
  applyKeys()
}

function applyKeys() {
  input.moveX = (held.has('right') ? 1 : 0) - (held.has('left') ? 1 : 0)
  input.moveY = (held.has('down') ? 1 : 0) - (held.has('up') ? 1 : 0)
}

/**
 * The corner map, on or off.
 *
 * Held here as well as on the engine so the button can show its state, and re-applied whenever a
 * new floor rebuilds the engine — a preference should survive the stairs even though the explored
 * map deliberately doesn't.
 */
const showMap = ref(true)

function toggleMinimap() {
  showMap.value = !showMap.value
  if (engine) engine.minimap = showMap.value
}

/** A window that loses focus mid-stride would otherwise walk you into a wall forever. */
function releaseKeys() {
  held.clear()
  applyKeys()
}

/** The other heroes, from their whispers. Pruned when they go quiet. */
const ghosts = new Map<number, PartyGhost>()

// Batched outcomes — one action per skeleton would be one round trip per skeleton.
let pendingXp = 0
let pendingGold = 0
let flushTimer: ReturnType<typeof setInterval> | null = null
let posTimer: ReturnType<typeof setInterval> | null = null
let mobTimer: ReturnType<typeof setInterval> | null = null

function build() {
  const sheet = state.value
  const context = canvas.value?.getContext('2d')
  if (!sheet || !context || !hero.value) return

  engine = new ArpgEngine(context, canvas.value!.width, canvas.value!.height, {
    seed: sheet.seed,
    depth: sheet.depth,
    hero: hero.value,
    isHost: isHost.value,
  })
  engine.minimap = showMap.value
  maxHp.value = engine.maxHp
  hp.value = engine.hp
  alive.value = true
}

function resize() {
  const box = wrap.value
  const element = canvas.value
  if (!box || !element) return

  const ratio = Math.min(2, window.devicePixelRatio || 1)
  element.width = Math.floor(box.clientWidth * ratio)
  element.height = Math.floor(box.clientHeight * ratio)
  element.style.width = `${box.clientWidth}px`
  element.style.height = `${box.clientHeight}px`
  engine?.setSize(element.width, element.height)
}

function frame(now: number) {
  const dt = lastFrame ? (now - lastFrame) / 1000 : 0
  lastFrame = now

  if (engine) {
    prune(Date.now())
    engine.setGhosts([...ghosts.values()])
    input.skill = armed.value

    const tick = engine.update(dt, input)
    engine.render(now)

    hp.value = Math.max(0, engine.hp)
    maxHp.value = engine.maxHp
    mana.value = engine.mana
    maxMana.value = engine.maxMana
    alive.value = engine.alive
    atStairs.value = tick.atStairs

    // Read once a frame rather than watched: a cooldown is a number that changes every frame and
    // nothing about it needs reactivity beyond being drawn.
    const left: Record<string, number> = {}
    for (const skill of hero.value?.skills ?? []) left[skill.def.id] = engine.cooldownLeft(skill.def.id)
    cooldowns.value = left

    pendingXp += tick.xp
    pendingGold += tick.gold

    // Loot is sent as it's picked up: a bag that fills silently and then rejects an item three
    // corridors later would be a mystery, so the refusal happens at the corpse.
    for (const item of tick.loot) emit('act', 'loot', { item: item satisfies Item })

    if (tick.died) {
      flush()
      emit('act', 'died', {})
    }

    // A guest's swings are claims until the host lands them.
    for (const hit of tick.hits) {
      echo?.private(channelName.value).whisper('arpg-hit', { to: party.value[0]?.id, m: hit.monster, d: hit.damage })
    }

    // Same for anything a guest wants to *exist*: the host owns every entity on the floor.
    for (const summon of tick.summons) {
      echo?.private(channelName.value).whisper('arpg-summon', { to: party.value[0]?.id, by: props.myId, s: summon })
    }

    // Heals and buffs land on the recipient's own client — nobody can write to somebody else's
    // health, so we ask them to.
    for (const aid of tick.aid) {
      echo?.private(channelName.value).whisper('arpg-aid', aid)
    }
  }

  raf = requestAnimationFrame(frame)
}

/** Bank what's been earned. Called on a timer, on death, and on the way out. */
function flush() {
  if (pendingXp === 0 && pendingGold === 0) return
  const xp = pendingXp
  const gold = pendingGold
  pendingXp = 0
  pendingGold = 0
  emit('act', 'progress', { xp, gold })
}

// --- the party, over whispers ---

function whisperPosition() {
  if (!engine || props.myId === null) return
  const snap = engine.playerSnapshot()
  echo?.private(channelName.value).whisper('arpg-pos', {
    id: props.myId,
    name: me.value?.name ?? '',
    x: snap.x,
    y: snap.y,
    hp: snap.hp,
    // Your class and tier ride along so the party draws you as yourself — two extra fields on a
    // message that already goes out twelve times a second, against a round trip to learn them.
    c: me.value?.class,
    t: tier.value,
  })
}

function whisperMonsters() {
  if (!engine || !isHost.value) return
  echo?.private(channelName.value).whisper('arpg-mobs', {
    d: state.value?.depth,
    m: engine.monsterSnapshot(),
    n: engine.minionSnapshot(),
  })
}

function subscribe() {
  const channel = echo?.private(channelName.value)
  if (!channel) return

  channel.listenForWhisper('arpg-pos', (m: { id: number, name: string, x: number, y: number, hp: number, c?: string, t?: number }) => {
    if (m.id === props.myId) return
    ghosts.set(m.id, {
      id: m.id,
      name: m.name,
      x: m.x,
      y: m.y,
      hp: m.hp,
      at: Date.now(),
      attacking: 0,
      line: m.c,
      tier: m.t ?? 1,
    })
  })

  channel.listenForWhisper('arpg-mobs', (m: { d: number, m: MonsterSnap[], n?: MinionSnap[] }) => {
    // A snapshot from the floor above is somebody who hasn't descended yet — ignoring it is the
    // whole of the "are we on the same floor" problem.
    if (m.d !== state.value?.depth) return
    engine?.applySnapshot(m.m)
    engine?.applyMinionSnapshot(m.n ?? [])
  })

  channel.listenForWhisper('arpg-hit', (m: { to: number, m: number, d: number }) => {
    if (m.to === props.myId) engine?.applyHit(m.m, m.d)
  })

  channel.listenForWhisper('arpg-summon', (m: { to: number, by: number, s: any }) => {
    if (m.to === props.myId) engine?.raise(m.by, m.s)
  })

  channel.listenForWhisper('arpg-aid', (m: Aid) => {
    if (m.to !== props.myId) return
    if (m.heal > 0) engine?.receiveHeal(m.heal)
    if (m.stat && m.amount) engine?.applyBuff(m.stat, m.amount, m.duration ?? 10)
  })
}

function unsubscribe() {
  const channel = echo?.private(channelName.value)
  for (const event of ['arpg-pos', 'arpg-mobs', 'arpg-hit', 'arpg-summon', 'arpg-aid']) {
    channel?.stopListeningForWhisper(event)
  }
}

/** Somebody who's stopped whispering has walked out, or crashed. Either way, stop drawing them. */
function prune(now: number) {
  for (const [id, ghost] of ghosts) {
    if (now - ghost.at > 3000) ghosts.delete(id)
  }
}

// --- input ---

function pointerAt(event: PointerEvent) {
  const element = canvas.value
  if (!element) return null
  const box = element.getBoundingClientRect()
  const ratio = element.width / box.width

  return { x: (event.clientX - box.left) * ratio, y: (event.clientY - box.top) * ratio }
}

function onPointerDown(event: PointerEvent) {
  ;(event.target as HTMLElement).setPointerCapture?.(event.pointerId)
  input.down = true
  input.pointer = pointerAt(event)
}

function onPointerMove(event: PointerEvent) {
  if (input.down) input.pointer = pointerAt(event)
}

function onPointerUp() {
  input.down = false
  input.pointer = null
}

// --- the buttons ---

function descend() {
  flush()
  emit('act', 'descend', {})
}

function rise() {
  engine?.revive()
  alive.value = true
  emit('act', 'revive', {})
}

function leave() {
  flush()
  emit('act', 'leave', {})
}

/**
 * Spend a point, without leaving the dungeon.
 *
 * Every rule about whether you may — the level it opens at, the ceiling, the three-skill
 * inheritance cap — is the server's, so this asks and shows what comes back rather than
 * duplicating the arithmetic. What the UI decides is only what to grey out first.
 */
const learnError = ref('')

function learn(id: string) {
  learnError.value = ''
  emit('act', 'learn', { skill: id })
}

/** How far along their line this hero is — a second job wears the same silhouette, better dressed. */
const tier = computed(() => (me.value ? (jobs.value[me.value.job]?.tier ?? 1) : 1))

/** Take the next job in the line. Only ever offered when the server says it's earned. */
function advance() {
  emit('act', 'advance', {})
}

/** Your own line's trees — a wizard sees the mage's tree too, because it's still theirs. */
const myTrees = computed(() => (me.value ? ownTrees(me.value.job) : []))

/**
 * The borrowable half, one section per tier the hero can touch.
 *
 * Split by tier because the cap is: the three you spent as a mage are not the three you're owed
 * as a wizard, and one flat list would hide that.
 */
const borrowable = computed(() => {
  const sheet = me.value
  if (!sheet) return []

  return tiersOpenTo(sheet.job).map(tier => ({
    tier,
    used: foreignCount(sheet.skills ?? {}, sheet.job, tier),
    limit: foreignLimits.value[tier] ?? 3,
    groups: foreignTrees(sheet.job, tier),
  }))
})

/** Is this skill part of the hero's own line, or would learning it be borrowing? */
function isOwn(id: string) {
  const def = byId.value.get(id)

  return !!def && !!me.value && line(me.value.job).includes(def.job)
}

/** Why a given skill can't be taken right now — null when it can. */
function blocked(id: string): string | null {
  const sheet = me.value
  const def = byId.value.get(id)
  if (!sheet || !def) return 'Unknown'

  const known = sheet.skills?.[id] ?? 0

  if (known >= maxSkillLevel.value) return 'Maxed'
  // A second-job skill needs a second job — your own or anyone's. Advancement buys the tier.
  if (!tiersOpenTo(sheet.job).includes(def.tier)) return 'Needs 2nd job'
  if (sheet.level < def.level) return `Level ${def.level}`
  if (sheet.skill_points < 1) return 'No points'

  if (known === 0 && !isOwn(id)) {
    const limit = foreignLimits.value[def.tier] ?? 3
    if (foreignCount(sheet.skills ?? {}, sheet.job, def.tier) >= limit) return `${limit} borrowed`
  }

  return null
}

function equip(index: number) {
  emit('act', 'equip', { index })
}

function drop(index: number) {
  emit('act', 'drop', { index })
}

const RARITY: Record<string, string> = {
  common: 'text-slate-300',
  magic: 'text-sky-400',
  rare: 'text-amber-300',
  unique: 'text-fuchsia-400',
}

/** An item's affixes as a line of text — the only description the genre ever needed. */
function describe(item: Item) {
  return Object.entries(item.affixes ?? {})
    .map(([key, value]) => `+${value} ${key}`)
    .join(', ')
}

// --- lifecycle ---

onMounted(async () => {
  // The catalogue first: a hero's skills are ids until it arrives, and the engine needs the
  // resolved list to know what a swing does.
  await loadSkills()
  resize()
  build()
  subscribe()

  observer = new ResizeObserver(resize)
  if (wrap.value) observer.observe(wrap.value)

  window.addEventListener('keydown', onKeyDown)
  window.addEventListener('keyup', onKeyUp)
  window.addEventListener('blur', releaseKeys)

  posTimer = setInterval(whisperPosition, 80)
  mobTimer = setInterval(whisperMonsters, 125)
  flushTimer = setInterval(flush, 2500)
  raf = requestAnimationFrame(frame)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  if (posTimer) clearInterval(posTimer)
  if (mobTimer) clearInterval(mobTimer)
  if (flushTimer) clearInterval(flushTimer)
  observer?.disconnect()
  window.removeEventListener('keydown', onKeyDown)
  window.removeEventListener('keyup', onKeyUp)
  window.removeEventListener('blur', releaseKeys)
  unsubscribe()
  flush()
  engine = null
})

/** A new floor is a new world: rebuild rather than trying to mutate one into the other. */
watch(() => state.value?.depth, (depth, was) => {
  if (depth === undefined || depth === was) return
  ghosts.clear()
  build()
})

/** A level, or a new sword: re-total without disturbing the fight. */
watch(hero, (next) => {
  if (next) engine?.setHero(next)
})

/** The host can change under you — someone leaves, and the next lowest id picks up the monsters. */
watch(isHost, host => engine?.setHost(host))
</script>

<template>
  <div class="pointer-events-auto absolute inset-0 z-30 flex flex-col bg-[#07060a]">
    <!-- OVER — the run resolved, one way or another. -->
    <div v-if="winner" class="grid flex-1 place-items-center p-4">
      <div class="w-full max-w-sm space-y-4 rounded-xl border bg-background p-5 text-center shadow-xl">
        <component
          :is="winner === 'party' ? Sparkles : Skull"
          class="mx-auto h-8 w-8"
          :class="winner === 'party' ? 'text-primary' : 'text-destructive'"
        />
        <div>
          <p class="text-lg font-semibold">
            {{ winner === 'party' ? 'The Labyrinth is beaten' : winner === 'dungeon' ? 'The party has fallen' : 'The portal closes' }}
          </p>
          <p class="text-sm text-muted-foreground">
            Your hero keeps everything they earned down there.
          </p>
        </div>
        <Button class="w-full" @click="emit('dismiss')">Back to the room</Button>
      </div>
    </div>

    <template v-else>
      <!-- The dungeon. Click to walk, click a monster in reach to swing. -->
      <div ref="wrap" class="relative min-h-0 flex-1">
        <canvas
          ref="canvas"
          class="h-full w-full touch-none select-none"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointercancel="onPointerUp"
        />

        <!-- Top strip: where you are, who's with you, and the way out. -->
        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between gap-2 p-2">
          <div class="rounded-md bg-black/60 px-2.5 py-1.5 text-xs text-white backdrop-blur">
            <p class="font-semibold">Floor {{ state?.depth }} <span class="font-normal text-white/50">of {{ state?.max_depth }}</span></p>
            <p class="text-[11px] text-white/60">
              {{ me?.name }} · level {{ me?.level }} {{ me?.job_name }} · {{ me?.gold }}g
            </p>
          </div>

          <div class="flex items-center gap-1.5">
            <span
              v-for="p in party"
              :key="p.id"
              class="pointer-events-none rounded-full px-2 py-1 text-[11px] font-medium backdrop-blur"
              :class="p.alive ? 'bg-black/60 text-white' : 'bg-destructive/70 text-white line-through'"
            >{{ p.name }} <span class="text-white/50">{{ p.level }}</span></span>

            <button
              type="button"
              class="pointer-events-auto rounded-full p-1.5 text-white backdrop-blur transition hover:bg-black/80"
              :class="showMap ? 'bg-primary/70' : 'bg-black/60'"
              title="Map (M)"
              @click="toggleMinimap"
            >
              <MapIcon class="h-3.5 w-3.5" />
            </button>
            <button
              type="button"
              class="pointer-events-auto rounded-full bg-black/60 p-1.5 text-white backdrop-blur transition hover:bg-black/80"
              title="Leave the dungeon"
              @click="leave"
            >
              <LogOut class="h-3.5 w-3.5" />
            </button>
            <button
              type="button"
              class="pointer-events-auto rounded-full bg-black/60 p-1.5 text-white backdrop-blur transition hover:bg-black/80"
              title="End the run for everyone"
              @click="emit('dismiss')"
            >
              <X class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <!-- Dead: the party can walk on without you, or you can pick yourself up. -->
        <div v-if="!alive" class="pointer-events-none absolute inset-0 grid place-items-center bg-black/60">
          <div class="pointer-events-auto space-y-3 text-center">
            <Skull class="mx-auto h-9 w-9 text-destructive" />
            <p class="text-lg font-semibold text-white">You have fallen</p>
            <p class="max-w-xs text-xs text-white/60">Some of your gold is scattered on the floor above. Your levels are yours.</p>
            <Button size="sm" @click="rise">Rise</Button>
          </div>
        </div>

        <!-- Standing on the stairs. Anyone can call the descent; the floor changes for everyone. -->
        <div v-else-if="atStairs" class="pointer-events-none absolute inset-x-0 bottom-20 flex justify-center">
          <Button size="sm" class="pointer-events-auto gap-1.5 shadow-lg" @click="descend">
            <ChevronsDown class="h-4 w-4" /> Descend to floor {{ (state?.depth ?? 1) + 1 }}
          </Button>
        </div>

        <!-- How to play, small and in the corner. Costs nothing and saves the first minute.
             Outside the v-if chain above: it shows whatever else is happening. -->
        <p class="pointer-events-none absolute bottom-2 left-2 text-[10px] text-white/40">
          WASD or click to move · click a monster to strike · 1–9 arms a skill · M maps
        </p>
      </div>

      <!-- The skill bar. One armed at a time: tap to arm, tap again for the plain swing. -->
      <div v-if="hero?.skills.length" class="flex items-center gap-1 overflow-x-auto border-t bg-background/95 px-2 py-1.5">
        <button
          type="button"
          class="shrink-0 rounded-md border px-2 py-1 text-[11px] font-medium transition-colors"
          :class="armed === null ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
          title="Plain attack — no mana, no cooldown"
          @click="armed = null"
        >
          <Swords class="h-3.5 w-3.5" />
        </button>

        <button
          v-for="skill in hero.skills"
          :key="skill.def.id"
          type="button"
          class="relative shrink-0 rounded-md border px-2 py-1 text-[11px] font-medium transition-colors disabled:opacity-40"
          :class="armed === skill.def.id ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
          :title="`${skill.def.name} (level ${skill.level}) — ${skill.def.blurb}`"
          :disabled="mana < skill.def.mana"
          @click="armed = armed === skill.def.id ? null : skill.def.id"
        >
          {{ skill.def.name }}
          <span class="text-muted-foreground">{{ skill.level }}</span>
          <!-- The cooldown, drawn over the button rather than beside it. -->
          <span
            v-if="cooldowns[skill.def.id] > 0"
            class="absolute inset-0 grid place-items-center rounded-md bg-background/80 text-[10px] tabular-nums"
          >{{ cooldowns[skill.def.id].toFixed(1) }}</span>
        </button>
      </div>

      <!-- The sheet: health, mana, experience, the bag. -->
      <div class="flex items-center gap-3 border-t bg-background/95 px-3 py-2 text-xs backdrop-blur">
        <span class="flex items-center gap-1.5 font-semibold text-destructive">
          <Heart class="h-3.5 w-3.5" /> {{ Math.ceil(hp) }}<span class="font-normal text-muted-foreground">/{{ maxHp }}</span>
        </span>

        <div class="h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-muted">
          <div class="h-full bg-destructive transition-all" :style="{ width: `${(hp / Math.max(1, maxHp)) * 100}%` }" />
        </div>

        <span class="flex shrink-0 items-center gap-1.5 font-semibold text-sky-500">
          <Zap class="h-3.5 w-3.5" />
          <span class="h-1.5 w-14 overflow-hidden rounded-full bg-muted">
            <span class="block h-full bg-sky-500 transition-all" :style="{ width: `${(mana / Math.max(1, maxMana)) * 100}%` }" />
          </span>
        </span>

        <div class="flex min-w-0 flex-1 items-center gap-1.5">
          <Swords class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <div class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
            <div class="h-full bg-primary transition-all" :style="{ width: `${xpFraction * 100}%` }" />
          </div>
        </div>

        <button
          type="button"
          class="relative flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 font-medium transition-colors hover:bg-muted"
          @click="showSkills = !showSkills; showBag = false"
        >
          <Sparkle class="h-3.5 w-3.5" />
          <!-- Unspent points nag, because a point you forgot to spend is a level you didn't get. -->
          <span v-if="(me?.skill_points ?? 0) > 0" class="rounded-full bg-primary px-1.5 text-[10px] text-primary-foreground">
            {{ me?.skill_points }}
          </span>
        </button>

        <button
          type="button"
          class="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1 font-medium transition-colors hover:bg-muted"
          @click="showBag = !showBag; showSkills = false"
        >
          <Backpack class="h-3.5 w-3.5" /> {{ me?.inventory?.length ?? 0 }}
        </button>
      </div>

      <!-- The skill screen: your own tree, then everyone else's, capped at three borrowed. -->
      <div v-if="showSkills" class="max-h-64 overflow-y-auto border-t bg-background px-3 py-2">
        <div class="mb-2 flex items-center justify-between text-[11px]">
          <p class="font-semibold uppercase tracking-wide text-muted-foreground">
            {{ me?.skill_points ?? 0 }} point{{ (me?.skill_points ?? 0) === 1 ? '' : 's' }} to spend
          </p>
          <p class="capitalize text-muted-foreground">{{ me?.job_name }}</p>
        </div>

        <p v-if="learnError" class="mb-2 text-xs text-destructive">{{ learnError }}</p>

        <!-- Advancement: the one moment in a run of rising numbers that's an event. -->
        <div
          v-if="me?.advance_to"
          class="mb-3 flex items-center gap-2 rounded-lg border px-3 py-2"
          :class="me.advance_to.ready ? 'border-primary bg-primary/5' : ''"
        >
          <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold">Become a {{ me.advance_to.name }}</p>
            <p class="text-[10px] text-muted-foreground">
              {{ me.advance_to.ready
                ? 'A new tree, and a fresh allowance of borrowed skills.'
                : `Earned at level ${me.advance_to.level}.` }}
            </p>
          </div>
          <Button v-if="me.advance_to.ready" size="sm" class="shrink-0 gap-1.5" @click="advance">
            <Sparkles class="h-3.5 w-3.5" /> Advance
          </Button>
        </div>

        <!-- Your own line: every job you've been, because a wizard hasn't forgotten Firebolt. -->
        <div v-for="tree in myTrees" :key="tree.job" class="mb-3">
          <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-primary">{{ tree.name }}</p>
          <div class="space-y-1">
            <div
              v-for="def in tree.skills"
              :key="def.id"
              class="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1.5"
            >
              <div class="min-w-0 flex-1">
                <p class="truncate text-xs font-medium">
                  {{ def.name }}
                  <span v-if="me?.skills?.[def.id]" class="text-primary">· {{ me.skills[def.id] }}</span>
                  <span class="text-muted-foreground"> · lv{{ def.level }}</span>
                </p>
                <p class="truncate text-[10px] text-muted-foreground">{{ def.blurb }}</p>
              </div>
              <button
                type="button"
                class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                :class="blocked(def.id) ? 'text-muted-foreground' : 'bg-primary/10 text-primary hover:bg-primary/20'"
                :disabled="!!blocked(def.id)"
                @click="learn(def.id)"
              >{{ blocked(def.id) ?? '+1' }}</button>
            </div>
          </div>
        </div>

        <!-- Inheritance, one section per tier: the allowances are separate, so the lists are. -->
        <div v-for="section in borrowable" :key="section.tier" class="mb-3">
          <div class="mb-1 flex items-center justify-between">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
              Borrowed · {{ section.tier === 1 ? 'first job' : 'second job' }}
            </p>
            <p class="text-[10px]" :class="section.used >= section.limit ? 'text-destructive' : 'text-muted-foreground'">
              {{ section.used }}/{{ section.limit }}
            </p>
          </div>

          <div v-for="group in section.groups" :key="group.job" class="mb-1.5">
            <p class="mb-0.5 text-[10px] font-medium text-muted-foreground">{{ group.name }}</p>
            <div class="space-y-1">
              <div
                v-for="def in group.skills"
                :key="def.id"
                class="flex items-center gap-2 rounded-md px-2 py-1 text-xs"
                :class="me?.skills?.[def.id] ? 'bg-primary/5' : ''"
              >
                <span class="min-w-0 flex-1 truncate">
                  {{ def.name }}
                  <span v-if="me?.skills?.[def.id]" class="text-primary">· {{ me.skills[def.id] }}</span>
                  <span class="text-muted-foreground"> · lv{{ def.level }}</span>
                </span>
                <button
                  type="button"
                  class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium transition-colors"
                  :class="blocked(def.id) ? 'text-muted-foreground' : 'bg-primary/10 text-primary hover:bg-primary/20'"
                  :disabled="!!blocked(def.id)"
                  @click="learn(def.id)"
                >{{ blocked(def.id) ?? '+1' }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- The bag. Click to wear; what you were wearing goes back on your belt. -->
      <div v-if="showBag" class="max-h-56 overflow-y-auto border-t bg-background px-3 py-2">
        <p v-if="!me?.inventory?.length" class="py-3 text-center text-xs text-muted-foreground">
          Nothing but lint. Kill something.
        </p>

        <div v-else class="grid gap-1 sm:grid-cols-2">
          <div
            v-for="(item, index) in me.inventory"
            :key="`${item.name}-${index}`"
            class="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1.5"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-xs font-medium" :class="RARITY[item.rarity]">{{ item.name }}</p>
              <p class="truncate text-[10px] text-muted-foreground">{{ describe(item) || item.slot }}</p>
            </div>
            <button type="button" class="shrink-0 text-[10px] font-medium text-primary hover:underline" @click="equip(index)">
              Wear
            </button>
            <button type="button" class="shrink-0 text-[10px] text-muted-foreground hover:underline" @click="drop(index)">
              Drop
            </button>
          </div>
        </div>

        <!-- What's on you now, so "is this better?" is one glance rather than two screens. -->
        <div v-if="me?.equipment && Object.keys(me.equipment).length" class="mt-2 border-t pt-2">
          <p class="mb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Worn</p>
          <div class="flex flex-wrap gap-1">
            <span
              v-for="(item, slot) in me.equipment"
              :key="slot"
              class="rounded bg-muted px-1.5 py-0.5 text-[10px]"
              :class="RARITY[item!.rarity]"
            >{{ item!.name }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
