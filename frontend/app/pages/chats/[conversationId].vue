<script setup lang="ts">
// The Side Desk's apps are refused for a guest — see useGuest.
import { Info, LayoutPanelLeft, LogOut, Map as MapIcon, MessagesSquare, Pencil, Phone, UserPlus, Users } from 'lucide-vue-next'
import type { ChannelType } from '~/types'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

definePageMeta({ middleware: 'auth', layout: 'app' })

/**
 * A DM or a group chat.
 *
 * Nearly as thin as the server-channel page, and for the same reason: a conversation owns
 * a channel, so the timeline, composer, threads, pins, reactions, read receipts and typing
 * are all ChannelView, unchanged and unaware. What's here is the handful of things a chat
 * has that #general doesn't — a person's face instead of a `#`, a call button that *rings*
 * rather than a room you wander into, and the group's own menu.
 */
const route = useRoute()
const { user } = useAuth()
const { conversation, openConversation, refreshConversation, closeConversation, clearUnread } = useConversation()
const { participantsIn } = useVoiceRoster()
const { channelId: callChannelId } = useVoice()
const { start } = useCall()
// Narrow headers show icons without labels rather than a row that doesn't fit.
const { narrow } = useNavDrawer()

const conversationId = computed(() => Number(route.params.conversationId))

const title = computed(() =>
  conversation.value ? conversationTitle(conversation.value, user.value) : '',
)
const isGroup = computed(() => conversation.value?.type === 'group')
const isOwner = computed(() => conversation.value?.owner_id === user.value?.id)

/**
 * A Side Space chat folds its timeline away, like a server's Side Space does — the room is what
 * you came for and the conversation is what you turn to. Local rather than in the URL because a
 * chat has no other panels competing for that query.
 */
const spaceChatHidden = ref(true)

/** The "is this call a voice bar or a Side Space" dialog — see ChannelTypeDialog. */
const showRoomType = ref(false)

/** A group says who's in it; a DM's subtitle would just be the title again. */
const subtitle = computed(() => {
  if (!conversation.value || !isGroup.value) return undefined

  const others = otherMembers(conversation.value, user.value)
  const names = others.slice(0, 3).map(m => m.name).join(', ')

  return others.length > 3 ? `${names} and ${others.length - 3} more` : names
})

/** The channel behind the chat — what every message and call endpoint is addressed by. */
const channel = computed(() => {
  const c = conversation.value
  if (!c) return null

  return {
    id: c.channel_id,
    server_id: null,
    conversation_id: c.id,
    // A conversation's channel is never a discussion — stated rather than omitted, so this
    // stands up as a `Channel` everywhere it's passed.
    parent_id: null,
    name: title.value,
    // Its *real* kind, not an assumption. A group chat whose call is a Side Space renders the
    // map below rather than the voice bar — see the conversation resource.
    type: (c.channel_type ?? 'text') as ChannelType,
    position: 0,
    created_at: c.created_at,
  }
})

const inThisCall = computed(() => callChannelId.value === conversation.value?.channel_id)
const callBusy = computed(() =>
  !!conversation.value && participantsIn(conversation.value.channel_id).length > 0,
)

async function onCall() {
  if (!conversation.value || inThisCall.value) return
  await start(conversation.value)
}

function openThreadsList() {
  // Open the chat's Threads list beside anything already up (a side chat stays put),
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

// --- group actions ---
const showAddMembers = ref(false)
const showRename = ref(false)
const showLeave = ref(false)

let openedId: number | null = null

async function sync() {
  const id = conversationId.value
  if (openedId === id) return

  if (openedId) closeConversation(openedId)
  openedId = id
  await openConversation(id)
}

onMounted(sync)
watch(conversationId, sync)
onBeforeUnmount(() => {
  if (openedId) closeConversation(openedId)
})

useHead({ title: computed(() => title.value) })

/** A guest has no Side Desk: every one of its endpoints refuses them. See useGuest. */
const { isGuest } = useGuest()
</script>

<template>
  <ChannelView
    v-if="conversation && channel"
    :key="conversation.id"
    :channel="channel"
    :title="title"
    :subtitle="subtitle"
    :float-icon="isGroup ? 'group' : 'dm'"
    :collapse-timeline="channel.type === 'space' && spaceChatHidden"
    @read="clearUnread(conversation.id)"
  >
    <template #icon>
      <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-[11px] font-semibold text-secondary-foreground">
        <Users v-if="isGroup" class="h-4 w-4" />
        <img
          v-else-if="conversationAvatar(conversation, user)"
          :src="conversationAvatar(conversation, user)!"
          :alt="title"
          class="h-full w-full rounded-full object-cover"
        >
        <span v-else>{{ initialsOf(title) }}</span>
        <PresenceDot
          v-if="!isGroup && otherMembers(conversation, user)[0]"
          :user-id="otherMembers(conversation, user)[0]!.id"
          class="absolute -bottom-0.5 -right-0.5 h-3 w-3"
        />
      </span>
    </template>

    <template #actions>
      <!--
        A call button, not a voice channel.

        This is the difference between a chat and a server, in one control. A voice channel
        is a room in the sidebar that you walk into and nobody is told. Pressing this makes
        somebody's phone ring — so it says "Call", it says "Join" once there's already one
        happening, and it goes quiet once you're in it.
      -->
      <Button
        v-if="!inThisCall"
        size="sm"
        :variant="callBusy ? 'default' : 'ghost'"
        class="gap-2"
        :class="[callBusy ? '' : 'text-muted-foreground', narrow && !callBusy ? 'px-2' : '']"
        :title="callBusy ? 'Join the call' : `Call ${title}`"
        @click="onCall"
      >
        <Phone class="h-4 w-4" />
        <span v-if="!narrow || callBusy">{{ callBusy ? 'Join call' : 'Call' }}</span>
      </Button>

      <!-- Same collapse the server-channel header does: on a phone these are icons, because
           four labels plus a title don't fit across 390px and the labels are the part you can
           do without. What still overflows scrolls sideways — see ChannelView's header. -->
      <!--
        Withheld from a guest, all of it: side chats, the app launcher, the favourite-app
        shortcut, threads, the Side Desk and the info panel every one read endpoints
        ConfineGuests refuses them. A guest is here for one call — see useGuest.
      -->
      <template v-if="!isGuest && channel">
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

      <!--
        A DM has no settings menu to hang this off, and its call can be a Side Space for exactly
        the same reason a group's can — a conversation's channel is a channel. Either person may
        convert it (there's nobody else it could belong to in a room of two), which is the rule
        the API holds too.
      -->
      <Button
        v-if="!isGroup && !isGuest && channel"
        variant="ghost"
        size="icon"
        class="text-muted-foreground"
        title="Call style — voice or a Side Space"
        @click="showRoomType = true"
      >
        <MapIcon class="h-4 w-4" />
      </Button>

      <DropdownMenu v-if="isGroup">
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon" class="text-muted-foreground" title="Group settings">
            <Users class="h-4 w-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-48">
          <DropdownMenuItem @select="showAddMembers = true">
            <UserPlus class="mr-2 h-4 w-4" /> Add people
          </DropdownMenuItem>
          <DropdownMenuItem v-if="isOwner" @select="showRename = true">
            <Pencil class="mr-2 h-4 w-4" /> Rename group
          </DropdownMenuItem>
          <!--
            What this chat's *call* is: a voice bar, or a Side Space you walk around in. The same
            conversion a server channel offers — a chat's channel is a channel — and the reason a
            Side Space meeting can be a group chat at all.
          -->
          <DropdownMenuItem v-if="isOwner && channel" @select="showRoomType = true">
            <MapIcon class="mr-2 h-4 w-4" /> Call style
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem class="text-destructive focus:text-destructive" @select="showLeave = true">
            <LogOut class="mr-2 h-4 w-4" /> Leave group
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </template>

    <!-- The call stage, but quiet: a chat with nobody calling shows the conversation, not
         an empty room. Same component a voice channel uses — same mesh, same signalling. -->
    <template #call>
      <!--
        A chat whose channel is a Side Space *is* a room: the map takes the window and the
        conversation folds away, exactly as it does for a server's Side Space. Anything else gets
        the voice bar, which is what a chat's call has always been.
      -->
      <SideSpaceStage
        v-if="channel?.type === 'space'"
        v-model:chat-hidden="spaceChatHidden"
        :channel="channel"
        :can-edit="isOwner"
      />
      <VoiceChannel v-else :channel="channel" quiet-when-empty join-label="Join call" />
    </template>

    <template #empty>
      This is the beginning of your conversation with
      <span class="font-medium">{{ title }}</span>. Say hello 👋
    </template>
  </ChannelView>

  <GroupSettingsDialogs
    v-if="conversation && isGroup"
    v-model:add-members="showAddMembers"
    v-model:rename="showRename"
    v-model:leave="showLeave"
    :conversation="conversation"
  />

  <!-- Converting the chat's channel: voice bar ⇄ Side Space. Reloads the conversation after,
       because the chat's own copy of the channel type is what decides which room renders. -->
  <ChannelTypeDialog
    v-if="showRoomType && channel"
    :channel="channel"
    @close="showRoomType = false; refreshConversation(conversationId)"
  />
</template>
