<script setup lang="ts">
import type { Peer } from '~/types'
import { Pin, RotateCcw, X } from 'lucide-vue-next'

/**
 * The call as a floor plan: you in the middle, everybody else a dot you can drag.
 *
 * ## Why a map and not sliders
 *
 * The underlying thing is a bearing and a distance per person, and there is no arrangement of
 * numeric inputs that makes "put Sam on my left, a bit further back" a single gesture. A
 * top-down room is the picture people already have in their heads when they say that sentence,
 * so the control is the picture: drag someone to where you want to hear them from.
 *
 * ## Conventions
 *
 * Straight up is straight ahead, which matches both the maths (angle 0 is in front, growing
 * clockwise) and the way anybody reads a map. The dashed ring is the far edge — dragging past
 * it clamps rather than wraps, because a voice that leaps from far-left to far-right when you
 * overshoot is not a control anyone can aim.
 *
 * ## In a Side Space
 *
 * The same map, doing something slightly different: the dots *move*, because the room is
 * placing people and they are walking about. Dragging one pins it — that person's voice stops
 * following them and stays where you put it, which is what you want for the one person you're
 * actually talking to while a room mills about around you. Pinned people are listed under the
 * map so a pin is never something you can only discover by wondering why somebody isn't moving.
 *
 * Nothing here is sent anywhere. Where you put people is yours, like their volume — see
 * setPeerPlacement.
 */
const props = defineProps<{
  peers: Peer[]
  /** True in a Side Space: positions are live, and placing somebody is an override. */
  roomMode?: boolean
}>()

const emit = defineEmits<{
  place: [id: number, placement: { angle: number, distance: number }]
  unplace: [id: number]
  reset: []
}>()

/** Who you've overridden. Only interesting in a Side Space, where the rest are still moving. */
const pinned = computed(() => props.peers.filter(p => p.placed))

const surface = ref<HTMLElement | null>(null)
/** Who is being dragged, so the pointer handlers know what they're moving. */
const dragging = ref<number | null>(null)

/** Fraction of the radius kept clear in the middle — you are standing there. */
const CENTRE_HOLE = 0.18

/** Polar (ours) → percentage offsets (the CSS's). Straight up is angle 0. */
function styleFor(peer: Peer) {
  const radius = CENTRE_HOLE + (1 - CENTRE_HOLE) * Math.min(1, Math.max(0, peer.placement.distance))

  return {
    left: `${50 + Math.sin(peer.placement.angle) * radius * 50}%`,
    top: `${50 - Math.cos(peer.placement.angle) * radius * 50}%`,
  }
}

/** The inverse, from a pointer event. Clamped to the ring; see the note about overshooting. */
function placementAt(event: PointerEvent) {
  const box = surface.value?.getBoundingClientRect()
  if (!box) return null

  // Normalised to ±1 on both axes, so a non-square box (there isn't one, but layouts change)
  // can't skew the angle.
  const x = (event.clientX - box.left) / box.width * 2 - 1
  const y = (event.clientY - box.top) / box.height * 2 - 1
  const radius = Math.min(1, Math.hypot(x, y))

  return {
    angle: Math.atan2(x, -y),
    // Back out the centre hole so a dot dropped at the very middle reads as distance 0
    // rather than as the hole's radius.
    distance: Math.max(0, (radius - CENTRE_HOLE) / (1 - CENTRE_HOLE)),
  }
}

function startDrag(id: number, event: PointerEvent) {
  dragging.value = id
  // Capture on the dot, so a fast drag that outruns the pointer doesn't drop the person
  // halfway across the room.
  ;(event.target as HTMLElement).setPointerCapture?.(event.pointerId)
  move(event)
}

function move(event: PointerEvent) {
  if (dragging.value === null) return

  const placement = placementAt(event)
  if (placement) emit('place', dragging.value, placement)
}

function endDrag() {
  dragging.value = null
}

/**
 * The keyboard path, and not as an afterthought: arrows walk somebody round you, so this is
 * usable without a pointer at all — which for an audio control is the case most likely to
 * matter to the people who need it most.
 */
function nudge(peer: Peer, event: KeyboardEvent) {
  const step = Math.PI / 18 // 10°
  const { angle, distance } = peer.placement

  const moves: Record<string, { angle: number, distance: number }> = {
    ArrowLeft: { angle: angle - step, distance },
    ArrowRight: { angle: angle + step, distance },
    ArrowUp: { angle, distance: distance - 0.1 },
    ArrowDown: { angle, distance: distance + 0.1 },
  }

  const next = moves[event.key]
  if (!next) return

  event.preventDefault()
  emit('place', peer.id, { angle: next.angle, distance: Math.min(1, Math.max(0, next.distance)) })
}

/** Left / right / ahead / behind, for the label under a dot and for screen readers. */
function bearing(peer: Peer) {
  const degrees = Math.round((peer.placement.angle * 180 / Math.PI + 360) % 360)
  if (degrees < 15 || degrees > 345) return 'ahead'
  if (degrees > 165 && degrees < 195) return 'behind'

  return degrees < 180 ? `${degrees}° right` : `${360 - degrees}° left`
}

const initials = (name: string) => name.trim().slice(0, 2).toUpperCase()
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-center justify-between">
      <span class="text-sm font-medium">Where everyone sits</span>
      <button
        type="button"
        class="flex items-center gap-1 rounded px-1.5 py-1 text-xs text-muted-foreground transition hover:bg-muted"
        @click="emit('reset')"
      >
        <RotateCcw class="h-3 w-3" />
        {{ props.roomMode ? 'All back to auto' : 'Arrange for me' }}
      </button>
    </div>

    <div
      ref="surface"
      class="relative aspect-square w-full touch-none select-none rounded-full border border-dashed bg-muted/30"
      @pointermove="move"
      @pointerup="endDrag"
      @pointercancel="endDrag"
    >
      <!-- You, and which way you're facing. -->
      <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 text-center">
        <div class="mx-auto h-2.5 w-2.5 rounded-full bg-primary" />
        <span class="mt-1 block text-[10px] text-muted-foreground">You</span>
      </div>
      <span class="absolute left-1/2 top-1 -translate-x-1/2 text-[10px] uppercase tracking-wide text-muted-foreground">
        Ahead
      </span>

      <button
        v-for="peer in props.peers"
        :key="peer.id"
        type="button"
        class="absolute flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 cursor-grab items-center justify-center rounded-full border bg-background text-[11px] font-medium shadow-sm transition-shadow focus:outline-none focus:ring-2 focus:ring-primary"
        :class="dragging === peer.id ? 'cursor-grabbing shadow-md ring-2 ring-primary' : ''"
        :style="styleFor(peer)"
        :title="`${peer.name} — ${bearing(peer)}${peer.placed && props.roomMode ? ' (pinned)' : ''}`"
        :aria-label="`${peer.name}, ${bearing(peer)}${peer.placed && props.roomMode ? ', pinned' : ''}. Arrow keys to move.`"
        @pointerdown="startDrag(peer.id, $event)"
        @keydown="nudge(peer, $event)"
      >
        {{ initials(peer.name) }}
        <Pin
          v-if="peer.placed && props.roomMode"
          class="absolute -right-0.5 -top-0.5 h-3 w-3 rounded-full bg-background text-primary"
        />
      </button>
    </div>

    <div v-if="props.roomMode && pinned.length" class="flex flex-wrap items-center gap-1.5">
      <span class="text-xs text-muted-foreground">Pinned:</span>
      <button
        v-for="peer in pinned"
        :key="peer.id"
        type="button"
        class="flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pl-2 pr-1 text-xs transition hover:bg-muted"
        :title="`Let the room place ${peer.name} again`"
        @click="emit('unplace', peer.id)"
      >
        {{ peer.name }}
        <X class="h-3 w-3 text-muted-foreground" />
      </button>
    </div>

    <p class="text-xs text-muted-foreground">
      <template v-if="props.roomMode">
        Dots move as people walk. Drag one to pin that person there instead — their voice stops
        following them until you let the room have them back.
      </template>
      <template v-else>
        Drag someone to hear them from that direction — or focus a dot and use the arrow keys.
      </template>
      Where you put people is yours alone; nobody is told, and it's remembered for next time.
    </p>
  </div>
</template>
