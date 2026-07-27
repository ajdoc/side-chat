<script setup lang="ts">
import type { FloatingSurfaceWindow } from '~/composables/useFloatingWindows'

/**
 * The body of a floating Side Desk *surface* app — the board, the notes, the calendar, the doc
 * shelf, the Open Canvas.
 *
 * This is the same renderer branch {@link SideDesk} has, minus the tab strip, and that is the
 * whole component: a surface app is already surface-agnostic (it takes a base path and a stream
 * and hangs its own endpoints off them), so floating one is just handing it the pair a tab would
 * have handed it. Nothing is copied and nothing is synced — views of one app on one surface
 * share a {@link useSurfaceStore}, so a stroke drawn in this window is on the desk tab beside it
 * before the server has heard about either.
 *
 * Docs' "jump to message" is dropped rather than wired: a floating window outlives the channel
 * it was opened from, so the timeline it would scroll may not be on screen — or may be a
 * different channel's. The file itself still opens, which is what the shelf is for.
 */
const props = defineProps<{ win: FloatingSurfaceWindow }>()

const app = computed(() => props.win.app)
</script>

<template>
  <!-- No padding: these apps own their full box (the board is a canvas, the canvas a viewport),
       exactly as they do in the desk panel. -->
  <div class="flex h-full min-h-0 flex-col">
    <SideDeskCanvas
      v-if="app === 'canvas'"
      :base-path="win.basePath"
      :stream-name="win.streamName"
      :can-edit="win.canEdit"
    />
    <Whiteboard
      v-else-if="app === 'board'"
      :base-path="`${win.basePath}/whiteboard`"
      :stream-name="win.streamName"
      :can-draw="win.canEdit"
    />
    <SideDeskNotes
      v-else-if="app === 'notes'"
      :base-path="win.basePath"
      :stream-name="win.streamName"
      :can-edit="win.canEdit"
    />
    <SideDeskCalendar
      v-else-if="app === 'calendar'"
      :base-path="win.basePath"
      :stream-name="win.streamName"
      :can-edit="win.canEdit"
    />
    <SideDeskDocs
      v-else
      :base-path="win.basePath"
      :stream-name="win.streamName"
      :can-edit="win.canEdit"
    />
  </div>
</template>
