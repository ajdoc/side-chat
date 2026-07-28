<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next'
import type { FloatingWidgetWindow } from '~/composables/useFloatingWindows'

/**
 * The music window's body — the one floating window that isn't driven by the generic widget
 * path. Music keeps its dedicated brain ({@link useMusicPin}): the pinned widget, the
 * `.WidgetUpdated` resync, the listen-along opt-in, the Spotify/YouTube hand-off and the
 * restore-across-reload all live there, so this just renders that widget as a docked
 * {@link MusicPlayer} inside the shared frame. Being mounted at the app level (via
 * {@link FloatingWindows}) is what keeps the sound alive as you move around — the pin's whole
 * reason for being.
 */
const props = defineProps<{ win: FloatingWidgetWindow }>()

const { widget, restore } = useMusicPin()

onMounted(async () => {
  // On a fresh page the window may come back from storage before the pin has been re-seated —
  // re-pin from the remembered id. If nothing ends up pinned, this window is an orphan; drop it.
  if (!widget.value) await restore()
  if (!widget.value) useFloatingWindows().close(props.win.id)
})

// No unpin on unmount. It used to live here, and it made every incidental remount of the shelf
// read as "the user closed the player": the pin was dropped, the docked engine went with it
// (silence) and the timeline card — no longer stubbed, because nothing was pinned any more —
// inflated a second player for the same song. Closing is now the shelf's business: the ✕ calls
// `close`, and `close` clears the pin (see useFloatingWindows).
</script>

<template>
  <div class="h-full overflow-y-auto p-2">
    <MusicPlayer v-if="widget" :widget="widget" docked />
    <div v-else class="flex h-full items-center justify-center">
      <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
    </div>
  </div>
</template>
