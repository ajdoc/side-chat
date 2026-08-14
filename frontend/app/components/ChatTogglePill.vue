<script setup lang="ts">
import { MessageSquare, PanelBottomClose } from 'lucide-vue-next'

/**
 * The way back to the conversation, from a surface that has taken the window.
 *
 * Shared by the two places that make that bargain — a Side Space's room and an app channel's
 * app. Both fold the timeline away by default, and both need one obvious way to get it back.
 * Having it in two shapes (a labelled button in the room's toolbar, a floating pill over the
 * app) meant "show chat" was in a different place depending on which kind of channel you were
 * standing in, and on a phone the room's version was folded inside a `⋯` menu — two taps to
 * reach the thing you reach for most.
 *
 * Floated over the surface rather than given a bar of its own: a permanent strip would cost
 * every surface a row of its window to hold one button.
 *
 * The host must be `relative`. Position is bottom-right, above the safe-area inset so it clears
 * a phone's home indicator, and `z-20` to sit over a canvas or a dock.
 */
const hidden = defineModel<boolean>({ required: true })
</script>

<template>
  <button
    type="button"
    class="absolute right-3 z-20 flex items-center gap-1.5 rounded-full border bg-background/90 px-3 py-2 text-xs shadow-lg backdrop-blur transition-colors hover:bg-muted"
    style="bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));"
    :title="hidden ? 'Show the conversation' : 'Hide the conversation'"
    @click="hidden = !hidden"
  >
    <component :is="hidden ? MessageSquare : PanelBottomClose" class="h-3.5 w-3.5 shrink-0" />
    {{ hidden ? 'Chat' : 'Hide chat' }}
  </button>
</template>
