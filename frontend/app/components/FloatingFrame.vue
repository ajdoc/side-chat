<script setup lang="ts">
import { GripVertical, Minus, X } from 'lucide-vue-next'
import type { FloatingWindow } from '~/composables/useFloatingWindows'

/**
 * The chrome around a floating window: a draggable title bar, a resize corner, close, and a
 * **minimize** that shrinks the window into a small bubble docked to the nearest screen edge —
 * a chat-head, restored with a click. Everything is shared by every window kind so none has to
 * reinvent it. The content goes in the default slot; the title bar's icon+label in `title`, and
 * the bubble's icon in `bubble`.
 *
 * Minimizing hides the window body with `v-show`, never `v-if`: a window may hold a live player
 * (a running video, the pinned song), and unmounting it would tear that down. So even as a
 * bubble the content stays mounted and playing behind the scenes — the whole point of the shelf.
 */
const BUBBLE = 52

const props = defineProps<{ win: FloatingWindow }>()

const { update, persist, focus, close } = useFloatingWindows()

const MIN_W = 260
const MIN_H = 160

function vw() { return window.innerWidth }
function vh() { return window.innerHeight }
function clamp(v: number, lo: number, hi: number) { return Math.max(lo, Math.min(hi, v)) }

// A title-bar drag or a corner resize. Screen-pixel start; window geometry at grab time.
type Op = { type: 'move' | 'resize', startX: number, startY: number, origX: number, origY: number, origW: number, origH: number }
let op: Op | null = null

function onPointerDown(type: Op['type'], e: PointerEvent) {
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
  snapToSide()
  persist()
}

function restore() {
  // Pull the window back fully on-screen — a bubble docked at the very edge would otherwise
  // restore half off it.
  update(props.win.id, {
    collapsed: false,
    x: clamp(props.win.x, 8, Math.max(8, vw() - props.win.w - 8)),
    y: clamp(props.win.y, 8, Math.max(8, vh() - props.win.h - 8)),
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
  const max = props.win.collapsed ? BUBBLE : props.win.w
  const maxH = props.win.collapsed ? BUBBLE : props.win.h
  const x = Math.min(props.win.x, Math.max(0, vw() - max - 8))
  const y = Math.min(props.win.y, Math.max(0, vh() - maxH - 8))
  if (x !== props.win.x || y !== props.win.y) { update(props.win.id, { x, y }); persist() }
}

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
    :class="win.collapsed ? 'grid cursor-pointer place-items-center rounded-full shadow-lg' : 'flex flex-col rounded-xl shadow-2xl'"
    :style="win.collapsed
      ? { left: `${win.x}px`, top: `${win.y}px`, width: `${BUBBLE}px`, height: `${BUBBLE}px`, zIndex: win.z }
      : { left: `${win.x}px`, top: `${win.y}px`, width: `${win.w}px`, height: `${win.h}px`, zIndex: win.z }"
    @pointerdown="focus(win.id)"
  >
    <!-- Minimized: the bubble. Drag to move + re-dock, click to restore. -->
    <button
      v-if="win.collapsed"
      class="grid h-full w-full place-items-center text-primary transition-transform hover:scale-105"
      title="Restore"
      @pointerdown="onBubbleDown"
      @click="onBubbleClick"
    >
      <slot name="bubble" />
    </button>

    <!-- Title bar / drag handle. Hidden (not unmounted) while minimized. -->
    <div
      v-show="!win.collapsed"
      class="flex h-8 shrink-0 cursor-move items-center gap-1.5 border-b bg-muted/40 px-2"
      @pointerdown="onPointerDown('move', $event)"
    >
      <GripVertical class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <div class="flex min-w-0 flex-1 items-center gap-1.5 text-xs font-medium">
        <slot name="title" />
      </div>
      <button
        class="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Minimize to a bubble"
        @pointerdown.stop
        @click="minimize"
      >
        <Minus class="h-3.5 w-3.5" />
      </button>
      <button
        class="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Close"
        @pointerdown.stop
        @click="close(win.id)"
      >
        <X class="h-3.5 w-3.5" />
      </button>
    </div>

    <!-- Body. Always mounted; hidden (v-show) while minimized so a live player keeps running. -->
    <div v-show="!win.collapsed" class="relative min-h-0 flex-1 overflow-hidden">
      <slot />

      <div
        class="absolute bottom-0 right-0 z-10 h-4 w-4 cursor-nwse-resize"
        @pointerdown="onPointerDown('resize', $event)"
      >
        <div class="absolute bottom-1 right-1 h-2 w-2 border-b-2 border-r-2 border-muted-foreground/40" />
      </div>
    </div>
  </div>
</template>
