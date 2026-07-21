<script setup lang="ts">
import { Brush, Eraser, Palette, Pencil, RotateCcw, SkipForward, Trash2, Trophy, Users } from 'lucide-vue-next'
import type { SkribblChatLine, SkribblDrawMsg, SkribblPlayer, SkribblState, Widget } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * Side Skribbl — draw and guess, the channel's turn-taking word game.
 *
 * The split here is the same one the co-op games use, for the same reason: whatever must be
 * shared *and* trustworthy lives on the server (see the SkribblWidget handler) — the word,
 * the clock, the judging of guesses, the scores — while the thing that's merely live goes
 * peer-to-peer over the channel's Reverb stream as whispers. A pen at 60fps has no business
 * in a database, and the picture is worthless the moment the turn ends.
 *
 * So this component:
 *   - draws on a canvas and whispers the drawer's strokes out in ~80ms batches, in 0..1
 *     coordinates so every screen redraws the same picture at its own size,
 *   - replays what it hears from the drawer, and asks for a re-send when it arrives mid-turn
 *     (whispers aren't retained — a late joiner would otherwise stare at a blank sheet),
 *   - sends guesses to the server, which is the only party that can judge them: a guesser's
 *     state never contains the word (see {@link SkribblState}),
 *   - and nudges the turn along when the shared clock runs out.
 */
const props = defineProps<{ widget: Widget }>()

const { action } = useWidgets()
const { user } = useAuth()
const echo: any = useNuxtApp().$echo

const state = computed(() => props.widget.state as SkribblState)
const status = computed(() => state.value.status)
const isDrawing = computed(() => status.value === 'drawing')
const isReveal = computed(() => status.value === 'reveal')
const isOver = computed(() => status.value === 'over')
const meId = computed(() => user.value?.id ?? 0)
const isDrawer = computed(() => state.value.drawerId === meId.value)
const joined = computed(() => !!state.value.players?.[String(meId.value)])
const canDraw = computed(() => isDrawer.value && isDrawing.value)
const hasGuessed = computed(() => (state.value.correct ?? []).includes(meId.value))
const channelName = computed(() => `channel.${props.widget.channel_id}`)

const scoreboard = computed(() =>
  Object.entries(state.value.players ?? {})
    .map(([id, p]) => ({ id: Number(id), ...(p as SkribblPlayer) }))
    .sort((a, b) => b.score - a.score),
)

// --- the shared clock ---------------------------------------------------------------
// endsAt / revealEndsAt are server epoch-ms; every client counts down against them rather
// than running its own timer, so nobody's turn is longer than anyone else's.
const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | null = null

const msLeft = computed(() => Math.max(0, (isReveal.value ? state.value.revealEndsAt : state.value.endsAt) - now.value))
const secsLeft = computed(() => Math.ceil(msLeft.value / 1000))
// Purely the width of the draining bar — it mirrors the handler's TURN_MS, and a drift
// between the two costs nothing worse than a bar that starts part-full.
const TURN_MS = 80_000
const turnFrac = computed(() => (isDrawing.value ? Math.max(0, Math.min(1, msLeft.value / TURN_MS)) : 0))

// The transition out of a turn is client-nudged: whoever's watching tells the server the
// clock is up. Everyone fires, the server takes the first and no-ops the rest (it re-checks
// the deadline and pins the call to a turn number) — that way an empty room isn't stuck, and
// a full one doesn't skip two turns. Once per turn per client is plenty.
const nudged = ref(0)
watch([msLeft, status], () => {
  if (msLeft.value > 0 || nudged.value === state.value.turn) return
  if (isDrawing.value) { nudged.value = state.value.turn; action(props.widget.id, 'timeup', { turn: state.value.turn }) }
  else if (isReveal.value) { nudged.value = state.value.turn; action(props.widget.id, 'next', { turn: state.value.turn }) }
})
watch(() => state.value.turn, () => { nudged.value = 0 })

// --- the sheet ----------------------------------------------------------------------
interface Stroke { s: number, c: string, w: number, p: number[] }

const RES_W = 640
const RES_H = 480
const PAPER = '#ffffff'
const COLORS = ['#111827', '#ef4444', '#f97316', '#eab308', '#22c55e', '#3b82f6', '#a855f7', '#ec4899', '#78350f', '#9ca3af']
const SIZES = [0.006, 0.014, 0.03]

const canvas = ref<HTMLCanvasElement | null>(null)
const color = ref(COLORS[0]!)
const size = ref(SIZES[1]!)
const erasing = ref(false)

// Non-reactive: only the redraw reads these, and a stroke can hold hundreds of points —
// making them reactive would cost a proxy per pen move for nothing.
let strokes: Stroke[] = []
let live: Stroke | null = null
let flushed = 0
let strokeSeq = 0
let flushTimer: ReturnType<typeof setInterval> | null = null
let lastAnswered = 0

function ctx2d() {
  return canvas.value?.getContext('2d') ?? null
}

function redraw() {
  const ctx = ctx2d()
  if (!ctx) return
  ctx.fillStyle = PAPER
  ctx.fillRect(0, 0, RES_W, RES_H)
  ctx.lineCap = ctx.lineJoin = 'round'
  for (const st of strokes) paint(ctx, st)
  if (live) paint(ctx, live)
}

function paint(ctx: CanvasRenderingContext2D, st: Stroke) {
  if (st.p.length < 2) return
  ctx.strokeStyle = st.c
  ctx.lineWidth = Math.max(1, st.w * RES_W)
  ctx.beginPath()
  ctx.moveTo(st.p[0]! * RES_W, st.p[1]! * RES_H)
  for (let i = 2; i < st.p.length; i += 2) ctx.lineTo(st.p[i]! * RES_W, st.p[i + 1]! * RES_H)
  // A tap with no travel should still leave a dot, not nothing.
  if (st.p.length === 2) ctx.lineTo(st.p[0]! * RES_W + 0.01, st.p[1]! * RES_H)
  ctx.stroke()
}

function clearSheet() {
  strokes = []
  live = null
  redraw()
}

// --- the pen (drawer only) -----------------------------------------------------------
function pointAt(e: PointerEvent): [number, number] {
  const rect = canvas.value!.getBoundingClientRect()
  return [
    Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)),
    Math.max(0, Math.min(1, (e.clientY - rect.top) / rect.height)),
  ]
}

function penDown(e: PointerEvent) {
  if (!canDraw.value) return
  canvas.value?.setPointerCapture(e.pointerId)
  const [x, y] = pointAt(e)
  live = { s: ++strokeSeq, c: erasing.value ? PAPER : color.value, w: erasing.value ? size.value * 2.5 : size.value, p: [x, y] }
  flushed = 0
  redraw()
}

function penMove(e: PointerEvent) {
  if (!live) return
  const [x, y] = pointAt(e)
  const n = live.p.length
  // Skip micro-jitter: it costs bandwidth and changes nothing on screen.
  if (n >= 2 && Math.hypot(x - live.p[n - 2]!, y - live.p[n - 1]!) < 0.004) return
  live.p.push(x, y)
  redraw()
}

function penUp() {
  if (!live) return
  flushStroke()
  strokes.push(live)
  live = null
  flushed = 0
  redraw()
}

/** Whisper whatever the pen has drawn since the last flush — the tail only, never the lot. */
function flushStroke() {
  if (!live || !canDraw.value) return
  const tail = live.p.slice(Math.max(0, flushed - 2))
  if (tail.length < 2) return
  flushed = live.p.length
  send({ by: meId.value, s: live.s, c: live.c, w: live.w, p: tail })
}

function undo() {
  strokes.pop()
  redraw()
  sendAll('undo')
}

function wipe() {
  clearSheet()
  echo?.private(channelName.value).whisper('skribbl-wipe', { by: meId.value })
}

// --- the wire ------------------------------------------------------------------------
function send(msg: SkribblDrawMsg) {
  echo?.private(channelName.value).whisper('skribbl-draw', msg)
}

/**
 * Re-send the whole sheet. Whispers are fire-and-forget with no history, so anyone who
 * opens the card mid-turn sees nothing until the drawer replays it — which is what an
 * `skribbl-ask` triggers. Split into chunks: one whisper carrying a long stroke would
 * sail past the socket's payload limit.
 */
function sendAll(reason: 'ask' | 'undo') {
  if (!canDraw.value) return
  if (reason === 'undo') echo?.private(channelName.value).whisper('skribbl-wipe', { by: meId.value })
  const CHUNK = 200
  for (const st of strokes) {
    for (let i = 0; i < st.p.length; i += CHUNK) {
      send({ by: meId.value, s: st.s, c: st.c, w: st.w, p: st.p.slice(i, i + CHUNK + 2) })
    }
  }
}

function listen() {
  const ch = echo?.private(channelName.value)
  if (!ch) return

  ch.listenForWhisper('skribbl-draw', (m: SkribblDrawMsg) => {
    // Only the pen holder's strokes land — a bored spectator can't scribble on the sheet.
    if (m.by === meId.value || m.by !== state.value.drawerId) return
    const existing = strokes.find(st => st.s === m.s)
    if (existing) existing.p.push(...m.p)
    else strokes.push({ s: m.s, c: m.c, w: m.w, p: [...m.p] })
    redraw()
  })

  ch.listenForWhisper('skribbl-wipe', (m: { by: number }) => {
    if (m.by === meId.value || m.by !== state.value.drawerId) return
    clearSheet()
  })

  // Someone just opened the card — replay the sheet for them, but no more than once every
  // couple of seconds however many of them arrive at once.
  ch.listenForWhisper('skribbl-ask', () => {
    if (!canDraw.value || Date.now() - lastAnswered < 2000) return
    lastAnswered = Date.now()
    sendAll('ask')
  })
}

function askForSheet() {
  if (!isDrawing.value || isDrawer.value) return
  echo?.private(channelName.value).whisper('skribbl-ask', { by: meId.value })
}

// --- guessing -------------------------------------------------------------------------
const guess = ref('')

function submitGuess() {
  const text = guess.value.trim()
  if (!text || !isDrawing.value || isDrawer.value || hasGuessed.value) return
  guess.value = ''
  action(props.widget.id, 'guess', { text })
}

const join = () => action(props.widget.id, 'join')
const start = () => action(props.widget.id, 'start')
const skip = () => action(props.widget.id, 'skip', { turn: state.value.turn })

// --- lifecycle -------------------------------------------------------------------------
// A new turn is a fresh sheet for everyone; the new drawer's first stroke starts from blank.
watch(() => state.value.turn, () => {
  clearSheet()
  guess.value = ''
  nextTick(askForSheet)
})

// The sheet only exists while a turn is on, so size it whenever it (re)appears rather than
// once at mount — at mount there's usually no canvas to size.
watch(canvas, (el) => {
  if (!el) return
  el.width = RES_W
  el.height = RES_H
  redraw()
})

watch(canDraw, (can) => {
  if (can && !flushTimer) flushTimer = setInterval(flushStroke, 80)
  if (!can && flushTimer) { clearInterval(flushTimer); flushTimer = null; live = null }
})

onMounted(() => {
  listen()
  clock = setInterval(() => { now.value = Date.now() }, 250)
  if (canDraw.value) flushTimer = setInterval(flushStroke, 80)
  askForSheet()
})

onBeforeUnmount(() => {
  if (clock) clearInterval(clock)
  if (flushTimer) clearInterval(flushTimer)
  const ch = echo?.private(channelName.value)
  ch?.stopListeningForWhisper('skribbl-draw')
  ch?.stopListeningForWhisper('skribbl-wipe')
  ch?.stopListeningForWhisper('skribbl-ask')
})

function lineClass(line: SkribblChatLine) {
  if (line.ok) return 'text-emerald-600 dark:text-emerald-400 font-medium'
  return line.close ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'
}
</script>

<template>
  <div class="mt-1.5 w-full max-w-md overflow-hidden rounded-xl border bg-gradient-to-b from-muted/50 to-muted/20 shadow-sm">
    <!-- Header -->
    <div class="flex items-center gap-1.5 border-b bg-background/40 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-primary">
      <Palette class="h-3.5 w-3.5" /> Side Skribbl
      <span v-if="isDrawing || isReveal" class="ml-auto rounded-full bg-primary/10 px-1.5 py-px text-[10px] normal-case text-primary">
        Turn {{ state.turn }}/{{ state.turns }}
      </span>
    </div>

    <div class="p-3">
      <!-- Lobby / game over -->
      <div v-if="!isDrawing && !isReveal" class="rounded-lg border bg-background/50 p-3 text-center">
        <template v-if="isOver">
          <p class="flex items-center justify-center gap-1.5 text-sm font-semibold text-emerald-600 dark:text-emerald-400">
            <Trophy class="h-4 w-4" /> Game over
          </p>
          <p v-if="scoreboard[0]" class="mt-0.5 text-xs text-muted-foreground">
            🏆 {{ scoreboard[0].name }} wins with {{ scoreboard[0].score }} points
          </p>
        </template>
        <template v-else>
          <p class="text-sm font-medium">Draw & guess 🎨</p>
          <p class="mt-0.5 text-xs text-muted-foreground">One player draws, everyone else races to guess the word.</p>
        </template>

        <div class="mt-2 flex items-center justify-center gap-2">
          <Button v-if="!joined" size="sm" variant="outline" class="gap-1.5" @click="join">
            <Users class="h-3.5 w-3.5" /> Join
          </Button>
          <Button size="sm" class="gap-1.5" @click="start">
            <Pencil class="h-3.5 w-3.5" /> {{ isOver ? 'Play again' : 'Start game' }}
          </Button>
        </div>
        <p class="mt-2 text-[10px] text-muted-foreground">or type <code class="rounded bg-muted px-1">s!play</code></p>
      </div>

      <!-- A turn in progress -->
      <template v-else>
        <!-- Word / mask + clock -->
        <div class="mb-2 flex items-center gap-2">
          <div class="min-w-0 flex-1">
            <p v-if="isDrawer && state.word" class="truncate text-sm font-semibold tracking-wide">
              You're drawing: <span class="text-primary">{{ state.word }}</span>
            </p>
            <p v-else-if="isReveal" class="truncate text-sm font-semibold">
              The word was <span class="text-primary">{{ state.word }}</span>
            </p>
            <p v-else class="truncate font-mono text-sm font-semibold tracking-[0.3em]">{{ state.mask }}</p>
            <p class="truncate text-[10px] text-muted-foreground">
              {{ isDrawer ? 'Everyone else sees blanks' : `${state.drawerName} is drawing` }}
            </p>
          </div>
          <span class="flex-none rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold tabular-nums text-primary">{{ secsLeft }}s</span>
        </div>
        <div class="mb-2 h-1 overflow-hidden rounded-full bg-muted">
          <div
            class="h-full rounded-full transition-[width] duration-200"
            :class="turnFrac < 0.25 ? 'bg-red-500' : 'bg-primary'"
            :style="{ width: `${turnFrac * 100}%` }"
          />
        </div>

        <!-- The sheet -->
        <div class="relative overflow-hidden rounded-lg border bg-white">
          <canvas
            ref="canvas"
            class="block w-full touch-none select-none"
            style="aspect-ratio: 4 / 3;"
            :class="canDraw ? 'cursor-crosshair' : 'cursor-default'"
            @pointerdown="penDown"
            @pointermove="penMove"
            @pointerup="penUp"
            @pointercancel="penUp"
            @pointerleave="penUp"
          />
          <div v-if="isReveal" class="absolute inset-0 grid place-items-center bg-black/50">
            <p class="rounded-md bg-black/70 px-3 py-1.5 text-sm font-semibold text-white">
              It was “{{ state.word }}” — next turn in {{ secsLeft }}s
            </p>
          </div>
        </div>

        <!-- Pen tools (drawer only) -->
        <div v-if="canDraw" class="mt-2 flex flex-wrap items-center gap-1.5">
          <button
            v-for="c in COLORS"
            :key="c"
            class="h-5 w-5 rounded-full border transition-transform"
            :class="!erasing && color === c ? 'scale-110 ring-2 ring-primary ring-offset-1 ring-offset-background' : ''"
            :style="{ backgroundColor: c }"
            :title="c"
            @click="color = c; erasing = false"
          />
          <span class="mx-0.5 h-4 w-px bg-border" />
          <button
            v-for="(s, i) in SIZES"
            :key="s"
            class="grid h-6 w-6 place-items-center rounded hover:bg-muted"
            :class="size === s && 'bg-muted'"
            :title="`Brush ${i + 1}`"
            @click="size = s"
          >
            <Brush class="text-foreground" :style="{ width: `${8 + i * 4}px`, height: `${8 + i * 4}px` }" />
          </button>
          <span class="mx-0.5 h-4 w-px bg-border" />
          <button class="grid h-6 w-6 place-items-center rounded hover:bg-muted" :class="erasing && 'bg-muted text-primary'" title="Eraser" @click="erasing = !erasing">
            <Eraser class="h-3.5 w-3.5" />
          </button>
          <button class="grid h-6 w-6 place-items-center rounded hover:bg-muted" title="Undo" @click="undo">
            <RotateCcw class="h-3.5 w-3.5" />
          </button>
          <button class="grid h-6 w-6 place-items-center rounded hover:bg-muted" title="Clear" @click="wipe">
            <Trash2 class="h-3.5 w-3.5" />
          </button>
          <button class="ml-auto flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground" title="Give up on this word" @click="skip">
            <SkipForward class="h-3 w-3" /> Skip
          </button>
        </div>

        <!-- Guess box (everyone else) -->
        <form v-else-if="isDrawing" class="mt-2 flex items-center gap-1.5" @submit.prevent="submitGuess">
          <input
            v-model="guess"
            :disabled="hasGuessed"
            :placeholder="hasGuessed ? 'You got it! Sit tight…' : 'Type your guess…'"
            maxlength="60"
            class="min-w-0 flex-1 rounded-md border bg-background px-2 py-1 text-sm outline-none placeholder:text-muted-foreground focus:ring-1 focus:ring-primary disabled:opacity-60"
          >
          <Button type="submit" size="sm" :disabled="hasGuessed || !guess.trim()">Guess</Button>
        </form>

        <!-- The guess feed -->
        <div v-if="state.chat?.length" class="mt-2 max-h-24 space-y-px overflow-y-auto rounded-lg bg-background/50 p-2 text-[11px] leading-snug">
          <p v-for="(line, i) in state.chat" :key="i" class="truncate" :class="lineClass(line)">
            <span class="font-medium text-foreground/80">{{ line.name }}</span>
            {{ line.ok ? '' : ':' }} {{ line.text }}<span v-if="line.close"> — so close!</span>
          </p>
        </div>
      </template>

      <!-- Scoreboard -->
      <div v-if="scoreboard.length" class="mt-3 border-t pt-2">
        <div class="mb-1 flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          <Users class="h-3 w-3" /> Table · {{ scoreboard.length }}
        </div>
        <ul class="space-y-0.5">
          <li
            v-for="(p, i) in scoreboard"
            :key="p.id"
            class="flex items-center gap-2 rounded px-1 py-0.5 text-xs"
            :class="p.id === meId && 'bg-primary/10'"
          >
            <span class="w-4 flex-none text-center text-muted-foreground">{{ i + 1 }}</span>
            <span class="min-w-0 flex-1 truncate" :class="p.id === meId ? 'font-semibold' : 'text-foreground/80'">
              {{ p.name }}<span v-if="p.id === meId" class="text-muted-foreground"> (you)</span>
            </span>
            <span v-if="p.id === state.drawerId" class="flex-none" title="Drawing">✏️</span>
            <span v-else-if="(state.correct ?? []).includes(p.id)" class="flex-none text-emerald-500" title="Guessed it">✓</span>
            <span class="w-10 flex-none text-right font-medium tabular-nums text-primary">{{ p.score }}</span>
          </li>
        </ul>
      </div>

      <div v-if="state.log?.length" class="mt-2 space-y-px rounded-lg bg-background/50 p-2 text-[11px] leading-snug text-muted-foreground">
        <p v-for="(line, i) in state.log" :key="i" class="truncate" :class="i === state.log.length - 1 && 'text-foreground'">{{ line }}</p>
      </div>
    </div>
  </div>
</template>
