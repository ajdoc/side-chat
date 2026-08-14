<script setup lang="ts">
import { Hash, Info, LayoutList, LayoutPanelLeft, Map as MapIcon, MessagesSquare, Volume2 } from 'lucide-vue-next'
import { useLocalStorage } from '@vueuse/core'
import { Button } from '~/components/ui/button'
import { deskApp } from '~/composables/useDeskApps'

definePageMeta({ middleware: 'auth', layout: 'app' })

/**
 * A channel in a server.
 *
 * Almost nothing left here. The timeline, composer, threads, pins, reactions, read
 * receipts and typing all moved into ChannelView — which the DM/group page now uses too,
 * because a chat is a channel. What's left on this page is only what a *server* channel
 * has that a chat doesn't: a `#`, a Threads button, and whatever sits above the timeline.
 *
 * That last slot is the whole story of the Side Space. A voice channel puts a call in it; a
 * Side Space puts a walkable room in it. Both are the same shape of thing — something on top
 * of a timeline that is completely unaware of it — which is why a feature as large as a
 * Gather-style room adds three lines to this page.
 */
const route = useRoute()
const { findChannel, resolveDiscussion, server } = useServer()

const channelId = computed(() => Number(route.params.channelId))
const channel = computed(() => findChannel(channelId.value))

/**
 * A container is not a place you can stand.
 *
 * It holds discussions and no timeline of its own, so a URL naming one resolves to the
 * discussion inside it and replaces the entry — landing on a channel means landing in its
 * General, and the back button shouldn't have to step through a page that draws nothing.
 *
 * Nothing else in the app has to know about this: every link into a channel, from the sidebar
 * to search to a dropped pane, may name either level and arrives in the right place.
 */
watch([channel, () => route.params.channelId], () => {
  const target = resolveDiscussion(channel.value)
  if (!target || target.id === channelId.value) return

  navigateTo(`/servers/${route.params.serverId}/channels/${target.id}`, { replace: true })
}, { immediate: true })

// The container this discussion hangs under — the `#channel` half of the header's breadcrumb.
const parent = computed(() => findChannel(channel.value?.parent_id ?? null))

/**
 * What the header calls this place.
 *
 * A channel with one discussion is called by the channel's name, because that is what it is:
 * "General" is an implementation detail nobody asked for and the person reading is simply in
 * #announcements. Once there are two, the discussion's own name takes the title and the channel
 * moves up into the breadcrumb above it.
 */
const hasSiblings = computed(() => (parent.value?.discussions?.length ?? 0) > 1)
const title = computed(() => (hasSiblings.value ? channel.value?.name : parent.value?.name) ?? channel.value?.name ?? '')
const isVoice = computed(() => channel.value?.type === 'voice')
// A Side Space is the same room everywhere now — web, desktop and phone. It has no keyboard
// requirement left: you walk by tapping and dragging the floor, and the stage folds its
// toolbar and its dock down to a phone's width itself. See SideSpaceStage.
const { narrow } = useNavDrawer()
const isSpace = computed(() => channel.value?.type === 'space')
/**
 * Anybody who belongs here may build here.
 *
 * A Side Space is a room a group makes together, so the walls are open to the same people the
 * furniture always was — see UpdateSideSpaceMapRequest, which is the gate that actually holds.
 * Being *in* the server is the whole of the requirement, and reaching this page already means
 * that, so this is only false while the server itself is still loading.
 */
const canEditMap = computed(() => !!server.value)

/**
 * Whether the room has the window to itself, with the conversation folded away.
 *
 * Lives on the page because it's the page owning both halves — the stage toggles it, and
 * ChannelView acts on it.
 *
 * Hidden by default, and remembered. A Side Space is somewhere you go to *be*, not to read: the
 * room wants the window, and the chat is what you turn to between conversations. Anyone who
 * disagrees flips it once and it stays flipped, which is why this is in localStorage rather
 * than a plain ref — the default should be an opinion, not a decision you re-make on every
 * visit.
 */
const chatHidden = useLocalStorage('side-space:chat-hidden', true)

/**
 * An app channel — the channel's body is an application rather than a timeline.
 *
 * Three lines again, and for exactly the reason in this file's header comment: the app goes in
 * the same slot the Side Space's room and the voice channel's call go in, and everything below
 * that slot stays unaware of it.
 */
const isApp = computed(() => channel.value?.type === 'app')

/**
 * Whether the app has the window to itself.
 *
 * A second preference rather than sharing the Side Space's: they're different rooms with
 * different habits. Somebody who keeps chat open beside a walkable room may well want it folded
 * away behind a tracker, and one shared key would make each visit re-litigate the other's
 * choice.
 */
const appChatHidden = useLocalStorage('app-channel:chat-hidden', true)

/** The icon an app channel wears in the header — its app's own, from the registry. */
const appIcon = computed(() => (channel.value?.app_id ? deskApp(channel.value.app_id)?.icon : undefined))

function openThreadsList() {
  // Open the channel's Threads list beside anything already up (a side chat stays put),
  // clearing a channel thread that was in view and the full-column Info / Side Desk.
  navigateTo({
    path: route.path,
    query: mergeQuery(route.query, { threads: '1', thread: null, from: null, info: null, desk: null }),
  })
}
function openInfo() {
  navigateTo({ path: route.path, query: { info: '1' } })
}
function openDesk() {
  navigateTo({ path: route.path, query: { desk: 'canvas' } })
}
</script>

<template>
  <ChannelView
    v-if="channel"
    :key="channel.id"
    :channel="channel"
    :title="title"
    :prefix="isVoice || isSpace || isApp ? '' : '#'"
    :collapse-timeline="(isSpace && chatHidden) || (isApp && appChatHidden)"
  >
    <template v-if="parent" #breadcrumb>
      <DiscussionPicker :parent="parent" :current="channel" />
    </template>

    <template #icon>
      <!-- An app channel wears its app's icon, so the header says which app it is without
           spending the title on it. -->
      <component :is="appIcon" v-if="isApp && appIcon" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <MapIcon v-else-if="isSpace" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <Volume2 v-else-if="isVoice" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <Hash v-else class="h-5 w-5 shrink-0 text-muted-foreground" />
    </template>

    <template #actions>
      <!-- Reading order: Side Chats first — it's the app's signature surface and the branded
           button, so it leads the row — then the app you reach for, the rest of the catalogue,
           then this channel's own surfaces. On a phone they all collapse to their icons: six labelled buttons plus a title
           do not fit across 390px, and the labels are the part you can do without. What still
           overflows scrolls sideways inside the strip (see ChannelView's header). -->
      <!-- Only once a channel holds more than one conversation; before that there is no list to
           see. The picker's menu carries the same link for anyone already up there. -->
      <Button
        v-if="hasSiblings"
        variant="ghost"
        size="sm"
        class="gap-2 text-muted-foreground"
        :class="narrow && 'px-2'"
        title="All discussions"
        @click="navigateTo(`/servers/${route.params.serverId}/discussions/${parent!.id}`)"
      >
        <LayoutList class="h-4 w-4" /> <span v-if="!narrow">Discussions</span>
      </Button>
      <SideChatsButton :channel-id="channel.id" />
      <FavoriteAppButton :channel-id="channel.id" />
      <AppsButton :channel-id="channel.id" />
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" :class="narrow && 'px-2'" title="Threads" @click="openThreadsList">
        <MessagesSquare class="h-4 w-4" /> <span v-if="!narrow">Threads</span>
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" :class="narrow && 'px-2'" title="Side Desk" @click="openDesk">
        <LayoutPanelLeft class="h-4 w-4" /> <span v-if="!narrow">Side Desk</span>
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" :class="narrow && 'px-2'" title="Info" @click="openInfo">
        <Info class="h-4 w-4" /> <span v-if="!narrow">Info</span>
      </Button>
    </template>

    <!-- Text-in-voice: the call sits on top of the very same timeline every other channel
         has, and everything below it is unaware it's in a voice channel. A Side Space's room
         takes the identical slot — hence chat, threads, side chats, Info and the Side Desk all
         working inside a walkable room without a line of their own. -->
    <template v-if="isSpace" #call>
      <SideSpaceStage v-model:chat-hidden="chatHidden" :channel="channel" :can-edit="canEditMap" />
    </template>
    <!-- An app channel takes the identical slot — which is the whole of why the timeline,
         threads, side chats, search, reads and encryption all work inside one without a line
         of their own. -->
    <template v-else-if="isApp" #call>
      <AppChannel v-model:chat-hidden="appChatHidden" :channel="channel" :can-edit="!!server" />
    </template>
    <template v-else-if="isVoice" #call>
      <VoiceChannel :channel="channel" />
    </template>
  </ChannelView>
</template>
