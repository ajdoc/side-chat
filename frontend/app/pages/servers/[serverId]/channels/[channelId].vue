<script setup lang="ts">
import { Hash, Info, LayoutPanelLeft, Map as MapIcon, MessagesSquare, Volume2 } from 'lucide-vue-next'
import { useLocalStorage } from '@vueuse/core'
import { Button } from '~/components/ui/button'

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
const { channels, server } = useServer()

const channelId = computed(() => Number(route.params.channelId))
const channel = computed(() => channels.value.find(c => c.id === channelId.value) ?? null)
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
    :title="channel.name"
    :prefix="isVoice || isSpace ? '' : '#'"
    :collapse-timeline="isSpace && chatHidden"
  >
    <template #icon>
      <MapIcon v-if="isSpace" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <Volume2 v-else-if="isVoice" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <Hash v-else class="h-5 w-5 shrink-0 text-muted-foreground" />
    </template>

    <template #actions>
      <!-- On a phone these collapse to their icons: three labelled buttons plus a title do
           not fit across 390px, and the labels are the part you can do without. -->
      <SideChatsButton :channel-id="channel.id" />
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
    <template v-else-if="isVoice" #call>
      <VoiceChannel :channel="channel" />
    </template>
  </ChannelView>
</template>
