<script setup lang="ts">
import { LayoutPanelLeft, X } from 'lucide-vue-next'
import type { SideDeskAppId } from '~/types'

/**
 * A channel's (or DM's) Side Desk, as a right-hand panel beside the timeline — the same
 * workspace a side chat has, for the whole channel. There's no roster here, so everyone in
 * the channel can author; the panel only opens for members, so `can-edit` is simply always
 * on. The active app rides in the URL as `?desk=<app>` so it's deep-linkable and survives
 * a reload; opening the panel at all is just "there is a `desk` query".
 *
 * All the real work lives in the surface-agnostic {@link SideDesk}; this is its panel shell.
 */
const props = defineProps<{ channelId: number }>()
// Docs' "Jump to message" — the panel has no timeline of its own, so it passes the ask on.
const emit = defineEmits<{ jump: [messageId: number] }>()
const route = useRoute()

// Draggable, remembered width (its left border carries the handle).
const { width: panelWidth, startResize } = useResizable('side-desk', 420, { min: 320, max: 760 })

const activeApp = computed<SideDeskAppId>(() => {
  const s = route.query.desk
  return s === 'notes' || s === 'docs' || s === 'board' ? s : 'canvas'
})

function setApp(app: SideDeskAppId) {
  const q: Record<string, string> = {}
  for (const [k, v] of Object.entries(route.query)) if (typeof v === 'string') q[k] = v
  q.desk = app
  navigateTo({ path: route.path, query: q })
}

function close() {
  const q: Record<string, string> = {}
  for (const [k, v] of Object.entries(route.query)) if (typeof v === 'string' && k !== 'desk') q[k] = v
  navigateTo({ path: route.path, query: q })
}
</script>

<template>
  <aside class="relative flex shrink-0 flex-col border-l" :style="{ width: `${panelWidth}px` }">
    <ResizeHandle edge="left" @resize="startResize" />
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex items-center gap-2 font-semibold">
        <LayoutPanelLeft class="h-4 w-4 text-muted-foreground" /> Side Desk
      </div>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <SideDesk
      :key="channelId"
      :base-path="`/api/channels/${channelId}`"
      :stream-name="`channel.${channelId}`"
      :can-edit="true"
      :active-app="activeApp"
      @update:active-app="setApp"
      @jump="emit('jump', $event)"
    />
  </aside>
</template>
