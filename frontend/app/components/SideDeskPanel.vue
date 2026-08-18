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
// The URL on the page you're on, a docked pane's own state inside one. See useSurfaceRoute.
const surface = useSurfaceRoute()

// Draggable, remembered width (its left border carries the handle) — beside the timeline on a
// wide window, and irrelevant on a narrow one, where the desk covers the window instead.
const { width: panelWidth, startResize } = useResizable('side-desk', 420, { min: 320, max: 760 })
const { narrow } = useNavDrawer()

/**
 * The active app, from `?desk=`.
 *
 * Any registry id is accepted now that the strip is open-ended — validating against the four
 * old names here would silently bounce every widget app back to the canvas. An id this surface
 * doesn't actually have on its strip is handled one level down, in SideDesk, which is where the
 * strip is known.
 */
const activeApp = computed<SideDeskAppId>(() => {
  const s = surface.query.value.desk
  return s && deskApp(s as SideDeskAppId) ? (s as SideDeskAppId) : 'canvas'
})

function setApp(app: SideDeskAppId) {
  surface.patch({ desk: app })
}

/**
 * "Open the calendar *and* start something" — how a room's Schedule button reaches the editor.
 *
 * Read here and passed down as a prop rather than read inside the calendar, because the calendar
 * is surface-agnostic: it also renders in a floating window, on the canvas and as a whole app
 * channel, none of which should react to this page's query. The panel is the one placement that
 * *is* the URL, so it's the one that translates it.
 *
 * `meet=1` means "compose a meeting in this room", which is only coherent when the surface is a
 * room — the calendar resolves the room itself, since on a room's own desk that is this channel.
 * `event=<id>` opens an existing entry, which is what the banner's title does.
 */
const compose = computed(() => ({
  meeting: surface.query.value.meet === '1',
  eventId: Number(surface.query.value.event) || null,
}))

/**
 * Consumed once. Left in the URL, a reload or a second visit to the Calendar tab would reopen
 * the editor over whatever somebody had since typed.
 */
function composed() {
  surface.patch({ meet: null, event: null })
}

function close() {
  surface.patch({ desk: null })
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
      :channel-id="channelId"
      :can-edit="true"
      :active-app="activeApp"
      :compose="compose"
      @update:active-app="setApp"
      @composed="composed"
      @jump="emit('jump', $event)"
    />
  </aside>
</template>
