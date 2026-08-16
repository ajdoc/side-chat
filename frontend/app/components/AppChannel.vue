<script setup lang="ts">
import { Download } from 'lucide-vue-next'
import type { Channel } from '~/types'
import { deskApp, isWidgetApp } from '~/composables/useDeskApps'

/**
 * The body of an app channel.
 *
 * A dispatcher and nothing more: look up `app_id` in the registry and render the component the
 * Side Desk already renders for it, full-bleed instead of in a tab. That's the whole reason an
 * app channel was cheap — every app it can be already existed, already spoke the base-path /
 * stream contract, and already synced through {@link useSurfaceStore}. A tracker open as a
 * channel and the same tracker in a floating window are one tracker.
 *
 * Sits in ChannelView's `#call` slot, the same slot a Side Space's map uses, so the timeline
 * underneath keeps working — mentions, search, threads, reads and encryption never learn that
 * anything is on top of them.
 */
const props = defineProps<{
  channel: Channel
  canEdit: boolean
}>()

/** Mirrors the Side Space's toggle: the app owns the window, chat is what you turn to. */
const chatHidden = defineModel<boolean>('chatHidden', { required: true })

/**
 * The `/api` prefix is part of the base path, not something the fetch layer adds.
 *
 * `useApi()`'s `baseURL` is the *host* (`http://localhost:8002`), so every surface app's base
 * path carries the `/api` itself — see how SideDeskPanel builds it. Getting this wrong is
 * quiet: the request goes out to the host with no `/api`, gets no CORS response, and surfaces
 * as "Failed to fetch" rather than as a 404.
 */
const basePath = computed(() => `/api/channels/${props.channel.id}`)
const streamName = computed(() => `channel.${props.channel.id}`)

const app = computed(() => (props.channel.app_id ? deskApp(props.channel.app_id) : undefined))

/**
 * "Import from another channel", offered on every app channel.
 *
 * Here rather than inside each app for the same reason AppChannel is a dispatcher at all: an
 * import means one thing everywhere, the server decides per app whether there's anything to
 * bring, and an app that can't be imported simply says so when the dialog opens. Adding an
 * importer server-side lights this up with no client change.
 */
const importing = ref(false)
</script>

<template>
  <!--
    `flex-1` with `min-h-0`, and the timeline collapsed by the page: an app channel is somewhere
    you work, so the app takes the window and the conversation folds away — the same bargain a
    Side Space strikes with its room.
  -->
  <div v-if="app" class="relative flex min-h-0 flex-1 flex-col border-b">
    <!-- The way back to the conversation — the same pill a Side Space uses, so "show chat"
         is in one place whichever kind of channel has taken the window. -->
    <ChatTogglePill v-model="chatHidden" />

    <AppImportDialog
      v-model:open="importing"
      :base-path="basePath"
      :label="app.label"
    />

    <!-- Sits beside the chat pill, out of the app's own chrome: every app channel has it, and
         no app should have to make room for it in its own header. -->
    <button
      v-if="canEdit"
      type="button"
      class="absolute right-2 top-2 z-20 flex items-center gap-1 rounded-md border bg-background/80 px-2 py-1 text-xs text-muted-foreground backdrop-blur transition-colors hover:bg-muted hover:text-foreground"
      title="Import this app's content from another channel"
      @click="importing = true"
    >
      <Download class="h-3.5 w-3.5" /> Import
    </button>

    <TrackerApp
      v-if="channel.app_id === 'tracker'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :channel-id="channel.id"
    />

    <AppPollsApp
      v-else-if="channel.app_id === 'polls'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
    />

    <StickerWallApp
      v-else-if="channel.app_id === 'stickers'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
    />

    <SideDeskCanvas
      v-else-if="channel.app_id === 'canvas'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
    />

    <Whiteboard
      v-else-if="channel.app_id === 'board'"
      :base-path="`${basePath}/whiteboard`"
      :stream-name="streamName"
      :can-draw="canEdit"
    />

    <SideDeskNotes
      v-else-if="channel.app_id === 'notes'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :channel-id="channel.id"
    />

    <SideDeskCalendar
      v-else-if="channel.app_id === 'calendar'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
    />

    <SideDeskDocs
      v-else-if="channel.app_id === 'docs'"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
    />

    <!-- The kanban board gets the window rather than going through the widget card: it has
         columns to reshape and a discussion panel per card, neither of which fits a card. -->
    <KanbanBoard
      v-else-if="channel.app_id === 'kanban'"
      :channel-id="channel.id"
      variant="full"
      :can-edit="canEdit"
    />

    <!-- Everything left is a widget promoted to a channel: one branch for all of them, because
         a widget channel is only ever "this channel's widget of that type, full window". -->
    <SideDeskWidgetApp
      v-else-if="channel.app_id && isWidgetApp(channel.app_id)"
      :type="channel.app_id as any"
      :channel-id="channel.id"
    />
  </div>

  <!--
    An app id this client has never heard of.

    Reached by a client a release behind the server, which is a real state rather than a
    hypothetical one — a channel created with an app added yesterday. Says so plainly instead of
    rendering an empty panel that reads as a broken channel, and the timeline underneath is
    untouched, so the channel is still usable as a conversation.
  -->
  <div v-else class="shrink-0 border-b px-4 py-6 text-center text-sm text-muted-foreground">
    This channel runs an app this version of the app doesn’t know about.
    <span class="block text-xs">Update to open it — the conversation below still works.</span>
  </div>
</template>
