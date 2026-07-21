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
 */
const { widget, unpin, restore } = useMusicPin()

const collapsed = useLocalStorage('music:dockCollapsed', false)

const nowPlaying = computed<MusicTrack | null>(() => {
  const s = widget.value?.state as MusicState | undefined
  if (!s || s.currentIndex == null) return null
  return s.queue?.[s.currentIndex] ?? null
})

onMounted(() => { void restore() })
</script>

<template>
  <div
    v-if="widget"
    class="fixed bottom-4 right-4 z-40 w-[22rem] max-w-[calc(100vw-2rem)] rounded-xl border bg-background shadow-lg"
  >
    <div class="flex items-center gap-1.5 px-2 py-1">
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
    <!-- v-show, never v-if: unmounting would tear down the player and stop the music. -->
    <div v-show="!collapsed" class="max-h-[70vh] overflow-y-auto px-2 pb-2">
      <MusicPlayer :widget="widget" docked />
    </div>
  </div>
</template>
