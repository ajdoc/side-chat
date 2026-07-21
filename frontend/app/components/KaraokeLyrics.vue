<script setup lang="ts">
import { Mic2 } from 'lucide-vue-next'
import { activeLineIndex, type LyricLine } from '~/lib/lrc'

/**
 * The scrolling lyric list — the same component for the in-card pane and the full-screen
 * stage, differing only in type scale and how many neighbouring lines stay legible.
 *
 * It's a pure view over two inputs: the parsed lines and the room's current position (the
 * *shared* clock the music widget broadcasts, not this viewer's own audio element). That's
 * what makes the sing-along work — everyone's highlight lands on the same word at the same
 * moment even though each browser is playing its own copy of the track.
 *
 * Auto-scroll keeps the active line centred, but yields to the reader: touching the scroll
 * wheel to read ahead suspends it, and it resumes once they've stopped for a few seconds.
 */
const props = withDefaults(defineProps<{
  lines: LyricLine[]
  /** False when the source had no timestamps — the list renders, nothing highlights. */
  synced: boolean
  loading?: boolean
  missing?: boolean
  instrumental?: boolean
  /** Seconds into the track, from the karaoke clock (already offset-corrected). */
  position: number
  /** The viewer's manual timing correction, in seconds — shown so they can undo it. */
  offset?: number
  variant?: 'pane' | 'stage'
}>(), { variant: 'pane', offset: 0 })

const emit = defineEmits<{ seek: [seconds: number], nudge: [delta: number], resetOffset: [] }>()

const stage = computed(() => props.variant === 'stage')

const active = computed(() =>
  props.synced ? activeLineIndex(props.lines, props.position) : -1,
)

/**
 * How far through the active line we are, 0–1. Drives the thin underline that gives the
 * held highlight some motion. It's interpolated across the gap to the next stamp — LRCLIB
 * has no per-word timings, so this tracks the *line*, never individual syllables.
 */
const lineProgress = computed(() => {
  const i = active.value
  if (i < 0) return 0
  const start = props.lines[i]!.time
  const next = props.lines[i + 1]?.time ?? start + 6
  const span = next - start
  return span > 0 ? Math.min(1, Math.max(0, (props.position - start) / span)) : 0
})

const scroller = ref<HTMLElement | null>(null)
const lineEls = ref<HTMLElement[]>([])

// Manual scrolling wins until the reader settles again.
const paused = ref(false)
let resumeTimer: ReturnType<typeof setTimeout> | null = null
// Our own smooth-scrolling fires `scroll` events too — ignore those, or auto-scroll would
// immediately suspend itself.
let selfScrolling = 0

function onScroll() {
  if (Date.now() < selfScrolling) return
  paused.value = true
  if (resumeTimer) clearTimeout(resumeTimer)
  resumeTimer = setTimeout(() => { paused.value = false; scrollToActive() }, 5000)
}

function scrollToActive() {
  if (paused.value || active.value < 0) return
  const el = lineEls.value[active.value]
  const box = scroller.value
  if (!el || !box) return
  // Centre the line in the viewport rather than scrollIntoView, which would scroll the
  // whole page (the card lives inside the message list) instead of just this box.
  const top = el.offsetTop - box.clientHeight / 2 + el.clientHeight / 2
  selfScrolling = Date.now() + 800
  box.scrollTo({ top: Math.max(0, top), behavior: 'smooth' })
}

watch(active, scrollToActive)
watch(() => props.lines, () => { lineEls.value = []; nextTick(scrollToActive) })
onMounted(() => nextTick(scrollToActive))
onBeforeUnmount(() => { if (resumeTimer) clearTimeout(resumeTimer) })

function setLineEl(el: any, i: number) {
  if (el) lineEls.value[i] = el as HTMLElement
}

/** Clicking a line jumps the *room* there — the classic "start us from the chorus". */
function onLineClick(line: LyricLine) {
  if (props.synced && line.time >= 0) emit('seek', line.time)
}

/** "+0.4s" / "−1.2s" / "in sync" — signed, because which way to nudge is the whole point. */
const offsetLabel = computed(() => {
  const o = props.offset
  if (Math.abs(o) < 0.005) return 'in sync'
  return `${o > 0 ? '+' : '−'}${Math.abs(o).toFixed(1)}s`
})
</script>

<template>
  <div :class="stage ? 'flex h-full flex-col' : ''">
    <!-- `relative` matters: scrollToActive reads each line's offsetTop, which is measured
         against the nearest positioned ancestor — this box has to be it.
         `min-h-0` lets it shrink so the sync bar below stays visible on the stage. -->
    <div
      ref="scroller"
      class="relative overflow-y-auto overscroll-contain"
      :class="stage ? 'min-h-0 flex-1 px-4 py-[35vh] text-center' : 'max-h-56 px-1 py-2'"
      @scroll.passive="onScroll"
    >
      <p v-if="loading" class="py-4 text-center text-xs text-muted-foreground">
        Looking for lyrics…
      </p>

      <p v-else-if="instrumental && !lines.length" :class="stage ? 'text-2xl text-white/60' : 'py-4 text-center text-xs text-muted-foreground'">
        🎹 Instrumental — no words to sing.
      </p>

      <p v-else-if="missing || !lines.length" :class="stage ? 'text-xl text-white/60' : 'py-4 text-center text-xs text-muted-foreground'">
        No lyrics found for this track.
      </p>

      <template v-else>
        <p
          v-if="!synced"
          class="mb-2 text-center text-[10px] uppercase tracking-wide"
          :class="stage ? 'text-white/40' : 'text-muted-foreground'"
        >
          Unsynced lyrics — follow along yourself
        </p>

        <component
          :is="synced ? 'button' : 'p'"
          v-for="(line, i) in lines"
          :key="`${i}-${line.time}`"
          :ref="el => setLineEl(el, i)"
          class="block w-full transition-all duration-300"
          :class="[
            stage ? 'px-2 py-2 text-2xl font-semibold leading-snug sm:text-3xl' : 'rounded px-2 py-1 text-left text-xs leading-relaxed',
            synced && 'cursor-pointer',
            i === active
              ? (stage ? 'scale-[1.03] text-white drop-shadow' : 'font-semibold text-foreground')
              : synced && i < active
                ? (stage ? 'text-white/25' : 'text-muted-foreground/50')
                : (stage ? 'text-white/45' : 'text-muted-foreground'),
            synced && (stage ? 'hover:text-white/80' : 'hover:bg-muted'),
          ]"
          :title="synced ? 'Jump the room here' : undefined"
          @click="onLineClick(line)"
        >
          <!-- A stamped-but-empty line is an instrumental gap; show a beat marker so the
               highlight has somewhere to sit instead of vanishing. -->
          <span v-if="line.text">{{ line.text }}</span>
          <Mic2 v-else class="mx-auto opacity-40" :class="stage ? 'h-6 w-6' : 'h-3 w-3'" />

          <!-- No CSS transition on the fill: the position now updates every frame, so
               easing the width would only add lag on top of an already-smooth value. -->
          <span
            v-if="i === active && synced"
            class="mt-1 block h-0.5 rounded-full"
            :class="stage ? 'mx-auto bg-white/70' : 'bg-primary'"
            :style="{ width: `${Math.round(lineProgress * 100)}%` }"
          />
        </component>
      </template>
    </div>

    <!-- Sync nudge. The stamps come from one recording and the queue is playing another, so
         a constant offset is usually all that stands between "close" and "exact". -->
    <div
      v-if="synced && lines.length"
      class="flex flex-none items-center justify-center gap-1 border-t px-2 py-1 text-[10px]"
      :class="stage
        ? 'relative z-10 border-white/10 bg-neutral-950/80 text-white/50'
        : 'text-muted-foreground'"
    >
      <span class="mr-0.5">Sync</span>
      <button
        class="rounded px-1.5 py-0.5 font-medium"
        :class="stage ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-muted hover:text-foreground'"
        title="Lyrics are early — hold them back"
        @click="emit('nudge', -0.2)"
      >
        −
      </button>
      <button
        class="min-w-[3.5rem] rounded px-1 py-0.5 tabular-nums"
        :class="[
          stage ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-muted hover:text-foreground',
          offset !== 0 && (stage ? 'text-white' : 'font-medium text-foreground'),
        ]"
        :title="offset !== 0 ? 'Reset to the original timing' : 'Nudge if the lyrics run ahead or behind'"
        @click="emit('resetOffset')"
      >
        {{ offsetLabel }}
      </button>
      <button
        class="rounded px-1.5 py-0.5 font-medium"
        :class="stage ? 'hover:bg-white/10 hover:text-white' : 'hover:bg-muted hover:text-foreground'"
        title="Lyrics are late — bring them forward"
        @click="emit('nudge', 0.2)"
      >
        +
      </button>
    </div>
  </div>
</template>
