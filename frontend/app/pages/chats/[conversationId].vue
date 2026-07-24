<script setup lang="ts">
import { Info, LayoutPanelLeft, LogOut, MessagesSquare, Pencil, Phone, UserPlus, Users } from 'lucide-vue-next'
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
const { conversation, openConversation, closeConversation, clearUnread } = useConversation()
const { participantsIn } = useVoiceRoster()
const { channelId: callChannelId } = useVoice()
const { start } = useCall()

const conversationId = computed(() => Number(route.params.conversationId))

const title = computed(() =>
  conversation.value ? conversationTitle(conversation.value, user.value) : '',
)
const isGroup = computed(() => conversation.value?.type === 'group')
const isOwner = computed(() => conversation.value?.owner_id === user.value?.id)

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
    name: title.value,
    type: 'text' as const,
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
  // clearing a channel thread that was in view and the full-column Info / Side Space.
  navigateTo({
    path: route.path,
    query: mergeQuery(route.query, { threads: '1', thread: null, from: null, info: null, space: null }),
  })
}
function openInfo() {
  navigateTo({ path: route.path, query: { info: '1' } })
}
function openSpace() {
  navigateTo({ path: route.path, query: { space: 'canvas' } })
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
</script>

<template>
  <ChannelView
    v-if="conversation && channel"
    :key="conversation.id"
    :channel="channel"
    :title="title"
    :subtitle="subtitle"
    :float-icon="isGroup ? 'group' : 'dm'"
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
        :class="callBusy ? '' : 'text-muted-foreground'"
        :title="callBusy ? 'Join the call' : `Call ${title}`"
        @click="onCall"
      >
        <Phone class="h-4 w-4" /> {{ callBusy ? 'Join call' : 'Call' }}
      </Button>

      <SideChatsButton v-if="channel" :channel-id="channel.id" />
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openThreadsList">
        <MessagesSquare class="h-4 w-4" /> Threads
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openSpace">
        <LayoutPanelLeft class="h-4 w-4" /> Side Space
      </Button>
      <Button variant="ghost" size="sm" class="gap-2 text-muted-foreground" @click="openInfo">
        <Info class="h-4 w-4" /> Info
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
      <VoiceChannel :channel="channel" quiet-when-empty join-label="Join call" />
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
</template>
