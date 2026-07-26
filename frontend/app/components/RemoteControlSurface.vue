<script setup lang="ts">
import { MousePointer2, Unplug } from 'lucide-vue-next'

/**
 * The layer you actually drive someone's screen through.
 *
 * Sits over the stage video while you hold control, swallows every pointer and key event that
 * lands on it, and forwards each one as a *fraction* of the shared picture. Fractions, never
 * pixels — see `toFraction`. Renders nothing at all when you don't hold control, so the stage
 * behaves exactly as it always did the rest of the time.
 */
const props = defineProps<{
  /** Whose screen this is. Control is only captured when it's the peer we're driving. */
  peerId: number
  /** The stream's intrinsic size, from VoiceVideo's `dimensions` event. */
  videoWidth: number
  videoHeight: number
}>()

const { controlling, releaseControl, sendInput } = useRemoteControl()

const active = computed(() => controlling.value === props.peerId)
const surface = ref<HTMLElement | null>(null)

/**
 * A point in the element → a point on the sharer's screen, as a 0..1 fraction.
 *
 * The arithmetic in the middle is the letterbox. The video is drawn `object-contain`, so the
 * picture is centred inside the element with bars on whichever axis has slack, and the naive
 * `offsetX / clientWidth` puts the cursor steadily further off the wider the bars get. So work
 * out the drawn rectangle from the intrinsic aspect first, then measure within *that*.
 *
 * Returns null for a point in the bars — there's nothing there to click on.
 */
function toFraction(e: PointerEvent | WheelEvent) {
  const el = surface.value
  if (!el || !props.videoWidth || !props.videoHeight) return null

  const box = el.getBoundingClientRect()
  const scale = Math.min(box.width / props.videoWidth, box.height / props.videoHeight)
  const drawnW = props.videoWidth * scale
  const drawnH = props.videoHeight * scale

  const x = (e.clientX - box.left - (box.width - drawnW) / 2) / drawnW
  const y = (e.clientY - box.top - (box.height - drawnH) / 2) / drawnH

  if (x < 0 || x > 1 || y < 0 || y > 1) return null
  return { x, y }
}

function onPointerMove(e: PointerEvent) {
  const p = toFraction(e)
  if (p) sendInput({ t: 'move', x: p.x, y: p.y })
}

function onPointerDown(e: PointerEvent) {
  const p = toFraction(e)
  if (!p) return
  // Capture the pointer so a drag that leaves the video still delivers its `up` here. Without
  // it, dragging a window off the edge of the stage strands the button down on their machine.
  surface.value?.setPointerCapture(e.pointerId)
  // Focus, so keystrokes start arriving without a separate click.
  surface.value?.focus()
  sendInput({ t: 'down', x: p.x, y: p.y, b: e.button })
}

function onPointerUp(e: PointerEvent) {
  const p = toFraction(e)
  surface.value?.releasePointerCapture?.(e.pointerId)
  // Unlike a move, an `up` is sent even from the bars: the button has to come back up
  // *somewhere*, and the last known position is closer to right than never releasing at all.
  sendInput({ t: 'up', x: p?.x ?? 0, y: p?.y ?? 0, b: e.button })
}

function onWheel(e: WheelEvent) {
  e.preventDefault()
  sendInput({ t: 'wheel', dx: e.deltaX, dy: e.deltaY })
}

function onKey(e: KeyboardEvent, t: 'key-down' | 'key-up') {
  // Escape is ours, not theirs: it's the way out of a session, and forwarding it would mean the
  // only obvious "get me out of here" key instead landed on the machine you're stuck driving.
  if (e.code === 'Escape') {
    if (t === 'key-down') releaseControl()
    return
  }
  e.preventDefault()
  sendInput({ t, code: e.code })
}

/**
 * Hand control back if the tab goes away mid-session.
 *
 * Alt-tabbing out never delivers the `keyup` for the keys that did it, and a browser won't send
 * further keystrokes to a hidden tab anyway — so the session is already over in practice. Ending
 * it explicitly means the sharer's machine has its modifiers lifted (see releaseAll in the
 * desktop shell) instead of being left with a stuck Alt.
 */
function onVisibility() {
  if (document.hidden && active.value) releaseControl()
}

onMounted(() => document.addEventListener('visibilitychange', onVisibility))
onUnmounted(() => {
  document.removeEventListener('visibilitychange', onVisibility)
  if (active.value) releaseControl()
})

// Take focus the moment control lands, so the first keystroke isn't swallowed.
watch(active, is => is && nextTick(() => surface.value?.focus()))
</script>

<template>
  <div
    v-if="active"
    ref="surface"
    class="absolute inset-0 z-20 cursor-none outline-none"
    tabindex="0"
    role="application"
    aria-label="Remote control surface — press Escape to hand control back"
    @pointermove="onPointerMove"
    @pointerdown.prevent="onPointerDown"
    @pointerup="onPointerUp"
    @wheel.prevent="onWheel"
    @contextmenu.prevent
    @keydown="onKey($event, 'key-down')"
    @keyup="onKey($event, 'key-up')"
  >
    <!-- A live border, so it's never ambiguous that your input is landing on someone else's
         machine rather than in your own window. -->
    <div class="pointer-events-none absolute inset-0 rounded-lg ring-2 ring-inset ring-primary" />

    <div class="pointer-events-none absolute left-2 top-2 flex items-center gap-2 rounded-md bg-primary px-2 py-1 text-[11px] font-medium text-primary-foreground shadow-md">
      <MousePointer2 class="h-3.5 w-3.5" />
      You have control
      <span class="opacity-70">· Esc to release</span>
    </div>

    <button
      type="button"
      class="absolute right-2 top-2 flex items-center gap-1.5 rounded-md bg-background/90 px-2 py-1 text-[11px] font-medium shadow-md backdrop-blur transition hover:bg-background"
      @pointerdown.stop.prevent
      @click.stop="releaseControl"
    >
      <Unplug class="h-3.5 w-3.5" /> Release
    </button>
  </div>
</template>
