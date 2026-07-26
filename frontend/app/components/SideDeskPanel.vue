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

// Draggable, remembered width (its left border carries the handle) — beside the timeline on a
// wide window, and irrelevant on a narrow one, where the desk covers the window instead.
const { width: panelWidth, startResize } = useResizable('side-desk', 420, { min: 320, max: 760 })
const { narrow } = useNavDrawer()

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
  <!-- On a phone the desk takes the window, exactly as the thread, side-chat and info panels
       already do. It's a full-column surface, and 420px of it beside a 390px timeline meant the
       board arrived as a sliver — which is most of why it read as broken on mobile. -->
  <aside
    class="flex flex-col border-l bg-background"
    :class="narrow ? 'safe-inset fixed inset-0 z-50 w-full' : 'relative shrink-0'"
    :style="narrow ? undefined : { width: `${panelWidth}px` }"
  >
    <ResizeHandle v-if="!narrow" edge="left" @resize="startResize" />
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
