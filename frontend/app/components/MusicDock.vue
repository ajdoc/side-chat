<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { ChevronDown, ChevronUp, ListMusic, X } from 'lucide-vue-next'
import type { MusicState, MusicTrack } from '~/types'

/**
 * The floating home of the pinned music player.
 *
 * Mounted once by the app layout, which is the point: a music card in a timeline dies the
 * moment you open another channel, and with it the sound. Rendered from here, the very same
 * MusicPlayer sits outside every page and keeps playing across channels, servers, DMs and
 * group chats. See useMusicPin for how a widget gets here and stays fresh.
 *
 * Collapsing hides the card with CSS rather than unmounting it — an unmounted player is a
 * destroyed <iframe>, which is exactly the silence this component exists to prevent.
 *
 * The window is resizable (drag the top-left grip): the player is a tall card, so being able
 * to give it more room — or shrink it out of the way — is what makes the dock usable. The
 * size is remembered per tab.
 */
const { widget, unpin, restore } = useMusicPin()

const collapsed = useLocalStorage('music:dockCollapsed', false)

// Remembered size. Width has a sensible default; height stays 0 ("fit the content, up to a
// cap") until the user drags, after which it becomes an explicit, scrollable height.
const MIN_W = 300
const MIN_H = 280
const dockW = useLocalStorage('music:dockW', 360)
const dockH = useLocalStorage('music:dockH', 0)

const frame = ref<HTMLElement | null>(null)

const nowPlaying = computed<MusicTrack | null>(() => {
  const s = widget.value?.state as MusicState | undefined
  if (!s || s.currentIndex == null) return null
  return s.queue?.[s.currentIndex] ?? null
})

function maxW() { return Math.max(MIN_W, window.innerWidth - 32) }
function maxH() { return Math.max(MIN_H, window.innerHeight - 32) }

// Anchored bottom-right, so dragging the top-left grip up-and-left grows the window.
function onResizeStart(e: PointerEvent) {
  e.preventDefault()
  const startX = e.clientX
  const startY = e.clientY
  const startW = dockW.value
  const startH = dockH.value || frame.value?.offsetHeight || MIN_H
  const handle = e.currentTarget as HTMLElement
  handle.setPointerCapture(e.pointerId)

  const move = (ev: PointerEvent) => {
    dockW.value = Math.round(Math.min(maxW(), Math.max(MIN_W, startW + (startX - ev.clientX))))
    dockH.value = Math.round(Math.min(maxH(), Math.max(MIN_H, startH + (startY - ev.clientY))))
  }
  const up = () => {
    handle.releasePointerCapture(e.pointerId)
    window.removeEventListener('pointermove', move)
    window.removeEventListener('pointerup', up)
  }
  window.addEventListener('pointermove', move)
  window.addEventListener('pointerup', up)
}

// Keep the window on-screen if the viewport shrinks under it.
function clampToViewport() {
  if (dockW.value > maxW()) dockW.value = maxW()
  if (dockH.value && dockH.value > maxH()) dockH.value = maxH()
}

onMounted(() => {
  void restore()
  window.addEventListener('resize', clampToViewport)
})
onBeforeUnmount(() => window.removeEventListener('resize', clampToViewport))
</script>

<template>
  <div
    v-if="widget"
    ref="frame"
    class="fixed bottom-4 right-4 z-40 flex max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-xl border bg-background shadow-lg"
    :style="{
      width: `${dockW}px`,
      height: collapsed ? undefined : (dockH ? `${dockH}px` : undefined),
      maxHeight: collapsed ? undefined : 'calc(100vh - 2rem)',
    }"
  >
    <!-- Resize grip. Only when expanded — a collapsed dock is just its title bar. -->
    <button
      v-if="!collapsed"
      class="group absolute left-0 top-0 z-10 flex h-5 w-5 cursor-nwse-resize items-start justify-start p-1"
      title="Drag to resize"
      @pointerdown="onResizeStart"
    >
      <span class="h-2 w-2 rounded-tl-sm border-l-2 border-t-2 border-muted-foreground/40 group-hover:border-primary" />
    </button>

    <div class="flex flex-none items-center gap-1.5 border-b py-1 pl-6 pr-2">
      <ListMusic class="h-3.5 w-3.5 flex-none text-primary" />
      <span class="min-w-0 flex-1 truncate text-xs" :title="nowPlaying?.title">
        {{ nowPlaying?.title ?? 'Pinned player' }}
      </span>
      <button
        class="flex-none p-1 text-muted-foreground hover:text-foreground"
        :title="collapsed ? 'Expand' : 'Collapse'"
        @click="collapsed = !collapsed"
      >
        <component :is="collapsed ? ChevronUp : ChevronDown" class="h-3.5 w-3.5" />
      </button>
      <button class="flex-none p-1 text-muted-foreground hover:text-foreground" title="Unpin — stops when you leave the chat" @click="unpin">
        <X class="h-3.5 w-3.5" />
      </button>
    </div>

    <!-- v-show, never v-if: unmounting would tear down the player and stop the music. The
         content scrolls inside the frame so the whole widget is always reachable. -->
    <div v-show="!collapsed" class="min-h-0 flex-1 overflow-y-auto px-2 py-2">
      <MusicPlayer :widget="widget" docked />
    </div>
  </div>
</template>
