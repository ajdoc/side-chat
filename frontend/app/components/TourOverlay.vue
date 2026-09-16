<script setup lang="ts">
import { X } from 'lucide-vue-next'
import { useEventListener, useResizeObserver } from '@vueuse/core'
import { Button } from '~/components/ui/button'
import { inflate, placePopover, unionRect, type Rect, type Side } from '~/lib/tour'
import { tourTarget, tourTargets } from '~/composables/useTour'

/**
 * The tour's chrome: a hole cut in a dimmed screen, and a bubble beside it.
 *
 * The dimming is four `box-shadow` spread rings around a transparent box rather than an SVG
 * mask or four positioned panels — one element, one paint, and the spotlight can be animated
 * between steps by moving that box. `pointer-events-none` on the ring is deliberate: the app
 * underneath stays clickable, so a step that points at the composer can be *tried* while it's
 * being explained instead of only read about.
 */
const { step, index, steps, next, back, end } = useTour()

const PADDING = 6
/**
 * A first guess only, used for the frame before the bubble exists to be measured.
 *
 * Placement has to know how big the bubble is, and a *constant* is the wrong answer: bodies
 * differ in length, so a step with three sentences was laid out as though it were 176px tall
 * and hung off the bottom of the screen with its Next button out of reach. So the real box is
 * measured once it's rendered and placement runs again — see `popover` and the observer below.
 */
const GUESS = { width: 320, height: 176 }

const rect = ref<Rect | null>(null)
const place = ref<{ x: number; y: number; side: Side }>({ x: 0, y: 0, side: 'bottom' })
const popover = ref<HTMLElement | null>(null)
const size = ref({ ...GUESS })

function boxOf(el: Element): Rect {
  const b = el.getBoundingClientRect()
  return { x: b.x, y: b.y, width: b.width, height: b.height }
}

function measure() {
  const s = step.value
  const el = s ? tourTarget(s.target) : null
  if (!s || !el) {
    // The target vanished mid-tour — a route change, a closed drawer. Ending beats pointing
    // at a stale rectangle the user can no longer see.
    if (step.value) end()
    return
  }
  // A step about a *set* lights the lot of them as one rectangle. See TourStep.spotlight.
  const box = s.spotlight === 'all'
    ? unionRect(tourTargets(s.target).map(boxOf)) ?? boxOf(el)
    : boxOf(el)
  const r = inflate(box, PADDING)
  rect.value = r
  const viewport = { width: window.innerWidth, height: window.innerHeight }
  // Never ask for more room than there is: on a short window the bubble caps out and its body
  // scrolls (see the template), so the height placement works with is the capped one.
  const height = Math.min(size.value.height, viewport.height - PADDING * 4)
  place.value = placePopover(r, { width: size.value.width, height }, viewport, s.side)
}

watch(step, async (s) => {
  if (!s) { rect.value = null; return }
  const el = tourTarget(s.target)
  // A target below the fold would otherwise be spotlighted off-screen.
  el?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  await nextTick()
  measure()
}, { immediate: true })

// The bubble's own size, as rendered. It changes with every step — a long body is a taller box
// — and placement is wrong until it's known, so re-place whenever it moves.
useResizeObserver(popover, ([entry]) => {
  if (!entry) return
  const box = entry.target.getBoundingClientRect()
  if (Math.abs(box.width - size.value.width) < 1 && Math.abs(box.height - size.value.height) < 1) return
  size.value = { width: box.width, height: box.height }
  measure()
})

// The shell resizes (split view, drawer, a rotated phone) while the tour is up.
useEventListener(window, 'resize', measure)
useEventListener(window, 'scroll', measure, true)
useEventListener(window, 'keydown', (e: KeyboardEvent) => {
  if (e.key === 'Escape') end()
  else if (e.key === 'ArrowRight' || e.key === 'Enter') next()
  else if (e.key === 'ArrowLeft') back()
})

const last = computed(() => index.value === steps.value.length - 1)
</script>

<template>
  <!-- z-[60]: above dialogs and menus (50), because the tour explains them. -->
  <div v-if="step && rect" class="pointer-events-none fixed inset-0 z-[60]">
    <div
      class="absolute rounded-lg ring-2 ring-primary transition-all duration-200"
      :style="{
        left: `${rect.x}px`,
        top: `${rect.y}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        boxShadow: '0 0 0 9999px rgba(0,0,0,0.55)',
      }"
    />

    <!--
      Capped, and a column, so that a long step can never strand its own buttons.

      The body is the part that scrolls; the title and the Back/Next row are pinned. A tour you
      cannot advance is worse than one you cannot finish reading, and on a short window — a
      phone in landscape, a small desktop pane — an uncapped bubble ran straight off the bottom.
    -->
    <div
      ref="popover"
      class="pointer-events-auto absolute flex w-80 max-w-[calc(100vw-1.5rem)] flex-col rounded-xl border bg-popover p-4 text-popover-foreground shadow-xl transition-all duration-200"
      :style="{ left: `${place.x}px`, top: `${place.y}px`, maxHeight: 'calc(100vh - 1.5rem)' }"
      role="dialog"
      aria-modal="false"
      :aria-label="step.title"
    >
      <button
        type="button"
        class="absolute right-2 top-2 rounded-md p-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
        aria-label="End tour"
        @click="end"
      >
        <X class="h-4 w-4" />
      </button>

      <p class="shrink-0 pr-6 text-sm font-semibold">{{ step.title }}</p>
      <p class="mt-1.5 min-h-0 overflow-y-auto text-sm text-muted-foreground">{{ step.body }}</p>

      <div class="mt-4 flex shrink-0 items-center gap-2">
        <span class="text-xs text-muted-foreground">{{ index + 1 }} of {{ steps.length }}</span>
        <div class="ml-auto flex gap-2">
          <Button v-if="index > 0" variant="ghost" size="sm" @click="back">Back</Button>
          <Button size="sm" @click="next">{{ last ? 'Done' : 'Next' }}</Button>
        </div>
      </div>
    </div>
  </div>
</template>
