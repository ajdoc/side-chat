<script setup lang="ts">
import { PenTool, X } from 'lucide-vue-next'

/**
 * The channel's own shared whiteboard, as a right-hand panel beside the timeline — the same
 * board a side chat has, for the whole channel. There's no roster here, so everyone in the
 * channel can draw; the panel only opens for members, so `can-draw` is simply always on.
 *
 * All the real work lives in the surface-agnostic {@link Whiteboard}; this is just its panel
 * shell. Keyed by channel id so switching channels remounts a fresh board.
 */
const props = defineProps<{ channelId: number }>()
const route = useRoute()

function close() {
  const q: Record<string, string> = {}
  for (const [k, v] of Object.entries(route.query)) if (typeof v === 'string' && k !== 'board') q[k] = v
  navigateTo({ path: route.path, query: q })
}
</script>

<template>
  <aside class="flex w-[420px] shrink-0 flex-col border-l">
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex items-center gap-2 font-semibold">
        <PenTool class="h-4 w-4 text-muted-foreground" /> Whiteboard
      </div>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <Whiteboard
      :key="channelId"
      :base-path="`/api/channels/${channelId}/whiteboard`"
      :stream-name="`channel.${channelId}`"
      :can-draw="true"
    />
  </aside>
</template>
