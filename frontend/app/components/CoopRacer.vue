<script setup lang="ts">
import { Flag, Gauge, LogOut, Timer, Trophy, Users } from 'lucide-vue-next'
import type { RaceGhostMsg, RacingPlayer, RacingState, Widget } from '~/types'
import { RaceEngine, type Ghost, makeTrack } from '~/lib/raceEngine'
import { Button } from '~/components/ui/button'

/**
 * Side Grand Prix — a co-op top-down racer card, the racing sibling of {@link CoopShooter}.
 *
 * The game itself is a client-side canvas racer ({@link RaceEngine}); this component is the
 * glue. It:
 *   - drives the render/update loop and keyboard input,
 *   - whispers this car's pose over the channel's Reverb stream (~12Hz, peer-to-peer, never
 *     touching Laravel — the same trick as typing indicators and the shooter's ghosts) so
 *     rivals appear as live ghost cars in everyone's view,
 *   - and folds the *shared* outcomes into the widget's persisted state via actions: `join`
 *     when you take the wheel, `lap` with each lap time, `finish` when you take the flag.
 *
 * The track is built deterministically from the shared `seed`, so the whole channel races
 * the same circuit; each car is simulated locally (there's no game server), but rivals are
 * shown as *real* ghosts from their whispered positions.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()
const { user } = useAuth()
const echo: any = useNuxtApp().$echo

const state = computed(() => props.widget.state as RacingState)
const isRacing = computed(() => state.value.status === 'racing')
const isFinished = computed(() => state.value.status === 'finished')
const totalLaps = computed(() => state.value.laps || 3)

const roster = computed(() =>
  Object.entries(state.value.players ?? {})
    .map(([id, p]) => ({ id, ...(p as RacingPlayer) }))
    .sort((a, b) => {
      // Finishers first, in finishing order; then whoever's furthest / fastest.
      if (a.finished !== b.finished) return a.finished ? -1 : 1
      if (a.finished && b.finished) return (a.place ?? 99) - (b.place ?? 99)
      if (a.lapsDone !== b.lapsDone) return b.lapsDone - a.lapsDone
      return (a.bestLap ?? Infinity) - (b.bestLap ?? Infinity)
    }),
)
const MEDALS = ['🥇', '🥈', '🥉']
const channelName = computed(() => `channel.${props.widget.channel_id}`)

// --- live game state (this client) ---
const canvas = ref<HTMLCanvasElement | null>(null)
const playing = ref(false)
const localLap = ref(0)
const done = ref(false)
const speed = ref(0)
const lapClock = ref(0)
const bestLap = ref<number | null>(null)
const RES_W = 480
const RES_H = 320

let engine: RaceEngine | null = null
let raf = 0
let lastFrame = 0
let raceStart = 0
let lapStart = 0
let posTimer: ReturnType<typeof setInterval> | null = null
const input = { throttle: 0, steer: 0 }

// rival ghosts, keyed by user id, with a last-heard stamp for expiry (non-reactive:
// only the render loop reads them)
const ghosts = new Map<number, Ghost & { at: number }>()
const rivals = ref(0)

function take() {
  if (!isRacing.value) { action(props.widget.id, 'reset'); return }
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

  engine = new RaceEngine(ctx, RES_W, RES_H, makeTrack(state.value.seed || 1))
  localLap.value = 0
  done.value = false
  bestLap.value = null

  action(props.widget.id, 'join')
  subscribeGhosts()

  window.addEventListener('keydown', onKey)
  window.addEventListener('keyup', onKey)

  posTimer = setInterval(whisperPosition, 80)
  raceStart = lapStart = performance.now()
  lastFrame = performance.now()
  raf = requestAnimationFrame(frame)
}

function frame(now: number) {
  if (!engine) return
  const dt = Math.min(0.05, (now - lastFrame) / 1000 || 0)
  lastFrame = now

  const tick = engine.update(dt, done.value ? { throttle: 0, steer: 0 } : input)
  if (tick.lapCompleted && !done.value) onLap(now)

  pruneGhosts(now)
  engine.render([...ghosts.values()], now)

  speed.value = Math.round(engine.speedFrac() * 100)
  lapClock.value = done.value ? lapClock.value : now - lapStart
  raf = requestAnimationFrame(frame)
}

function onLap(now: number) {
  const ms = Math.round(now - lapStart)
  lapStart = now
  localLap.value++
  bestLap.value = bestLap.value == null ? ms : Math.min(bestLap.value, ms)
  action(props.widget.id, 'lap', { ms })

  if (localLap.value >= totalLaps.value) {
    done.value = true
    action(props.widget.id, 'finish', { ms: Math.round(now - raceStart) })
  }
}

// --- input ---
function onKey(e: KeyboardEvent) {
  const down = e.type === 'keydown'
  switch (e.key) {
    case 'w': case 'W': case 'ArrowUp': input.throttle = down ? 1 : 0; break
    case 's': case 'S': case 'ArrowDown': input.throttle = down ? -1 : 0; break
    case 'a': case 'A': case 'ArrowLeft': input.steer = down ? -1 : 0; break
    case 'd': case 'D': case 'ArrowRight': input.steer = down ? 1 : 0; break
    default: return
  }
  if (playing.value) e.preventDefault()
}

// --- networking: rival ghost cars over whispers ---
function subscribeGhosts() {
  echo?.private(channelName.value).listenForWhisper('race-pos', (m: RaceGhostMsg) => {
    if (!user.value || m.id === user.value.id) return
    ghosts.set(m.id, { id: m.id, name: m.name, x: m.x, y: m.y, a: m.a, lap: m.lap, at: performance.now() })
  })
}
function whisperPosition() {
  if (!playing.value || !user.value || !engine) return
  const p = engine.player
  echo?.private(channelName.value).whisper('race-pos', {
    id: user.value.id,
    name: user.value.name,
    x: Math.round(p.x * 100) / 100,
    y: Math.round(p.y * 100) / 100,
    a: Math.round(p.a * 100) / 100,
    lap: localLap.value,
  })
}
function pruneGhosts(now: number) {
  for (const [id, g] of ghosts) if (now - g.at > 2000) ghosts.delete(id)
  rivals.value = ghosts.size
}

function leave() {
  stopGame()
  playing.value = false
}

function stopGame() {
  if (raf) cancelAnimationFrame(raf)
  raf = 0
  if (posTimer) clearInterval(posTimer)
  posTimer = null
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('keyup', onKey)
  echo?.private(channelName.value).stopListeningForWhisper('race-pos')
  ghosts.clear()
  engine = null
  input.throttle = input.steer = 0
}

const raceAgain = () => action(props.widget.id, 'reset')

function fmt(ms: number | null): string {
  if (ms == null) return '—'
  const s = ms / 1000
  return s >= 60 ? `${Math.floor(s / 60)}:${(s % 60).toFixed(2).padStart(5, '0')}` : `${s.toFixed(2)}s`
}
function ordinal(place: number | null): string {
  if (place == null) return ''
  return MEDALS[place - 1] ?? `P${place}`
}

// The race was flagged over while we were driving — drop out to the podium.
watch(isFinished, (fin) => { if (fin && playing.value) leave() })

onBeforeUnmount(stopGame)
</script>

<template>
  <div class="mt-1.5 w-full max-w-md overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm">
    <!-- Header -->
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Flag class="h-3.5 w-3.5" /> Side Grand Prix
      <span v-if="isRacing || isFinished" class="ml-auto rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary">{{ totalLaps }} laps</span>
    </div>

    <div class="p-3">
      <!-- The circuit -->
      <div v-show="playing" class="relative overflow-hidden rounded-lg bg-[#14351f]">
        <canvas
          ref="canvas"
          class="block w-full select-none"
          style="aspect-ratio: 3 / 2; image-rendering: auto;"
        />
        <!-- HUD overlay -->
        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between p-2 text-[11px] font-semibold text-white/90">
          <span class="rounded bg-black/40 px-1.5 py-0.5">Lap {{ Math.min(localLap + 1, totalLaps) }}/{{ totalLaps }}</span>
          <span class="flex items-center gap-1 rounded bg-black/40 px-1.5 py-0.5 tabular-nums">
            <Timer class="h-3 w-3" /> {{ fmt(lapClock) }}
          </span>
        </div>
        <div class="pointer-events-none absolute left-2 top-8 flex flex-col gap-1 text-[10px] font-medium text-white/85">
          <span v-if="bestLap != null" class="rounded bg-black/40 px-1.5 py-0.5 tabular-nums">Best {{ fmt(bestLap) }}</span>
        </div>
        <!-- Speedo + leave -->
        <div class="absolute bottom-0 left-0 right-0 flex items-center justify-between p-2">
          <div class="flex items-center gap-1.5">
            <div class="h-2 w-24 overflow-hidden rounded-full bg-black/50">
              <div class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-red-500 transition-[width] duration-100" :style="{ width: `${speed}%` }" />
            </div>
            <Gauge class="h-3 w-3 text-white/70" />
          </div>
          <button class="pointer-events-auto flex items-center gap-1 rounded bg-black/50 px-2 py-1 text-[11px] font-medium text-white/90 hover:bg-black/70" @click="leave">
            <LogOut class="h-3 w-3" /> Leave
          </button>
        </div>
        <!-- Prompts / finish -->
        <div v-if="!done && localLap === 0 && lapClock < 2500" class="pointer-events-none absolute inset-0 grid place-items-center">
          <p class="rounded-md bg-black/60 px-3 py-1.5 text-xs font-medium text-white">WASD / arrows to drive · stay on the tarmac</p>
        </div>
        <div v-if="done" class="pointer-events-none absolute inset-0 grid place-items-center bg-black/40">
          <p class="flex items-center gap-1.5 rounded-md bg-black/70 px-3 py-1.5 text-sm font-semibold text-emerald-300">
            <Flag class="h-4 w-4" /> Finished {{ ordinal(state.players?.[String(user?.id)]?.place ?? null) }} — best {{ fmt(bestLap) }}
          </p>
        </div>
      </div>

      <!-- Lobby / race-over (when not driving) -->
      <div v-if="!playing">
        <div v-if="isFinished" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-center">
          <p class="flex items-center justify-center gap-1.5 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
            <Trophy class="h-4 w-4" /> Race over
          </p>
          <p v-if="roster[0]" class="mt-0.5 text-xs text-muted-foreground">🥇 {{ roster[0].name }} took the win</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="raceAgain"><Flag class="h-3.5 w-3.5" /> Rematch</Button>
        </div>

        <div v-else-if="isRacing" class="rounded-lg border bg-background/50 p-3 text-center">
          <p class="text-sm font-medium">The lights are green 🏁</p>
          <p class="mt-0.5 text-xs text-muted-foreground">Same {{ totalLaps }}-lap circuit as the channel — chase the fastest lap.</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="take"><Gauge class="h-3.5 w-3.5" /> Take the wheel</Button>
        </div>

        <div v-else class="rounded-lg border bg-background/50 p-3 text-center">
          <p class="text-sm text-muted-foreground">No race running.</p>
          <Button size="sm" class="mt-2 gap-1.5" @click="raceAgain"><Flag class="h-3.5 w-3.5" /> Start a race</Button>
          <p class="mt-2 text-[10px] text-muted-foreground">or type <code class="rounded bg-muted px-1">r!race</code></p>
        </div>
      </div>

      <!-- Leaderboard + feed (always shown for onlookers and downtime) -->
      <template v-if="isRacing || isFinished">
        <div v-if="roster.length" class="mt-3 border-t pt-2">
          <div class="mb-1 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            <Users class="h-3 w-3" /> Grid · {{ roster.length }}
            <span v-if="playing" class="ml-auto normal-case">{{ rivals }} rival{{ rivals === 1 ? '' : 's' }} nearby</span>
          </div>
          <ul class="space-y-0.5">
            <li v-for="(p, i) in roster" :key="p.id" class="flex items-center gap-2 rounded px-1 py-0.5 text-xs" :class="p.id === String(user?.id) && 'bg-primary/10'">
              <span class="w-4 flex-none text-center" :class="!p.finished && 'text-muted-foreground'">{{ p.finished ? (MEDALS[(p.place ?? 99) - 1] ?? `P${p.place}`) : i + 1 }}</span>
              <span class="min-w-0 flex-1 truncate" :class="p.id === String(user?.id) ? 'font-semibold' : 'text-foreground/80'">
                {{ p.name }}<span v-if="p.id === String(user?.id)" class="text-muted-foreground"> (you)</span>
              </span>
              <span v-if="!p.finished" class="flex-none text-[10px] text-muted-foreground">L{{ p.lapsDone }}</span>
              <span class="w-16 flex-none text-right font-medium tabular-nums text-primary">{{ fmt(p.bestLap) }}</span>
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
