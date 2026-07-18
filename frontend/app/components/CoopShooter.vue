<script setup lang="ts">
import { Crosshair, Heart, LogOut, Skull, Swords, Users } from 'lucide-vue-next'
import type { RaidGhostMsg, ShooterPlayer, ShooterState, Widget } from '~/types'
import { RaidEngine, type Ghost } from '~/lib/raidEngine'
import { Button } from '~/components/ui/button'

/**
 * Side Raid — a co-op DOOM-style shooter card.
 *
 * The game itself is a client-side canvas raycaster ({@link RaidEngine}); this component is
 * the glue. It:
 *   - drives the render/update loop and keyboard+mouse (pointer-lock) input,
 *   - whispers this player's position over the channel's Reverb stream (~12Hz, peer-to-peer,
 *     never touching Laravel — same trick as typing indicators) so teammates appear as live
 *     ghosts in everyone's view,
 *   - and folds the *shared* outcomes into the widget's persisted state via actions: `join`
 *     on deploy, batched `frag` for kills, `wave` when you clear one, `died` when you fall.
 *
 * Enemies are spawned deterministically from the shared `seed`, so the whole team fights the
 * same waves; they're simulated locally (your demons chase you), which is the honest limit of
 * a broadcast-synced world without a game server. Teammates, though, are real.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()
const { user } = useAuth()
const echo: any = useNuxtApp().$echo

const state = computed(() => props.widget.state as ShooterState)
const isActive = computed(() => state.value.status === 'active')
const isLost = computed(() => state.value.status === 'lost')
const roster = computed(() =>
  Object.entries(state.value.players ?? {})
    .map(([id, p]) => ({ id, ...(p as ShooterPlayer) }))
    .sort((a, b) => b.kills - a.kills || a.name.localeCompare(b.name)),
)
const MEDALS = ['🥇', '🥈', '🥉']
const channelName = computed(() => `channel.${props.widget.channel_id}`)

// --- live game state (this client) ---
const canvas = ref<HTMLCanvasElement | null>(null)
const playing = ref(false)
const locked = ref(false)
const health = ref(100)
const dead = ref(false)
const bossFrac = ref<number | null>(null)
const RES_W = 480
const RES_H = 300

let engine: RaidEngine | null = null
let raf = 0
let lastFrame = 0
let localWave = 1
let lastShotAt = -9999
let shooting = false

// batched kill reporting — one action per demon would be one round trip per demon
let pendingKills = 0
let pendingPoints = 0
let fragTimer: ReturnType<typeof setInterval> | null = null
let posTimer: ReturnType<typeof setInterval> | null = null

// teammate ghosts, keyed by user id, with a last-heard stamp for expiry (non-reactive:
// only the render loop reads them)
const ghosts = new Map<number, Ghost & { at: number }>()
const mates = ref(0)

function deploy() {
  if (!isActive.value) { action(props.widget.id, 'reset'); return }
  playing.value = true
  nextTick(startGame)
}

function startGame() {
  const el = canvas.value
  if (!el) return
  el.width = RES_W
  el.height = RES_H
  const ctx = el.getContext('2d')
  if (!ctx) return

  engine = new RaidEngine(ctx, RES_W, RES_H)
  localWave = state.value.wave || 1
  engine.startWave(localWave, state.value.seed || 1)
  engine.respawn()
  health.value = 100
  dead.value = false

  action(props.widget.id, 'join')
  subscribeGhosts()

  window.addEventListener('keydown', onKey)
  window.addEventListener('keyup', onKey)
  document.addEventListener('pointerlockchange', onLockChange)

  posTimer = setInterval(whisperPosition, 80)
  fragTimer = setInterval(flushFrags, 2500)
  lastFrame = performance.now()
  raf = requestAnimationFrame(frame)
}

const input = { forward: 0, strafe: 0, turn: 0 }

function frame(now: number) {
  if (!engine) return
  const dt = Math.min(0.05, (now - lastFrame) / 1000 || 0)
  lastFrame = now

  if (shooting && !dead.value && now - lastShotAt > 260) {
    lastShotAt = now
    const shot = engine.shoot(now)
    if (shot.killed) { pendingKills++; pendingPoints += shot.points }
    if (shot.waveCleared) onWaveCleared()
  }

  const tick = engine.update(dt, input)
  if (tick.playerDied && !dead.value) onDeath()

  pruneGhosts(now)
  engine.render([...ghosts.values()], now)
  health.value = Math.round(engine.player.hp)
  const b = engine.boss()
  bossFrac.value = b ? b.hp / b.maxHp : null
  raf = requestAnimationFrame(frame)
}

function onWaveCleared() {
  localWave++
  flushFrags()
  action(props.widget.id, 'wave', { wave: localWave })
  engine?.startWave(localWave, state.value.seed || 1)
}

function onDeath() {
  dead.value = true
  flushFrags()
  action(props.widget.id, 'died')
  // Respawn shortly — unless that death was the team's last life, which the status
  // watcher will catch and end the game.
  setTimeout(() => {
    if (!isLost.value && engine) { engine.respawn(); health.value = 100; dead.value = false }
  }, 1100)
}

// --- input ---
function onKey(e: KeyboardEvent) {
  const down = e.type === 'keydown'
  switch (e.key) {
    case 'w': case 'W': case 'ArrowUp': input.forward = down ? 1 : 0; break
    case 's': case 'S': case 'ArrowDown': input.forward = down ? -1 : 0; break
    case 'a': case 'A': input.strafe = down ? -1 : 0; break
    case 'd': case 'D': input.strafe = down ? 1 : 0; break
    case 'ArrowLeft': input.turn = down ? -1 : 0; break
    case 'ArrowRight': input.turn = down ? 1 : 0; break
    case ' ': shooting = down; break
    default: return
  }
  if (playing.value) e.preventDefault()
}

function onCanvasDown(e: MouseEvent) {
  if (!locked.value) { canvas.value?.requestPointerLock?.(); return }
  if (e.button === 0) shooting = true
}
function onCanvasUp(e: MouseEvent) { if (e.button === 0) shooting = false }
function onMouseMove(e: MouseEvent) {
  if (locked.value && engine) engine.player.dir += e.movementX * 0.0024
}
function onLockChange() {
  locked.value = document.pointerLockElement === canvas.value
  if (locked.value) window.addEventListener('mousemove', onMouseMove)
  else { window.removeEventListener('mousemove', onMouseMove); shooting = false }
}

// --- networking: teammate ghosts over whispers ---
function subscribeGhosts() {
  echo?.private(channelName.value).listenForWhisper('raid-pos', (m: RaidGhostMsg) => {
    if (!user.value || m.id === user.value.id) return
    ghosts.set(m.id, {
      id: m.id, name: m.name, x: m.x, y: m.y, dir: m.dir, hp: m.hp,
      firing: m.f ? performance.now() : 0,
      at: performance.now(),
    })
  })
}
function whisperPosition() {
  if (!playing.value || !user.value || !engine) return
  const p = engine.player
  echo?.private(channelName.value).whisper('raid-pos', {
    id: user.value.id,
    name: user.value.name,
    x: Math.round(p.x * 100) / 100,
    y: Math.round(p.y * 100) / 100,
    dir: Math.round(p.dir * 100) / 100,
    hp: Math.round(p.hp),
    f: performance.now() - lastShotAt < 90 ? 1 : 0,
  })
}
function pruneGhosts(now: number) {
  for (const [id, g] of ghosts) if (now - g.at > 2000) ghosts.delete(id)
  mates.value = ghosts.size
}

function flushFrags() {
  if (pendingKills > 0 || pendingPoints > 0) {
    action(props.widget.id, 'frag', { kills: pendingKills, points: pendingPoints })
    pendingKills = 0
    pendingPoints = 0
  }
}

function leave() {
  stopGame()
  playing.value = false
}

function stopGame() {
  if (raf) cancelAnimationFrame(raf)
  raf = 0
  if (posTimer) clearInterval(posTimer)
  if (fragTimer) clearInterval(fragTimer)
  posTimer = fragTimer = null
  flushFrags()
  if (document.pointerLockElement === canvas.value) document.exitPointerLock?.()
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('keyup', onKey)
  window.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('pointerlockchange', onLockChange)
  echo?.private(channelName.value).stopListeningForWhisper('raid-pos')
  ghosts.clear()
  engine = null
  bossFrac.value = null
  input.forward = input.strafe = input.turn = 0
  shooting = false
}

const raidAgain = () => action(props.widget.id, 'reset')

// The team ran out of lives while we were playing — drop us out to the game-over screen.
watch(isLost, (lost) => { if (lost && playing.value) leave() })
// Someone else cleared our wave first — catch up so we're all on the same demons.
watch(() => state.value.wave, (w) => {
  if (playing.value && engine && w > localWave) {
    localWave = w
    engine.startWave(localWave, state.value.seed || 1)
  }
})

onBeforeUnmount(stopGame)
</script>

<template>
  <div class="mt-1.5 w-full max-w-md overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm">
    <!-- Header -->
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Swords class="h-3.5 w-3.5" /> Side Raid
      <span v-if="isActive || isLost" class="ml-auto rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary">Wave {{ state.wave }}</span>
      <span v-if="state.score" class="rounded-full bg-amber-500/15 px-1.5 py-px text-[10px] normal-case text-amber-600 dark:text-amber-400" title="Team score">⭐ {{ state.score }}</span>
    </div>

    <div class="p-3">
      <!-- The arena -->
      <div v-show="playing" class="relative overflow-hidden rounded-lg bg-black">
        <canvas
          ref="canvas"
          class="block w-full cursor-crosshair select-none"
          style="aspect-ratio: 16 / 10; image-rendering: auto;"
          @mousedown="onCanvasDown"
          @mouseup="onCanvasUp"
          @contextmenu.prevent
        />
        <!-- HUD overlay -->
        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between p-2 text-[11px] font-semibold text-white/90">
          <span class="rounded bg-black/40 px-1.5 py-0.5">Wave {{ localWave }}</span>
          <span class="flex items-center gap-1 rounded bg-black/40 px-1.5 py-0.5">
            <Heart class="h-3 w-3 fill-red-500 text-red-500" /> {{ Math.max(0, health) }}
          </span>
        </div>
        <!-- Boss health bar -->
        <div v-if="bossFrac != null" class="pointer-events-none absolute inset-x-0 top-8 flex flex-col items-center gap-0.5 px-6">
          <span class="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-red-300">🐲 Boss</span>
          <div class="h-2 w-full max-w-[220px] overflow-hidden rounded-full border border-red-900/60 bg-black/50">
            <div class="h-full rounded-full bg-gradient-to-r from-red-700 to-red-400 transition-[width] duration-150" :style="{ width: `${Math.max(0, bossFrac * 100)}%` }" />
          </div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 flex items-center justify-between p-2">
          <div class="h-2 w-1/2 overflow-hidden rounded-full bg-black/50">
            <div class="h-full rounded-full bg-gradient-to-r from-red-600 to-emerald-500 transition-[width] duration-150" :style="{ width: `${Math.max(0, health)}%` }" />
          </div>
          <button class="pointer-events-auto flex items-center gap-1 rounded bg-black/50 px-2 py-1 text-[11px] font-medium text-white/90 hover:bg-black/70" @click="leave">
            <LogOut class="h-3 w-3" /> Leave
          </button>
        </div>
        <!-- Prompts -->
        <div v-if="!locked && !dead" class="pointer-events-none absolute inset-0 grid place-items-center">
          <p class="rounded-md bg-black/60 px-3 py-1.5 text-xs font-medium text-white">Click to aim · WASD move · click to shoot</p>
        </div>
        <div v-if="dead" class="pointer-events-none absolute inset-0 grid place-items-center bg-red-950/40">
          <p class="flex items-center gap-1.5 rounded-md bg-black/70 px-3 py-1.5 text-sm font-semibold text-red-300">
            <Skull class="h-4 w-4" /> You fell — respawning…
          </p>
        </div>
      </div>

      <!-- Lobby / game-over (when not in the arena) -->
      <div v-if="!playing">
        <div v-if="isLost" class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-center">
          <p class="flex items-center justify-center gap-1.5 text-sm font-semibold text-red-600 dark:text-red-400">
            <Skull class="h-4 w-4" /> Team wiped on wave {{ state.wave }}
          </p>
          <p class="mt-0.5 text-xs text-muted-foreground">Final score ⭐ {{ state.score }}</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="raidAgain"><Swords class="h-3.5 w-3.5" /> Raid again</Button>
        </div>

        <div v-else-if="isActive" class="rounded-lg border bg-background/50 p-3 text-center">
          <p class="text-sm font-medium">The arena is live 👹</p>
          <p class="mt-0.5 text-xs text-muted-foreground">Fight the same waves as the channel — share {{ state.maxLives }} lives.</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="deploy"><Crosshair class="h-3.5 w-3.5" /> Deploy into the raid</Button>
        </div>

        <div v-else class="rounded-lg border bg-background/50 p-3 text-center">
          <p class="text-sm text-muted-foreground">No raid running.</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="raidAgain"><Swords class="h-3.5 w-3.5" /> Start a raid</Button>
          <p class="mt-2 text-[10px] text-muted-foreground">or type <code class="rounded bg-muted px-1">g!raid</code></p>
        </div>
      </div>

      <!-- Shared lives + leaderboard + feed (always shown for onlookers and downtime) -->
      <template v-if="isActive || isLost">
        <div class="mt-3 flex items-center gap-1.5 text-xs">
          <span class="font-medium text-muted-foreground">Lives</span>
          <span class="flex gap-0.5">
            <Heart
              v-for="i in state.maxLives"
              :key="i"
              class="h-3.5 w-3.5"
              :class="i <= state.teamLives ? 'fill-red-500 text-red-500' : 'text-muted-foreground/30'"
            />
          </span>
          <span v-if="playing" class="ml-auto text-[10px] text-muted-foreground">{{ mates }} teammate{{ mates === 1 ? '' : 's' }} nearby</span>
        </div>

        <div v-if="roster.length" class="mt-2 border-t pt-2">
          <p class="mb-1 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            <Users class="h-3 w-3" /> Raiders · {{ roster.length }}
          </p>
          <ul class="space-y-0.5">
            <li v-for="(p, i) in roster" :key="p.id" class="flex items-center gap-2 rounded px-1 py-0.5 text-xs" :class="p.id === String(user?.id) && 'bg-primary/10'">
              <span class="w-4 flex-none text-center">{{ MEDALS[i] ?? '' }}</span>
              <span class="min-w-0 flex-1 truncate" :class="p.id === String(user?.id) ? 'font-semibold' : 'text-foreground/80'">
                {{ p.name }}<span v-if="p.id === String(user?.id)" class="text-muted-foreground"> (you)</span>
              </span>
              <span class="w-14 flex-none text-right font-medium tabular-nums text-primary">{{ p.kills }} kills</span>
            </li>
          </ul>
        </div>
      </template>

      <div v-if="state.log?.length" class="mt-2 space-y-px rounded-lg bg-background/50 p-2 text-[11px] leading-snug text-muted-foreground">
        <p v-for="(line, i) in state.log" :key="i" class="truncate" :class="i === state.log.length - 1 && 'text-foreground'">{{ line }}</p>
      </div>
    </div>
  </div>
</template>
