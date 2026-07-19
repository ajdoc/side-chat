<script setup lang="ts">
import {
  Check, ChevronDown, ChevronRight, Copy, DoorOpen, Hash, LogOut, MessageSquarePlus, MicOff,
  Monitor, Moon, Pencil, Phone, Plus, ScreenShare, Sun, Trash2, User, UserPlus, Users, Volume2,
} from 'lucide-vue-next'
import { useLocalStorage } from '@vueuse/core'
import type { Channel, Conversation, Server, ThemeColor, ThemeMode } from '~/types'
import { useDesktopNotifications } from '~/composables/useDesktopNotifications'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'
import { Input } from '~/components/ui/input'

/**
 * One sidebar, two kinds of place.
 *
 * **Chats** are DMs and group chats: flat, sorted by whoever spoke last, and each one is a
 * single conversation. **Servers** are shared spaces with channels inside them, so they
 * nest — and only the server you're actually in is expanded, because a sidebar showing
 * every channel of every server is a sidebar you have to search rather than read.
 *
 * Both are rendered from one flat list of rows into one virtual scroller. That's not
 * incidental: the two sections share a scrollbar, so they cannot each own one, and
 * flattening is what lets a chat and a channel and a server sit in the same scroller
 * without any of them knowing about the others.
 */
const route = useRoute()
const { servers, hasMore: hasMoreServers, fetchServers, loadMore: loadMoreServers, renameServer, deleteServer, leaveServer } = useServers()
const { server, channels, openServer, loadMoreChannels, renameChannel, deleteChannel, patchServer } = useServer()
const { conversations, hasMore: hasMoreChats, fetchConversations, loadMore: loadMoreChats } = useConversations()
const { user, logout } = useAuth()
const { hasDraft } = useDrafts()
const { mode, color, setMode, setColor } = useTheme()
const { participantsIn } = useVoiceRoster()
const { expandedIds, isExpanded, isLoading, expand: expandServer, toggle: toggleServer, loadChannels, cache: cacheChannels, channelsFor } = useSidebarChannels()
const userStream = useUserStream()
const { ensurePermission: ensureNotifyPermission } = useDesktopNotifications()

const modes: { value: ThemeMode, label: string, icon: any }[] = [
  { value: 'light', label: 'Light', icon: Sun },
  { value: 'dark', label: 'Dark', icon: Moon },
  { value: 'system', label: 'System', icon: Monitor },
]
// Swatches render themselves from the accent registry (see tailwind.css) — the
// label is all this list has to carry.
const colors: { value: ThemeColor, label: string }[] = [
  { value: 'slate', label: 'Slate' },
  { value: 'blue', label: 'Blue' },
  { value: 'violet', label: 'Violet' },
  { value: 'teal', label: 'Teal' },
  { value: 'green', label: 'Green' },
  { value: 'amber', label: 'Amber' },
  { value: 'red', label: 'Red' },
  { value: 'rose', label: 'Rose' },
]

const activeServerId = computed(() => Number(route.params.serverId) || null)
const activeChannelId = computed(() => Number(route.params.channelId) || null)
const activeConversationId = computed(() => Number(route.params.conversationId) || null)

// Browser tab: "Side Chat - <server>" (a chat page sets its own).
useHead({ title: computed(() => server.value?.name ?? '') })

// Persisted so a section you folded away stays folded across reloads, not just across
// navigations. useLocalStorage is SSR-safe (yields the default on the server).
const chatsOpen = useLocalStorage('sidebar:chatsOpen', true)
const serversOpen = useLocalStorage('sidebar:serversOpen', true)
const showNewChat = ref(false)

/**
 * Unfolding, decoupled from where you're standing.
 *
 * Which servers show their channels is a set you control (the chevrons) and that persists —
 * *not* a function of the route. Selecting another server therefore leaves the ones you'd
 * already opened exactly as they were, and only the one you're viewing draws from useServer's
 * live channels; the rest draw from the sidebar cache. Two threads keep that honest:
 *
 *  - the server you navigate to auto-unfolds (and stays unfolded until you fold it yourself);
 *  - its live channels are mirrored into the cache, so the moment you step off it onto the
 *    next server, its tree is already there to draw instead of blinking out.
 */
watch(activeServerId, (id) => {
  if (id) expandServer(id)
}, { immediate: true })

// Mirror the active server's live channels (unread, renames, new/removed) into the cache,
// so an unfolded server you've since left keeps an up-to-date-as-of-leaving tree.
watch([() => server.value?.id, channels], () => {
  if (server.value) cacheChannels(server.value.id, channels.value)
}, { deep: true })

// Restored-from-storage or freshly-opened servers that aren't the active one need their
// channels fetched into the cache the first time they're shown.
watch([expandedIds, servers, activeServerId], () => {
  const known = new Set(servers.value.map(s => s.id))
  for (const id of expandedIds.value) {
    if (known.has(id) && id !== activeServerId.value && !channelsFor(id).length) loadChannels(id)
  }
}, { immediate: true })

// Live count, kept in sync by the join-request Reverb subscription opened in openServer().
const { requests: joinRequests } = useJoinRequests()
const pendingCount = computed(() => joinRequests.value.length)

/**
 * The sidebar, as one flat list of rows.
 *
 * Voice rows carry their occupants and server rows carry their channels, because the
 * scroller has to be told what decides a row's height — a voice channel grows a face for
 * every person in it, and it can't measure what it doesn't know about.
 */
const rows = computed(() => {
  const list: any[] = []

  // --- Chats ---
  list.push({ id: 'h-chats', kind: 'section', label: 'Chats', section: 'chats', open: chatsOpen.value })

  if (chatsOpen.value) {
    if (!conversations.value.length) {
      list.push({ id: 'e-chats', kind: 'empty', label: 'No chats yet.' })
    }
    for (const c of conversations.value) {
      list.push({ id: `chat-${c.id}`, kind: 'chat', conversation: c })
    }
    list.push({ id: 'new-chat', kind: 'new-chat' })
  }

  // --- Servers ---
  list.push({ id: 'h-servers', kind: 'section', label: 'Servers', section: 'servers', open: serversOpen.value })

  if (serversOpen.value) {
    if (!servers.value.length) {
      list.push({ id: 'e-servers', kind: 'empty', label: 'You’re not in any servers.' })
    }

    for (const s of servers.value) {
      const isActive = s.id === activeServerId.value
      const expanded = isExpanded(s.id)
      list.push({ id: `server-${s.id}`, kind: 'server', server: s, expanded, isActive })

      // Several servers can stand unfolded at once (see the watchers above). The one you're
      // viewing draws from useServer's live channels; every other unfolded server draws from
      // the sidebar cache, which is why switching servers doesn't fold the rest away.
      if (!expanded) continue

      // The active server draws from useServer's live channels; while it's mid-switch those
      // are briefly empty, so fall back to the cache (which still holds the last tree) rather
      // than flash "No channels yet". Every other unfolded server draws from the cache.
      const rowChannels = isActive && channels.value.length ? channels.value : channelsFor(s.id)

      if (!rowChannels.length) {
        // Tell "still loading" from "genuinely empty": the active server has settled once
        // useServer commits it (server.value.id === s.id); a cached one, once it's not fetching.
        const settled = isActive ? server.value?.id === s.id : !isLoading(s.id)
        list.push(settled
          ? { id: `e-channels-${s.id}`, kind: 'empty', indent: true, label: 'No channels yet.' }
          : { id: `l-channels-${s.id}`, kind: 'empty', indent: true, label: 'Loading channels…' })
      }

      const text = rowChannels.filter(c => c.type === 'text')
      const voice = rowChannels.filter(c => c.type === 'voice')

      // Inline rename/delete only on the active server: those edits flow through useServer,
      // which holds *its* channels, so a cached (non-active) server's tree couldn't be kept
      // in step with them. You manage a server's channels from inside it.
      const canEdit = isActive && s.is_owner
      for (const c of text) {
        list.push({ id: `c-${c.id}`, kind: 'channel', channel: c, voice: [], isOwner: canEdit })
      }
      for (const c of voice) {
        list.push({ id: `c-${c.id}`, kind: 'channel', channel: c, voice: participantsIn(c.id), isOwner: canEdit })
      }
      list.push({ id: `add-channel-${s.id}`, kind: 'add-channel', server: s })
    }

    list.push({ id: 'add-server', kind: 'add-server' })
  }

  return list
})

/** Both lists page at 200; whichever has more, load more of it. */
function onScrollEnd() {
  if (hasMoreChats.value) loadMoreChats()
  if (hasMoreServers.value) loadMoreServers()
  // Only the active server paginates its channels (useServer holds it); the other unfolded
  // servers are cached at their first page, which is the whole tree for any real sidebar.
  if (activeServerId.value) loadMoreChannels(activeServerId.value)
}

function chatTitle(conversation: Conversation) {
  return conversationTitle(conversation, user.value)
}

async function copyInvite() {
  if (!server.value) return
  try {
    await navigator.clipboard.writeText(server.value.invite_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // clipboard blocked — the user can still select the text manually
  }
}

async function syncServer() {
  const id = activeServerId.value
  if (id) await openServer(id)
}

// --- invite link ---
const showInvite = ref(false)
const copied = ref(false)

// --- renaming, leaving and deleting ---
// The destructive ones are irreversible and none is undoable, so each goes through a
// confirmation that names what is actually about to be destroyed.
//
// Note the shape: an `open` flag *and*, separately, the thing being acted on. They can't be
// the same ref. Driving the dialog off `channelToDelete` directly means closing the dialog
// nulls it — and the confirm button closes the dialog *before* our click handler runs, so
// the handler would find nothing to delete and silently do nothing.
const showLeave = ref(false)
const showDeleteServer = ref(false)
const showDeleteChannel = ref(false)
const showRenameServer = ref(false)
const showRenameChannel = ref(false)
const targetChannel = ref<Channel | null>(null)
const targetServer = ref<Server | null>(null)
const nameDraft = ref('')
const working = ref(false)
const actionError = ref('')

/** Where you go when the place you were standing no longer exists. */
async function afterServerGone() {
  await navigateTo(servers.value.length ? `/servers/${servers.value[0]!.id}` : '/chats')
}

/** Run a confirmed action: one place to hold the spinner, the error, and the close. */
async function confirm(open: Ref<boolean>, run: () => Promise<void>, fallback: string) {
  if (working.value) return
  working.value = true
  actionError.value = ''
  try {
    await run()
    open.value = false
  } catch (e: any) {
    // Stays open, so the message has somewhere to be read — the owner's "you can't leave
    // this, delete it instead" is the whole reason this isn't fire-and-forget.
    actionError.value = e?.data?.message ?? fallback
  } finally {
    working.value = false
  }
}

function askRenameServer(s: Server) {
  targetServer.value = s
  nameDraft.value = s.name
  actionError.value = ''
  showRenameServer.value = true
}

function askRenameChannel(channel: Channel) {
  targetChannel.value = channel
  nameDraft.value = channel.name
  actionError.value = ''
  showRenameChannel.value = true
}

function askDeleteChannel(channel: Channel) {
  targetChannel.value = channel
  actionError.value = ''
  showDeleteChannel.value = true
}

function askDeleteServer(s: Server) {
  targetServer.value = s
  actionError.value = ''
  showDeleteServer.value = true
}

function askLeaveServer(s: Server) {
  targetServer.value = s
  actionError.value = ''
  showLeave.value = true
}

const onRenameServer = () => confirm(showRenameServer, async () => {
  const name = nameDraft.value.trim()
  if (!name || !targetServer.value) return
  const updated = await renameServer(targetServer.value.id, name)
  // renameServer patches the list; the open server is a separate ref.
  patchServer(updated.id, updated)
}, 'Could not rename the server.')

const onRenameChannel = () => confirm(showRenameChannel, async () => {
  const name = nameDraft.value.trim()
  if (!name || !targetChannel.value) return
  await renameChannel(targetChannel.value.id, name)
}, 'Could not rename the channel.')

const onLeaveServer = () => confirm(showLeave, async () => {
  if (!targetServer.value) return
  await leaveServer(targetServer.value.id)
  if (activeServerId.value === targetServer.value.id) await afterServerGone()
}, 'Could not leave the server.')

const onDeleteServer = () => confirm(showDeleteServer, async () => {
  if (!targetServer.value) return
  await deleteServer(targetServer.value.id)
  if (activeServerId.value === targetServer.value.id) await afterServerGone()
}, 'Could not delete the server.')

const onDeleteChannel = () => confirm(showDeleteChannel, async () => {
  const channel = targetChannel.value
  if (!channel) return
  await deleteChannel(channel.id)
  // Standing in the channel you just deleted: step back out to the server.
  if (activeChannelId.value === channel.id) {
    await navigateTo(`/servers/${channel.server_id}`)
  }
}, 'Could not delete the channel.')

onMounted(async () => {
  // Your own stream first: it's the only subscription that outlives every navigation, and
  // it's what makes a DM appear, a badge move, and a phone ring. Everything else is scoped
  // to a place you happen to be standing.
  userStream.subscribe()
  // Ask once, so a mention can reach you while you're in another tab. Declined is fine —
  // the sidebar badge still does its job.
  ensureNotifyPermission()

  await Promise.all([fetchServers(), fetchConversations()])
  await syncServer()
})

watch(activeServerId, syncServer)
watch(() => user.value?.id, () => userStream.subscribe())
onBeforeUnmount(() => userStream.unsubscribe())
</script>

<template>
  <div class="flex h-screen text-foreground">
    <aside class="flex w-64 shrink-0 flex-col border-r bg-sidebar">
      <div class="flex h-12 shrink-0 items-center border-b px-4 font-semibold">
        Side Chat
      </div>

      <div class="min-h-0 flex-1">
        <ClientOnly>
          <DynamicScroller
            class="h-full"
            :items="rows"
            :min-item-size="34"
            key-field="id"
            @scroll-end="onScrollEnd"
          >
            <template #default="{ item, active }">
              <DynamicScrollerItem
                :item="item"
                :active="active"
                :size-dependencies="[item.kind, item.open, item.expanded, item.voice?.length]"
              >
                <!-- Section header: Chats / Servers -->
                <button
                  v-if="item.kind === 'section'"
                  type="button"
                  class="flex w-full items-center gap-1 px-2 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground transition hover:text-foreground"
                  @click="item.section === 'chats' ? (chatsOpen = !chatsOpen) : (serversOpen = !serversOpen)"
                >
                  <ChevronDown v-if="item.open" class="h-3.5 w-3.5 shrink-0" />
                  <ChevronRight v-else class="h-3.5 w-3.5 shrink-0" />
                  {{ item.label }}
                </button>

                <p
                  v-else-if="item.kind === 'empty'"
                  class="py-1 text-xs text-muted-foreground"
                  :class="item.indent ? 'pl-9 pr-3' : 'px-3'"
                >
                  {{ item.label }}
                </p>

                <!-- A DM or group chat. -->
                <NuxtLink
                  v-else-if="item.kind === 'chat'"
                  :to="`/chats/${item.conversation.id}`"
                  class="mx-2 flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted"
                  :class="item.conversation.id === activeConversationId
                    ? 'bg-muted font-medium text-foreground'
                    : item.conversation.unread_count
                      ? 'font-semibold text-foreground'
                      : 'text-muted-foreground'"
                >
                  <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                    <Users v-if="item.conversation.type === 'group'" class="h-3.5 w-3.5" />
                    <img
                      v-else-if="conversationAvatar(item.conversation, user)"
                      :src="conversationAvatar(item.conversation, user)"
                      :alt="chatTitle(item.conversation)"
                      class="h-full w-full rounded-full object-cover"
                    >
                    <span v-else>{{ initialsOf(chatTitle(item.conversation)) }}</span>
                  </span>

                  <span class="truncate">{{ chatTitle(item.conversation) }}</span>

                  <!-- Unsent text waiting in a chat you're not looking at (Viber-style). Hidden
                       on the open chat, where the composer already shows it. -->
                  <span
                    v-if="hasDraft(item.conversation.channel_id) && item.conversation.id !== activeConversationId"
                    class="ml-auto shrink-0 text-[10px] font-medium italic text-primary"
                    title="You have an unsent draft here"
                  >Draft</span>

                  <!-- A call happening in a chat you aren't looking at. Kept live by
                       CallStarted/CallEnded on your own stream — no roster needed. -->
                  <Phone
                    v-if="item.conversation.call_active"
                    class="ml-auto h-3.5 w-3.5 shrink-0 animate-pulse text-green-600 dark:text-green-400"
                    title="Call in progress"
                  />
                  <span
                    v-else-if="item.conversation.unread_count"
                    class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground"
                    :class="item.conversation.mention ? 'ring-2 ring-primary/30' : ''"
                    :title="item.conversation.mention ? 'You were mentioned' : `${item.conversation.unread_count} unread`"
                  ><span v-if="item.conversation.mention" aria-hidden="true">@</span>{{ item.conversation.unread_count > 99 ? '99+' : item.conversation.unread_count }}</span>
                </NuxtLink>

                <button
                  v-else-if="item.kind === 'new-chat'"
                  type="button"
                  class="mx-2 flex w-[calc(100%-1rem)] items-center gap-2 rounded px-2 py-1.5 text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground"
                  @click="showNewChat = true"
                >
                  <MessageSquarePlus class="h-4 w-4 shrink-0" />
                  New chat
                </button>

                <!-- A server. Its chevron folds it open/shut on its own; several can stand
                     open at once and selecting another leaves these alone. -->
                <div
                  v-else-if="item.kind === 'server'"
                  class="group/sv relative mx-2 flex items-center rounded hover:bg-muted"
                  :class="item.expanded ? 'font-semibold text-foreground' : 'text-muted-foreground'"
                >
                  <button
                    type="button"
                    class="flex shrink-0 items-center py-1.5 pl-2 pr-1 hover:text-foreground"
                    :title="item.expanded ? 'Collapse' : 'Expand'"
                    @click="toggleServer(item.server.id, { active: item.isActive })"
                  >
                    <ChevronDown v-if="item.expanded" class="h-3.5 w-3.5 shrink-0" />
                    <ChevronRight v-else class="h-3.5 w-3.5 shrink-0" />
                  </button>
                  <NuxtLink
                    :to="`/servers/${item.server.id}`"
                    class="flex min-w-0 flex-1 items-center gap-2 py-1.5 pr-2 text-sm"
                  >
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded bg-secondary text-[9px] font-semibold text-secondary-foreground">
                      {{ initialsOf(item.server.name) }}
                    </span>
                    <span class="truncate">{{ item.server.name }}</span>
                    <span
                      v-if="item.isActive && pendingCount"
                      class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold text-primary-foreground"
                      :title="`${pendingCount} pending join requests`"
                    >{{ pendingCount }}</span>
                  </NuxtLink>

                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <button
                        class="absolute right-3 top-1/2 hidden -translate-y-1/2 rounded bg-muted p-1 text-muted-foreground transition hover:text-foreground group-hover/sv:block"
                        :title="`${item.server.name} settings`"
                        @click.prevent
                      >
                        <ChevronDown class="h-3.5 w-3.5" />
                      </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56">
                      <DropdownMenuItem @select="navigateTo(`/servers/${item.server.id}`); showInvite = true">
                        <UserPlus class="mr-2 h-4 w-4" /> Invite people
                      </DropdownMenuItem>
                      <DropdownMenuItem @select="navigateTo(`/servers/${item.server.id}/requests`)">
                        <Check class="mr-2 h-4 w-4" /> Pending requests
                        <span
                          v-if="item.isActive && pendingCount"
                          class="ml-auto rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground"
                        >{{ pendingCount }}</span>
                      </DropdownMenuItem>
                      <DropdownMenuItem v-if="item.server.is_owner" @select="askRenameServer(item.server)">
                        <Pencil class="mr-2 h-4 w-4" /> Rename server
                      </DropdownMenuItem>

                      <DropdownMenuSeparator />

                      <!-- The owner can't leave (there'd be nobody left who could delete
                           it), so they get the other door. Everyone else gets this one. -->
                      <DropdownMenuItem
                        v-if="!item.server.is_owner"
                        class="text-destructive focus:text-destructive"
                        @select="askLeaveServer(item.server)"
                      >
                        <DoorOpen class="mr-2 h-4 w-4" /> Leave server
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        v-else
                        class="text-destructive focus:text-destructive"
                        @select="askDeleteServer(item.server)"
                      >
                        <Trash2 class="mr-2 h-4 w-4" /> Delete server
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>

                <!-- A channel inside the open server. -->
                <div v-else-if="item.kind === 'channel'">
                  <div class="group/ch relative">
                    <NuxtLink
                      :to="`/servers/${item.channel.server_id}/channels/${item.channel.id}`"
                      class="mx-2 flex items-center gap-2 rounded py-1.5 pl-7 pr-2 text-sm hover:bg-muted"
                      :class="item.channel.id === activeChannelId
                        ? 'bg-muted font-medium text-foreground'
                        : item.channel.unread_count
                          ? 'font-semibold text-foreground'
                          : 'text-muted-foreground'"
                    >
                      <Volume2 v-if="item.channel.type === 'voice'" class="h-4 w-4 shrink-0" />
                      <Hash v-else class="h-4 w-4 shrink-0" />
                      <span class="truncate">{{ item.channel.name }}</span>
                      <!-- Unsent text waiting in a channel you're not looking at (Viber-style). -->
                      <span
                        v-if="hasDraft(item.channel.id) && item.channel.id !== activeChannelId"
                        class="ml-auto shrink-0 text-[10px] font-medium italic text-primary"
                        title="You have an unsent draft here"
                      >Draft</span>
                      <!-- Voice channels hold a conversation as well as a call, so they get
                           the same unread badge every other channel does. -->
                      <span
                        v-if="item.channel.unread_count"
                        class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground"
                        :class="item.channel.mention ? 'ring-2 ring-primary/30' : ''"
                        :title="item.channel.mention ? 'You were mentioned' : `${item.channel.unread_count} unread`"
                      ><span v-if="item.channel.mention" aria-hidden="true">@</span>{{ item.channel.unread_count > 99 ? '99+' : item.channel.unread_count }}</span>
                      <span
                        v-else-if="item.voice.length"
                        class="ml-auto shrink-0 text-[10px] tabular-nums text-muted-foreground"
                      >{{ item.voice.length }}</span>
                    </NuxtLink>

                    <span
                      v-if="item.isOwner"
                      class="absolute right-3 top-1/2 hidden -translate-y-1/2 items-center gap-0.5 rounded bg-muted px-0.5 group-hover/ch:flex"
                    >
                      <button
                        class="rounded p-1 text-muted-foreground hover:text-foreground"
                        :title="`Rename ${item.channel.name}`"
                        @click.prevent="askRenameChannel(item.channel)"
                      >
                        <Pencil class="h-3.5 w-3.5" />
                      </button>
                      <button
                        class="rounded p-1 text-muted-foreground hover:text-destructive"
                        :title="`Delete ${item.channel.name}`"
                        @click.prevent="askDeleteChannel(item.channel)"
                      >
                        <Trash2 class="h-3.5 w-3.5" />
                      </button>
                    </span>
                  </div>

                  <!-- Whoever is already talking in this voice channel. -->
                  <NuxtLink
                    v-for="p in item.voice"
                    :key="p.user.id"
                    :to="`/servers/${item.channel.server_id}/channels/${item.channel.id}`"
                    class="mx-2 flex items-center gap-2 rounded py-0.5 pl-12 pr-2 text-xs text-muted-foreground hover:bg-muted"
                  >
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                      <img v-if="p.user.avatar" :src="p.user.avatar" :alt="p.user.name" class="h-full w-full rounded-full object-cover">
                      <span v-else>{{ initialsOf(p.user.name) }}</span>
                    </span>
                    <span class="truncate">{{ p.user.name }}</span>
                    <MicOff v-if="p.muted" class="ml-auto h-3 w-3 shrink-0 text-destructive" :title="`${p.user.name} is muted`" />
                    <ScreenShare v-if="p.screen_sharing" class="ml-auto h-3 w-3 shrink-0 text-primary" :title="`${p.user.name} is sharing their screen`" />
                  </NuxtLink>
                </div>

                <NuxtLink
                  v-else-if="item.kind === 'add-channel'"
                  :to="`/servers/${item.server.id}/channels/new`"
                  class="mx-2 flex items-center gap-2 rounded py-1.5 pl-7 pr-2 text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground"
                >
                  <Plus class="h-4 w-4 shrink-0" /> Add channel
                </NuxtLink>

                <NuxtLink
                  v-else-if="item.kind === 'add-server'"
                  to="/onboarding"
                  class="mx-2 flex items-center gap-2 rounded px-2 py-1.5 text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground"
                >
                  <Plus class="h-4 w-4 shrink-0" /> Add a server
                </NuxtLink>
              </DynamicScrollerItem>
            </template>
          </DynamicScroller>
        </ClientOnly>
      </div>

      <!-- Sits outside the page, because the call does too: leaving a channel's page
           doesn't leave the call. -->
      <VoiceBar />

      <!-- You. -->
      <div class="shrink-0 border-t p-2">
        <DropdownMenu>
          <DropdownMenuTrigger as-child>
            <button class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm outline-none transition hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring">
              <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                <img v-if="user?.avatar" :src="user.avatar" :alt="user.name" class="h-full w-full rounded-full object-cover">
                <span v-else>{{ user ? initialsOf(user.name) : '?' }}</span>
              </span>
              <span class="min-w-0 flex-1 truncate text-left font-medium">{{ user?.name }}</span>
              <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent side="top" align="start" class="w-56">
            <DropdownMenuLabel class="font-normal">
              <div class="flex flex-col">
                <span class="truncate text-sm font-medium">{{ user?.name }}</span>
                <span class="truncate text-xs text-muted-foreground">{{ user?.email }}</span>
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <div class="px-2 py-1.5">
              <p class="mb-1.5 text-xs font-medium text-muted-foreground">Appearance</p>
              <div class="grid grid-cols-3 gap-1">
                <button
                  v-for="m in modes"
                  :key="m.value"
                  type="button"
                  class="flex flex-col items-center gap-1 rounded-md border py-1.5 text-[11px] transition"
                  :class="mode === m.value ? 'border-primary bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted/50'"
                  @click="setMode(m.value)"
                >
                  <component :is="m.icon" class="h-4 w-4" />
                  {{ m.label }}
                </button>
              </div>
              <p class="mb-1.5 mt-3 text-xs font-medium text-muted-foreground">Theme</p>
              <div class="grid grid-cols-4 gap-1.5">
                <button
                  v-for="c in colors"
                  :key="c.value"
                  type="button"
                  :data-accent="c.value"
                  class="accent-swatch grid h-7 w-full place-items-center rounded-md ring-offset-2 ring-offset-popover transition hover:brightness-105"
                  :class="color === c.value ? 'ring-2 ring-ring' : ''"
                  :title="c.label"
                  :aria-label="c.label"
                  :aria-pressed="color === c.value"
                  @click="setColor(c.value)"
                >
                  <Check v-if="color === c.value" class="h-3.5 w-3.5 text-white drop-shadow" />
                </button>
              </div>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem>
              <User class="mr-2 h-4 w-4" /> Profile
            </DropdownMenuItem>
            <DropdownMenuItem class="text-destructive focus:text-destructive" @select="logout">
              <LogOut class="mr-2 h-4 w-4" /> Sign out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </aside>

    <!-- Main content -->
    <main class="flex min-w-0 flex-1 flex-col">
      <slot />
    </main>

    <!-- The ringing phone. Lives here, not in a page, because a call has to reach you
         wherever you are — including in a conversation you have never once opened. -->
    <IncomingCall />

    <NewChatDialog v-model:open="showNewChat" />

    <Dialog v-model:open="showInvite">
      <DialogContent v-if="server">
        <DialogHeader>
          <DialogTitle>Invite people to {{ server.name }}</DialogTitle>
          <DialogDescription>
            Share this link. Anyone who opens it can request to join — a member has to
            approve them before they're let in.
          </DialogDescription>
        </DialogHeader>
        <div class="flex items-center gap-2">
          <Input :model-value="server.invite_url" readonly class="flex-1 font-mono text-xs" />
          <Button variant="outline" size="icon" :title="copied ? 'Copied!' : 'Copy link'" @click="copyInvite">
            <Check v-if="copied" class="h-4 w-4 text-green-600 dark:text-green-400" />
            <Copy v-else class="h-4 w-4" />
          </Button>
        </div>
      </DialogContent>
    </Dialog>

    <!-- Rename the server (owner) -->
    <Dialog v-model:open="showRenameServer">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Rename server</DialogTitle>
          <DialogDescription>Everyone in the server sees the new name.</DialogDescription>
        </DialogHeader>
        <form class="space-y-3" @submit.prevent="onRenameServer">
          <Input v-model="nameDraft" placeholder="Server name" maxlength="100" autofocus />
          <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
          <div class="flex justify-end gap-2">
            <Button type="button" variant="outline" :disabled="working" @click="showRenameServer = false">
              Cancel
            </Button>
            <Button type="submit" :disabled="working || !nameDraft.trim()">
              {{ working ? 'Saving…' : 'Save' }}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>

    <!-- Rename a channel (owner) -->
    <Dialog v-model:open="showRenameChannel">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Rename channel</DialogTitle>
          <DialogDescription>Everyone in the server sees the new name.</DialogDescription>
        </DialogHeader>
        <form class="space-y-3" @submit.prevent="onRenameChannel">
          <Input v-model="nameDraft" placeholder="Channel name" maxlength="100" autofocus />
          <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
          <div class="flex justify-end gap-2">
            <Button type="button" variant="outline" :disabled="working" @click="showRenameChannel = false">
              Cancel
            </Button>
            <Button type="submit" :disabled="working || !nameDraft.trim()">
              {{ working ? 'Saving…' : 'Save' }}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>

    <!--
      The confirm button on each of these is a plain Button, not an AlertDialogAction.
      AlertDialogAction closes the dialog itself, on click, before our handler runs — which
      both discards what we were about to act on and leaves any error message nowhere to be
      displayed. Closing is ours to do, once the thing has actually succeeded.
    -->

    <!-- Leave (members) -->
    <AlertDialog v-model:open="showLeave">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Leave {{ targetServer?.name }}?</AlertDialogTitle>
          <AlertDialogDescription>
            You’ll lose access to its channels and need a new invite to come back. The
            messages you’ve posted stay where they are.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
          <Button variant="destructive" :disabled="working" @click="onLeaveServer">
            {{ working ? 'Leaving…' : 'Leave server' }}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Delete the whole server (owner) -->
    <AlertDialog v-model:open="showDeleteServer">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete {{ targetServer?.name }}?</AlertDialogTitle>
          <AlertDialogDescription>
            This can’t be undone. Every channel, message, thread and uploaded file in this
            server is permanently deleted, for everyone in it.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
          <Button variant="destructive" :disabled="working" @click="onDeleteServer">
            {{ working ? 'Deleting…' : 'Delete server' }}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Delete one channel (owner) -->
    <AlertDialog v-model:open="showDeleteChannel">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete {{ targetChannel?.name }}?</AlertDialogTitle>
          <AlertDialogDescription>
            This can’t be undone. The channel’s messages, threads and uploaded files are
            permanently deleted, for everyone.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
          <Button variant="destructive" :disabled="working" @click="onDeleteChannel">
            {{ working ? 'Deleting…' : 'Delete channel' }}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </div>
</template>
