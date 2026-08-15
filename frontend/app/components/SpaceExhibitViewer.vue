<script setup lang="ts">
import { X, ZoomIn, ZoomOut } from 'lucide-vue-next'
import type { ExhibitPiece } from '~/lib/spaceMapEngine'

/**
 * A painting, full size, with the card beside it.
 *
 * The point of the whole exhibit feature. A drawn museum paints its art at a few dozen pixels —
 * enough to read as a gallery, nothing you can actually look at — so walking up to a frame and
 * pressing E opens the real thing here.
 *
 * ## Why its own overlay rather than the floating-window shelf
 *
 * Everything else a Side Space opens (the board, the music, a document) is something you *use
 * while standing in the room*, which is exactly what a floating window is for. Looking at a
 * painting is the opposite: it wants the whole display and nothing else on it, and it ends when
 * you look away. So it's a plain overlay that takes the screen and gives it straight back.
 *
 * The room keeps running behind it — the call, everyone's walking, proximity — so closing it puts
 * you back exactly where you were standing, which is what makes a gallery worth walking around
 * rather than a list of pictures with a map attached.
 */
const props = defineProps<{ piece: ExhibitPiece }>()
const emit = defineEmits<{ close: [] }>()

/**
 * Zoom, because the reason to open a painting is to get closer to it.
 *
 * Steps rather than a continuous pinch: this is a viewer, not an editor, and the useful gesture
 * is "closer" rather than "closer by precisely this much". `1` is fitted to the display.
 */
const ZOOM_STEPS = [1, 1.5, 2, 3, 4] as const
const zoom = ref(0)

const zoomedIn = computed(() => zoom.value > 0)
const scale = computed(() => ZOOM_STEPS[zoom.value] ?? 1)

function zoomIn() {
  zoom.value = Math.min(ZOOM_STEPS.length - 1, zoom.value + 1)
}

function zoomOut() {
  zoom.value = Math.max(0, zoom.value - 1)
}

/** A new painting is a fresh look at it, not a continuation of the last one's zoom. */
watch(() => props.piece.exhibit_id, () => { zoom.value = 0 })

/**
 * Escape closes, and while this is open it is the *only* thing Escape does.
 *
 * Captured rather than bubbled, because the room behind this is still listening for keys — it has
 * to be, or your avatar would be stranded mid-step — and a viewer you could walk out from behind
 * is a viewer that doesn't feel like it took the screen.
 */
function onKey(e: KeyboardEvent) {
  if (e.key !== 'Escape') return

  e.preventDefault()
  e.stopPropagation()
  emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey, { capture: true }))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, { capture: true }))
</script>

<template>
  <div class="fixed inset-0 z-[60] flex flex-col bg-black/92 backdrop-blur-sm">
    <header class="flex shrink-0 items-start justify-between gap-4 px-4 py-3 text-white">
      <div class="min-w-0">
        <h2 class="truncate text-base font-semibold">{{ piece.title }}</h2>
        <p v-if="piece.artist" class="truncate text-sm text-white/70">{{ piece.artist }}</p>
      </div>

      <div class="flex shrink-0 items-center gap-1">
        <button
          type="button"
          class="rounded p-2 text-white/80 transition hover:bg-white/10 hover:text-white disabled:opacity-40"
          :disabled="zoom === 0"
          title="Zoom out"
          @click="zoomOut"
        >
          <ZoomOut class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="rounded p-2 text-white/80 transition hover:bg-white/10 hover:text-white disabled:opacity-40"
          :disabled="zoom === ZOOM_STEPS.length - 1"
          title="Zoom in"
          @click="zoomIn"
        >
          <ZoomIn class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="rounded p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
          title="Close (Esc)"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </header>

    <!--
      The picture. Scrollable only once it is bigger than the space, so a fitted painting has no
      scrollbars and a zoomed one can be moved around.
    -->
    <div class="min-h-0 flex-1" :class="zoomedIn ? 'overflow-auto' : 'overflow-hidden'">
      <div class="flex min-h-full min-w-full items-center justify-center p-4">
        <img
          :src="piece.url"
          :alt="piece.title"
          class="max-h-full origin-center select-none"
          :class="zoomedIn ? 'max-w-none' : 'max-w-full object-contain'"
          :style="zoomedIn ? { width: `${scale * 100}%` } : undefined"
          draggable="false"
        >
      </div>
    </div>

    <!--
      The wall label. Below the picture rather than beside it, because it is the thing you read
      *after* looking — and because a column of text next to a painting takes width from the
      painting on every display narrow enough to matter.
    -->
    <footer v-if="piece.caption" class="max-h-[28%] shrink-0 overflow-auto border-t border-white/10 px-4 py-3">
      <p class="mx-auto max-w-3xl whitespace-pre-line text-sm leading-relaxed text-white/80">
        {{ piece.caption }}
      </p>
    </footer>
  </div>
</template>
