<script setup lang="ts">
import { Mic2, Pause, Play, SkipBack, SkipForward, X } from 'lucide-vue-next'
import type { LyricLine } from '~/lib/lrc'
import type { MusicTrack } from '~/types'

/**
 * The full-screen sing-along: big lyrics, a dimmed room, and just enough transport to run
 * the song from across the room.
 *
 * Teleported to `<body>` because the player card lives inside the scrolling message list —
 * a fixed overlay nested in there would be clipped and would scroll away. The transport
 * buttons re-emit rather than acting: the widget's shared state is the single source of
 * truth, and MusicPlayer already owns talking to it.
 */
defineProps<{
  track: MusicTrack
  lines: LyricLine[]
  synced: boolean
  loading?: boolean
  missing?: boolean
  instrumental?: boolean
  position: number
  offset?: number
  duration: number
  playing: boolean
  fmt: (secs: number | null | undefined) => string
}>()

const emit = defineEmits<{
  close: []
  toggle: []
  next: []
  prev: []
  seek: [seconds: number]
  nudge: [delta: number]
  resetOffset: []
}>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
  // Space is the universal "pause the karaoke" — but not while someone's typing.
  if (e.key === ' ' && !(e.target as HTMLElement)?.closest('input, textarea, [contenteditable]')) {
    e.preventDefault()
    emit('toggle')
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  // The page behind must not scroll while the stage is up.
  document.body.style.overflow = 'hidden'
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100] flex flex-col bg-neutral-950/95 backdrop-blur-sm">
      <!-- The album art, blown up and blurred, as the backdrop — cheap atmosphere. -->
      <img
        v-if="track.thumbnail"
        :src="track.thumbnail"
        alt=""
        class="pointer-events-none absolute inset-0 h-full w-full scale-110 object-cover opacity-20 blur-3xl"
      >

      <header class="relative flex items-center gap-3 px-5 py-4 text-white">
        <Mic2 class="h-5 w-5 flex-none text-white/70" />
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold">{{ track.title }}</p>
          <p v-if="track.artist" class="truncate text-xs text-white/50">{{ track.artist }}</p>
        </div>
        <button class="flex-none rounded-full p-2 text-white/60 hover:bg-white/10 hover:text-white" title="Close (Esc)" @click="emit('close')">
          <X class="h-5 w-5" />
        </button>
      </header>

      <!-- min-h-0 so the lyric list scrolls inside the flex column instead of overflowing it. -->
      <div class="relative min-h-0 flex-1">
        <KaraokeLyrics
          :lines="lines"
          :synced="synced"
          :loading="loading"
          :missing="missing"
          :instrumental="instrumental"
          :position="position"
          :offset="offset"
          variant="stage"
          @seek="s => emit('seek', s)"
          @nudge="d => emit('nudge', d)"
          @reset-offset="emit('resetOffset')"
        />
        <!-- Fade the lyrics out at both edges so lines arrive and leave rather than clip. -->
        <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-neutral-950/95 to-transparent" />
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-neutral-950/95 to-transparent" />
      </div>

      <footer class="relative flex items-center justify-center gap-4 px-5 py-5 text-white">
        <button class="rounded-full p-2 text-white/70 hover:bg-white/10 hover:text-white" title="Previous" @click="emit('prev')">
          <SkipBack class="h-5 w-5" />
        </button>
        <button class="rounded-full bg-white/15 p-3 hover:bg-white/25" :title="playing ? 'Pause' : 'Play'" @click="emit('toggle')">
          <component :is="playing ? Pause : Play" class="h-6 w-6" />
        </button>
        <button class="rounded-full p-2 text-white/70 hover:bg-white/10 hover:text-white" title="Next" @click="emit('next')">
          <SkipForward class="h-5 w-5" />
        </button>
        <span class="ml-3 text-xs tabular-nums text-white/50">{{ fmt(position) }} / {{ fmt(duration) }}</span>
      </footer>
    </div>
  </Teleport>
</template>
