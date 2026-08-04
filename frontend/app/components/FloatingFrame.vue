<script setup lang="ts">
import { GripVertical, Minus, X } from 'lucide-vue-next'
import type { FloatingWindow } from '~/composables/useFloatingWindows'

/**
 * The chrome around a floating window. Two shapes, one body.
 *
 * **With room** (a desktop window, a tablet held wide) it is what it has always been: a
 * draggable title bar, a resize corner, close, and a **minimize** that shrinks the window into
 * a small bubble docked to the nearest screen edge — a chat-head, restored with a click.
 *
 * **On a phone** (`compact`) free positioning is meaningless: two hundred pixels of window over
 * a three-hundred-and-ninety pixel screen is not a panel, it's an obstruction, and there is no
 * cursor to grab a 4px resize corner with. So the same two states are drawn differently — a
 * window is either put away or it is the screen:
 *   - minimized → a bubble, as ever (and, for music, a full-width **bar** docked to the bottom,
 *     because the pinned song is the one window you want to keep operating while you read);
 *   - open → a full-screen sheet, with no drag and no resize.
 * Nothing about the window *list* changes, so geometry arranged on a desktop is still there
 * when the same account opens the app on a laptop again.
 *
 * Content goes in the default slot; the title bar's icon+label in `title`, the bubble's icon in
 * `bubble`, and the compact music bar's face in `bar`.
 *
 * Minimizing hides the window body with `v-show`, never `v-if`: a window may hold a live player
 * (a running video, the pinned song), and unmounting it would tear that down. So even as a
 * bubble — or behind the bar — the content stays mounted and playing, which is the whole point
 * of the shelf, and is what lets the bar be a pure facade with no engine of its own.
 */
const BUBBLE = 52

const props = defineProps<{ win: FloatingWindow }>()

const { update, persist, focus, close, compact } = useFloatingWindows()

const MIN_W = 260
const MIN_H = 160

/** Music is the only window with a docked-bar form; everything else minimizes to a bubble. */
const isMusic = computed(() => props.win.kind === 'widget' && props.win.widgetType === 'music')
/** The compact stand-in for a minimized music window: a bar across the bottom of the screen. */
const asBar = computed(() => compact.value && props.win.collapsed && isMusic.value)
const asBubble = computed(() => props.win.collapsed && !asBar.value)
/** Open, on a screen with no room to arrange anything: the window *is* the screen. */
const asSheet = computed(() => compact.value && !props.win.collapsed)

function vw() { return window.innerWidth }
function vh() { return window.innerHeight }
function clamp(v: number, lo: number, hi: number) { return Math.max(lo, Math.min(hi, v)) }

// A title-bar drag or a corner resize. Screen-pixel start; window geometry at grab time.
type Op = { type: 'move' | 'resize', startX: number, startY: number, origX: number, origY: number, origW: number, origH: number }
let op: Op | null = null

function onPointerDown(type: Op['type'], e: PointerEvent) {
  // A sheet has nowhere to be moved to and no edge to be dragged out from.
  if (asSheet.value) return
  e.preventDefault()
  focus(props.win.id)
  op = { type, startX: e.clientX, startY: e.clientY, origX: props.win.x, origY: props.win.y, origW: props.win.w, origH: props.win.h }
  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
}

function onMove(e: PointerEvent) {
  if (!op) return
  const dx = e.clientX - op.startX
  const dy = e.clientY - op.startY
  if (op.type === 'move') {
    update(props.win.id, {
      x: clamp(Math.round(op.origX + dx), 0, Math.max(0, vw() - 40)),
      y: clamp(Math.round(op.origY + dy), 0, Math.max(0, vh() - 40)),
    })
  } else {
    update(props.win.id, {
      w: Math.max(MIN_W, Math.round(op.origW + dx)),
      h: Math.max(MIN_H, Math.round(op.origH + dy)),
    })
  }
}

function onUp() {
  window.removeEventListener('pointermove', onMove)
  window.removeEventListener('pointerup', onUp)
  op = null
  persist()
}

/** Snap a bubble's left edge to whichever side of the screen its centre is nearer. */
function snapToSide() {
  const centre = props.win.x + BUBBLE / 2
  const x = centre < vw() / 2 ? 8 : vw() - BUBBLE - 8
  update(props.win.id, { x, y: clamp(props.win.y, 8, Math.max(8, vh() - BUBBLE - 8)) })
}

function minimize() {
  update(props.win.id, { collapsed: true })
  // A bar is positioned by the stylesheet, not by `x`/`y`; snapping it would only scramble the
  // geometry it goes back to on a wider screen.
  if (!(compact.value && isMusic.value)) snapToSide()
  persist()
}

function restore() {
  // Pull the window back fully on-screen — a bubble docked at the very edge would otherwise
  // restore half off it. A sheet is the screen, so there is nothing to pull it back onto.
  update(props.win.id, {
    collapsed: false,
    ...(compact.value
      ? {}
      : {
          x: clamp(props.win.x, 8, Math.max(8, vw() - props.win.w - 8)),
          y: clamp(props.win.y, 8, Math.max(8, vh() - props.win.h - 8)),
        }),
  })
  persist()
}

// Bubble drag: move it around, re-dock to a side on release. A press that barely moves is a
// click — restore — rather than a drag, so a bubble is easy to reopen.
let bubbleMoved = false
function onBubbleDown(e: PointerEvent) {
  e.preventDefault()
  focus(props.win.id)
  bubbleMoved = false
  const startX = e.clientX
  const startY = e.clientY
  const origX = props.win.x
  const origY = props.win.y
  const move = (ev: PointerEvent) => {
    const dx = ev.clientX - startX
    const dy = ev.clientY - startY
    if (Math.abs(dx) + Math.abs(dy) > 4) bubbleMoved = true
    update(props.win.id, {
      x: clamp(Math.round(origX + dx), 0, Math.max(0, vw() - BUBBLE)),
      y: clamp(Math.round(origY + dy), 0, Math.max(0, vh() - BUBBLE)),
    })
  }
  const up = () => {
    window.removeEventListener('pointermove', move)
    window.removeEventListener('pointerup', up)
    if (bubbleMoved) { snapToSide(); persist() }
  }
  window.addEventListener('pointermove', move)
  window.addEventListener('pointerup', up)
}
function onBubbleClick() {
  if (!bubbleMoved) restore()
}

function clampToViewport() {
  // Bars and sheets are laid out against the viewport by CSS; only free-positioned windows can
  // end up off the edge of a shrinking one.
  if (compact.value) return
  const max = props.win.collapsed ? BUBBLE : props.win.w
  const maxH = props.win.collapsed ? BUBBLE : props.win.h
  const x = Math.min(props.win.x, Math.max(0, vw() - max - 8))
  const y = Math.min(props.win.y, Math.max(0, vh() - maxH - 8))
  if (x !== props.win.x || y !== props.win.y) { update(props.win.id, { x, y }); persist() }
}

/**
 * The geometry for whichever shape we're wearing.
 *
 * A sheet and a bar get none: they're positioned by their classes against the viewport, and a
 * stale `left`/`top` from the last desktop session would drag them off it.
 */
const geometry = computed(() => {
  if (asSheet.value || asBar.value) return { zIndex: props.win.z }
  if (props.win.collapsed) {
    return { left: `${props.win.x}px`, top: `${props.win.y}px`, width: `${BUBBLE}px`, height: `${BUBBLE}px`, zIndex: props.win.z }
  }
  return { left: `${props.win.x}px`, top: `${props.win.y}px`, width: `${props.win.w}px`, height: `${props.win.h}px`, zIndex: props.win.z }
})

const shell = computed(() => {
  if (asBar.value) return 'inset-x-0 bottom-0 flex flex-col border-t shadow-2xl safe-inset'
  if (asSheet.value) return 'inset-0 flex flex-col shadow-2xl safe-inset'
  if (props.win.collapsed) return 'grid cursor-pointer place-items-center rounded-full shadow-lg'
  return 'flex flex-col rounded-xl shadow-2xl'
})

onMounted(() => window.addEventListener('resize', clampToViewport))
onBeforeUnmount(() => {
  window.removeEventListener('resize', clampToViewport)
  window.removeEventListener('pointermove', onMove)
  window.removeEventListener('pointerup', onUp)
})
</script>

<template>
  <div
    class="pointer-events-auto fixed overflow-hidden border bg-background"
    :class="shell"
    :style="geometry"
    @pointerdown="focus(win.id)"
  >
    <!-- Minimized on a phone, and music: the docked bar. Tapping the face opens the sheet;
         the transport lives in the `bar` slot, which drives the shared state over the API
         rather than the (hidden, still-mounted) player below. -->
    <div v-if="asBar" class="flex h-14 shrink-0 items-center gap-2 px-2">
      <!-- `open` rather than a wrapping button: the bar carries its own transport buttons, and
           a button inside a button is invalid markup that browsers resolve unpredictably. -->
      <slot name="bar" :open="restore" />
      <button
        class="shrink-0 rounded p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Close"
        @click="close(win.id)"
      >
        <X class="h-4 w-4" />
      </button>
    </div>

    <!-- Minimized anywhere else: the bubble. Drag to move + re-dock, click to restore. -->
    <button
      v-else-if="asBubble"
      class="grid h-full w-full place-items-center text-primary transition-transform hover:scale-105"
      title="Restore"
      @pointerdown="onBubbleDown"
      @click="onBubbleClick"
    >
      <slot name="bubble" />
    </button>

    <!-- Title bar. A drag handle only where there is somewhere to drag to; on a sheet it is
         just a header. Hidden (not unmounted) while minimized. -->
    <div
      v-show="!win.collapsed"
      class="flex shrink-0 items-center gap-1.5 border-b bg-muted/40 px-2"
      :class="asSheet ? 'h-11' : 'h-8 cursor-move'"
      @pointerdown="onPointerDown('move', $event)"
    >
      <GripVertical v-if="!asSheet" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <div class="flex min-w-0 flex-1 items-center gap-1.5 font-medium" :class="asSheet ? 'text-sm' : 'text-xs'">
        <slot name="title" />
      </div>
      <button
        class="shrink-0 rounded text-muted-foreground hover:bg-muted hover:text-foreground"
        :class="asSheet ? 'p-2' : 'p-1'"
        :title="asSheet ? (isMusic ? 'Put the player away' : 'Minimize to a bubble') : 'Minimize to a bubble'"
        @pointerdown.stop
        @click="minimize"
      >
        <Minus :class="asSheet ? 'h-4 w-4' : 'h-3.5 w-3.5'" />
      </button>
      <button
        class="shrink-0 rounded text-muted-foreground hover:bg-muted hover:text-foreground"
        :class="asSheet ? 'p-2' : 'p-1'"
        title="Close"
        @pointerdown.stop
        @click="close(win.id)"
      >
        <X :class="asSheet ? 'h-4 w-4' : 'h-3.5 w-3.5'" />
      </button>
    </div>

    <!-- Body. Always mounted; hidden (v-show) while minimized so a live player keeps running. -->
    <div v-show="!win.collapsed" class="relative min-h-0 flex-1 overflow-hidden">
      <slot />

      <div
        v-if="!asSheet"
        class="absolute bottom-0 right-0 z-10 h-4 w-4 cursor-nwse-resize"
        @pointerdown="onPointerDown('resize', $event)"
      >
        <div class="absolute bottom-1 right-1 h-2 w-2 border-b-2 border-r-2 border-muted-foreground/40" />
      </div>
    </div>
  </div>
</template>
