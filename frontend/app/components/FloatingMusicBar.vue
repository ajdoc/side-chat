<script setup lang="ts">
import { ListMusic, Loader2, Pause, Play, SkipForward } from 'lucide-vue-next'
import type { MusicState, MusicTrack } from '~/types'

/**
 * The face of the pinned music player on a phone — the bar the window wears instead of a
 * bubble when it's put away (see {@link FloatingFrame}).
 *
 * It is deliberately a **facade**: the real {@link MusicPlayer}, with both engines and the
 * listen-along machinery, is still mounted directly below in the hidden window body, and is
 * still what makes the sound. This only shows what's on and drives the *shared* transport over
 * the widget API, exactly as the player's own buttons do — so tapping pause here pauses the
 * room, and the hidden player follows the same broadcast every other listener does.
 *
 * The one thing it won't do is *start* playback for someone who hasn't joined the listen-along.
 * That first play has to happen inside the player, in the same task as the tap, or the browser
 * refuses it sound — so before joining, the whole bar simply opens the sheet where the
 * "Listen along" button lives, and no transport is offered.
 */
/** Open the full sheet — the frame's `restore`, handed down so the bar can put it on its face. */
defineProps<{ open: () => void }>()

const { widget, refresh, hasJoined } = useMusicPin()
const { action } = useWidgets()

const state = computed(() => widget.value?.state as MusicState | undefined)
const current = computed<MusicTrack | null>(() => {
  const s = state.value
  return s?.currentIndex != null ? s.queue?.[s.currentIndex] ?? null : null
})
const isPlaying = computed(() => state.value?.status === 'playing')
const joined = computed(() => (widget.value ? hasJoined(widget.value.id) : false))

const busy = ref(false)
async function send(name: string) {
  const w = widget.value
  if (!w || busy.value) return
  busy.value = true
  try {
    await action(w.id, name)
    // No timeline behind the bar to fold the broadcast back in — the same resync the docked
    // player does for itself. See MusicPlayer.report().
    await refresh()
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <!-- Art + what's on, and the way into the full player. The transport sits outside it, so a
       tap on Pause isn't also a tap on "open the sheet". -->
  <button class="flex min-w-0 flex-1 items-center gap-2 text-left" title="Open the player" @click="open()">
    <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded bg-muted">
      <img v-if="current?.thumbnail" :src="current.thumbnail" alt="" class="h-full w-full object-cover">
      <ListMusic v-else class="h-4 w-4 text-muted-foreground" />
    </span>

    <span class="min-w-0 flex-1">
      <span class="block truncate text-xs font-medium">{{ current?.title ?? 'Nothing playing' }}</span>
      <span class="block truncate text-[11px] text-muted-foreground">
        {{ joined ? (current?.artist ?? 'Music') : 'Tap to listen along' }}
      </span>
    </span>
  </button>

  <!-- Transport, once this viewer has actually joined — see the note above on why. -->
  <template v-if="joined && current">
    <button
      class="shrink-0 rounded-full p-2 text-foreground hover:bg-muted disabled:opacity-50"
      :title="isPlaying ? 'Pause for everyone' : 'Play for everyone'"
      :disabled="busy"
      @click="send(isPlaying ? 'pause' : 'resume')"
    >
      <Loader2 v-if="busy" class="h-5 w-5 animate-spin" />
      <Pause v-else-if="isPlaying" class="h-5 w-5" />
      <Play v-else class="h-5 w-5" />
    </button>
    <button
      class="shrink-0 rounded-full p-2 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
      title="Skip"
      :disabled="busy"
      @click="send('next')"
    >
      <SkipForward class="h-5 w-5" />
    </button>
  </template>
</template>
