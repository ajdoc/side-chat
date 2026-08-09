<script setup lang="ts">
import {
  AudioLines,
  Bot,
  Check, ChevronDown, ChevronRight, Copy, DoorOpen, Hash, HeadphoneOff, Lock, LogOut,
  KeyRound,
  LayoutList,
  Map as MapIcon,
  MessageSquarePlus, MessagesSquare, MicOff, Monitor, Moon, Pencil, Phone, Plus, ScreenShare, Search, Shield, Sun, Trash2,
  User, UserPlus, Users, Volume2, Zap,
} from 'lucide-vue-next'
import { useLocalStorage } from '@vueuse/core'
import type { Channel, Conversation, Server, ThemeColor, ThemeMode } from '~/types'
import type { SplitPane } from '~/composables/useSplitView'
import { useLongPress } from '~/composables/useTouch'
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
const { server, channels, findChannel, resolveDiscussion, openServer, loadMoreChannels, renameChannel, deleteChannel, patchServer } = useServer()
const { conversations, hasMore: hasMoreChats, fetchConversations, loadMore: loadMoreChats } = useConversations()
// Only the incoming half is a badge — a request you sent is not news you need chasing.
const { incoming: incomingFriends, load: loadFriends } = useFriends()
const { user, logout, updateProfile } = useAuth()
const { hasDraft } = useDrafts()
// People in a voice channel show under whatever they're called in this server.
const { nameFor } = useNicknames()
const { mode, color, setMode, setColor } = useTheme()
const { participantsIn } = useVoiceRoster()
const { expandedIds, isExpanded, isLoading, expand: expandServer, toggle: toggleServer, loadChannels, cache: cacheChannels, channelsFor, isSectionOpen, toggleSection, isBranchOpen, toggleBranch } = useSidebarChannels()

/**
 * Split view: the second conversation docked beside the page. See useSplitView for why the
 * route keeps the left half and why there are two panes rather than a tiling grid.
 */
const { pane, ratio, openSplit, closeSplit, setRatioFromX, writeDragPayload, readDragPayload, isChannelDrag } = useSplitView()
const splitArea = ref<HTMLElement | null>(null)
const dropActive = ref(false)
const draggingDivider = ref(false)
/** A split on a screen too narrow for two columns: two full-width pages you swipe between. */
const pagerSplit = computed(() => !!pane.value && narrow.value)

function onSplitDragOver(e: DragEvent) {
  if (!isChannelDrag(e)) return
  // Without preventDefault the browser refuses the drop outright — this is what makes the
  // area a drop target at all, not merely a decoration.
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
  dropActive.value = true
}

function onSplitDragLeave(e: DragEvent) {
  // Dragging across a child element fires `dragleave` on the parent; only a pointer that has
  // actually left the area counts, or the zone flickers off under the cursor.
  if (e.relatedTarget instanceof Node && splitArea.value?.contains(e.relatedTarget)) return
  dropActive.value = false
}

function onSplitDrop(e: DragEvent) {
  dropActive.value = false
  const payload = readDragPayload(e)
  if (!payload) return
  e.preventDefault()
  // Dropping the channel you're already in would split the window between a thing and
  // itself; navigating there is what the click on that row does anyway.
  if (payload.channelId === activeChannelId.value) return
  openSplit(payload)
}

/**
 * "Open beside", by finger.
 *
 * The split is set up by dragging a channel row onto the main area, which is a gesture a touch
 * screen doesn't have — HTML5 drag-and-drop is a mouse feature, so on a phone the feature had
 * no door at all. A long press on the same row is that door, and deliberately does the same one
 * thing the drag does rather than raising a menu: the row already navigates on a tap, so the
 * only other thing anyone wants from it is the second column.
 *
 * The handler bags are memoised per row. `useLongPress` keeps its own timer and "did it fire?"
 * flag inside the bag it returns, and that flag is what swallows the click the browser
 * synthesises afterwards — so a bag rebuilt between the press and its timer (an unread count
 * ticking over mid-press is enough) would let the row navigate out from under the split it just
 * opened. The payload is refreshed on every render instead, so a channel renamed while the app
 * is open still docks under its new name.
 */
const splitPress = new Map<number, { bag: ReturnType<typeof useLongPress>, payload: SplitPane }>()
function openBeside(payload: SplitPane) {
  const existing = splitPress.get(payload.channelId)
  if (existing) {
    existing.payload = payload
    return existing.bag
  }
  const entry = {
    payload,
    bag: useLongPress(() => {
      const p = splitPress.get(payload.channelId)?.payload
      // Splitting the channel you're already in would split the window between a thing and
      // itself — the same rule the drop zone applies.
      if (!p || p.channelId === activeChannelId.value) return
      openSplit(p)
      // No navigation happened, so the drawer's own route watcher won't close it — and the
      // split it just opened is behind it.
      closeDrawer()
    }),
  }
  splitPress.set(payload.channelId, entry)
  return entry.bag
}

/**
 * Swap the panes: the docked conversation becomes the page, and the page you were on takes
 * the dock. Nearly always what "open this properly" means — you were watching it because
 * you were about to need it.
 */
function promotePane() {
  const docked = pane.value
  if (!docked) return
  const current = currentPaneTarget()
  navigateTo(docked.path)
  // Null when the page isn't a conversation (a settings screen, the requests list): there's
  // nothing to put in the dock, so the split simply closes.
  if (current) openSplit(current)
  else closeSplit()
}

/** The page you're standing on, as a pane — or null if it isn't a conversation. */
function currentPaneTarget() {
  const channel = findChannel(activeChannelId.value)
  if (channel && server.value) {
    return {
      channelId: channel.id,
      title: channel.name,
      type: channel.type,
      path: `/servers/${server.value.id}/channels/${channel.id}`,
    }
  }

  const conversation = conversations.value.find(c => c.id === activeConversationId.value)
  if (conversation) {
    return {
      channelId: conversation.channel_id,
      title: chatTitle(conversation),
      type: 'text' as const,
      path: `/chats/${conversation.id}`,
    }
  }

  return null
}

/**
 * The divider drag. Pointer events with capture rather than mousemove on the window: the
 * pointer keeps reporting to the divider even when it outruns it, which a 4px target it
 * very much does.
 */
function startDividerDrag(e: PointerEvent) {
  const el = splitArea.value
  if (!el) return
  const target = e.currentTarget as HTMLElement
  target.setPointerCapture(e.pointerId)
  draggingDivider.value = true

  const box = el.getBoundingClientRect()
  const move = (ev: PointerEvent) => setRatioFromX(ev.clientX, box.left, box.width)
  const up = () => {
    draggingDivider.value = false
    target.removeEventListener('pointermove', move)
    target.removeEventListener('pointerup', up)
    target.removeEventListener('pointercancel', up)
  }
  target.addEventListener('pointermove', move)
  target.addEventListener('pointerup', up)
  target.addEventListener('pointercancel', up)
}

// A split needs two columns' worth of room. Below `md` there is one, so the pane is drawn as a
// second full-width page instead (see `pagerSplit`) — it's the same split either way, so
// widening the window lays it back out as columns exactly where you left it.

/**
 * The channel-type sections, in the order a server's tree draws them.
 *
 * A constant rather than three hand-written blocks, because the three sections differ only
 * in which channels they hold and what they're called — writing them out three times is
 * three places for the next kind of channel to be forgotten.
 */
const CHANNEL_SECTIONS = [
  { type: 'text', label: 'Text' },
  { type: 'voice', label: 'Voice' },
  { type: 'space', label: 'Side Spaces' },
] as const
const userStream = useUserStream()
const { ensurePermission: ensureNotifyPermission } = useDesktopNotifications()
// Global online/idle presence — joined once here, since this layout is the one thing mounted
// for the whole of a signed-in session. Every avatar's status dot reads from it.
const { start: startPresence, stop: stopPresence } = usePresence()

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

// The sidebar's width is draggable and remembered (its right border carries the handle).
const { width: sidebarWidth, startResize: startSidebarResize } = useResizable('sidebar', 256, { min: 200, max: 480, edge: 'right' })

const activeServerId = computed(() => Number(route.params.serverId) || null)
const activeChannelId = computed(() => Number(route.params.channelId) || null)
const activeConversationId = computed(() => Number(route.params.conversationId) || null)
// The channel whose discussion you're reading — the one branch that stays unfolded whether or
// not you unfolded it. Read from the tree rather than the route, because the route names a
// discussion on a channel page and the *container* on the discussion directory; resolving both
// to the container is what keeps the branch open across the hop between them.
const activeParent = computed(() => {
  const channel = findChannel(activeChannelId.value)

  return channel ? (channel.parent_id ?? channel.id) : null
})

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

// Running a server — creating one, adding channels, inviting people, approving the requests
// that come back — now works on the phone too, so the sidebar no longer hides those doors from
// the native builds. What is still withheld there (Side Spaces, the Side Desk) is withheld by
// middleware/native-scope.global.ts. Nothing in this layout asks `usePlatform` any more: the
// last holdout was the floating-window shelf, and the question it was really asking — "is there
// room to arrange panels?" — is about the viewport, not the shell, so it's `narrow`'s business.
const { narrow, open: drawerOpen, close: closeDrawer } = useNavDrawer()
// ⌘K. Held by ref rather than a shared flag because the palette owns its own open state and
// its own shortcut — the sidebar button is one more way in, not the way in.
const palette = ref<{ show: () => void } | null>(null)
// The sidebar hides several controls behind hover — a server's settings menu, a channel's
// rename/delete. On a touch screen that isn't "harder to find", it's unreachable, so those
// controls stand permanently open for a finger. See useTouch.
const coarse = useCoarsePointer()

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

  // --- Friends ---
  //
  // Above the sections rather than inside one: it isn't a place you can open, it's the
  // roster the places are made of, and it's where a pending request has to be visible from
  // wherever you happen to be standing.
  list.push({ id: 'friends', kind: 'friends' })

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

      // Inline rename/delete only on the active server: those edits flow through useServer,
      // which holds *its* channels, so a cached (non-active) server's tree couldn't be kept
      // in step with them. You manage a server's channels from inside it.
      // Staff, not just the owner: an admin exists precisely so the owner doesn't have to
      // be around for the server's shape to change. `is_staff` falls back to `is_owner` for
      // a server payload cached from before roles existed.
      const canEdit = isActive && (s.is_staff ?? s.is_owner)

      /**
       * The three kinds of place, each under its own foldable heading.
       *
       * Order is text → voice → Side Spaces: what you read, then the two kinds of room you
       * *go into*, which both carry a head-count. A heading is only drawn when the server
       * actually has that kind of channel — an empty "Voice" label is a promise of nothing.
       *
       * The open/shut state comes from useSidebarChannels, keyed by server, so folding a
       * section in one server doesn't fold it in the next and nothing about the route
       * touches it. See the note there.
       */
      for (const group of CHANNEL_SECTIONS) {
        const groupChannels = rowChannels.filter(c => c.type === group.type)
        if (!groupChannels.length) continue

        const open = isSectionOpen(s.id, group.type)
        list.push({
          id: `sec-${s.id}-${group.type}`,
          kind: 'channel-section',
          server: s,
          channelType: group.type,
          label: group.label,
          count: groupChannels.length,
          open,
        })
        if (!open) continue

        for (const c of groupChannels) {
          const discussions = c.discussions ?? []
          // Unfolded either because you unfolded it or because you're standing in it. A lone
          // discussion never gets a branch: that channel is a channel exactly as it was before
          // discussions existed, and drawing "General" under it would be a row that says
          // nothing.
          const branched = discussions.length > 1
          const open = branched && isBranchOpen(c.id, activeParent.value)

          list.push({
            id: `c-${c.id}`,
            kind: 'channel',
            channel: c,
            // Clicking a channel opens a conversation, and the container has none — so the row
            // points at the discussion you'd land in anyway: the one you pinned, else the first.
            target: resolveDiscussion(c) ?? c,
            branched,
            open,
            // Only the rooms have occupants to draw; a text channel's list is always empty.
            // Folded shut, the channel row carries everyone in every discussion of it, because
            // "is anybody in there" is the question a collapsed room has to answer. Unfolded,
            // each face moves to the discussion it's actually in.
            voice: group.type === 'text' || open ? [] : discussions.flatMap(d => participantsIn(d.id)),
            isOwner: canEdit,
          })

          if (!open) continue

          for (const d of discussions) {
            list.push({
              id: `d-${d.id}`,
              kind: 'discussion',
              channel: d,
              voice: group.type === 'text' ? [] : participantsIn(d.id),
              // Renaming and deleting a discussion are staff's, like a channel's — and like a
              // channel's, only on the server you're standing in, whose tree is the live one.
              isOwner: canEdit,
              // Never the last one: a channel with no discussions is a channel you can open but
              // not read. The server refuses it too; this just doesn't offer it.
              canDelete: canEdit && discussions.length > 1,
            })
          }
        }
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
  if (!inviteServer.value) return
  try {
    await navigator.clipboard.writeText(inviteServer.value.invite_url)
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
/**
 * Which server's link is on show.
 *
 * The dialog used to read `server` from useServer — the one you're *standing in* — while the
 * menu that opens it hangs off any row in the list, and the navigation it fired alongside had
 * not landed yet. So it showed the previous server's link, or nothing. Every row already
 * carries its own `invite_url` (the servers index returns it), so the dialog takes the server
 * it was opened for and no navigation is needed at all.
 */
const inviteServer = ref<Server | null>(null)

function askInvite(s: Server) {
  inviteServer.value = s
  copied.value = false
  showInvite.value = true
}

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
// The two settings surfaces added with roles. Both are their own components rather than
// more inline dialogs here: each fetches something (an allow-list, a roster with roles)
// that only the people who can open it are allowed to see.
const accessChannel = ref<Channel | null>(null)
const showKeyBackup = ref(false)
const rolesServer = ref<Server | null>(null)
// Bots, likewise its own component: it fetches tokens' worth of settings only the owner
// may see, and it's the one screen where a secret is shown.
const botsServer = ref<Server | null>(null)
// The bot's control panel — rules, badges, how it behaves. Staff, unlike Bots above:
// configuring what the bot *does* is running the place, not holding its credential.
const botDashboardServer = ref<Server | null>(null)
const showProfile = ref(false)
// Which channel the new-discussion dialog is about. Held apart from the open flag for the same
// reason the delete dialogs are — see the note above.
const newDiscussionParent = ref<Channel | null>(null)
const showNewDiscussion = ref(false)
const { canCreate: canCreateDiscussions, remove: removeDiscussion } = useDiscussions()
const targetChannel = ref<Channel | null>(null)
const targetServer = ref<Server | null>(null)
const nameDraft = ref('')
// Who may start a discussion in this server's channels. Open by default — a discussion is a
// conversation somebody wanted to have — with the switch here for the servers that outgrow that.
const discussionPolicyDraft = ref<'everyone' | 'staff'>('everyone')
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
  discussionPolicyDraft.value = s.discussion_creation ?? 'everyone'
  actionError.value = ''
  showRenameServer.value = true
}

function askRenameChannel(channel: Channel) {
  targetChannel.value = channel
  nameDraft.value = channel.name
  actionError.value = ''
  showRenameChannel.value = true
}

function askChannelAccess(channel: Channel) {
  accessChannel.value = channel
}

/**
 * Start a discussion in this channel.
 *
 * Unfolds the branch on the way, so the new one lands somewhere visible — making a thing and
 * not being shown where it went is how people conclude nothing happened.
 */
function askNewDiscussion(channel: Channel) {
  newDiscussionParent.value = channel
  showNewDiscussion.value = true
}

function onDiscussionCreated(discussion: Channel) {
  if (discussion.parent_id && !isBranchOpen(discussion.parent_id)) toggleBranch(discussion.parent_id)
  navigateTo(`/servers/${discussion.server_id}/channels/${discussion.id}`)
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

const showNickname = ref(false)

/**
 * Rename yourself in one server.
 *
 * Navigates first, because a nickname is scoped to a place and useNicknames only ever
 * holds the one you're standing in — offering to rename you in a server you aren't
 * looking at would write the name into whichever place happened to be open.
 */
async function askOwnNickname(serverId: number) {
  if (activeServerId.value !== serverId) await navigateTo(`/servers/${serverId}`)
  showNickname.value = true
}

function askEditProfile() {
  nameDraft.value = user.value?.name ?? ''
  actionError.value = ''
  showProfile.value = true
}

const onRenameServer = () => confirm(showRenameServer, async () => {
  const name = nameDraft.value.trim()
  if (!name || !targetServer.value) return
  const updated = await renameServer(targetServer.value.id, name, {
    discussion_creation: discussionPolicyDraft.value,
  })
  // renameServer patches the list; the open server is a separate ref.
  patchServer(updated.id, updated)
}, 'Could not rename the server.')

const onRenameChannel = () => confirm(showRenameChannel, async () => {
  const name = nameDraft.value.trim()
  if (!name || !targetChannel.value) return
  await renameChannel(targetChannel.value.id, name)
}, 'Could not rename the channel.')

const onSaveProfile = () => confirm(showProfile, async () => {
  const name = nameDraft.value.trim()
  if (!name || name === user.value?.name) return
  await updateProfile({ name })
}, 'Could not save your name.')

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

  // One dialog for both, dispatching on what the row actually is. They are different endpoints
  // — a discussion's refuses to remove the last one in a channel — but they ask the same
  // question of the same person, and two dialogs would be two places to keep that wording.
  if (channel.parent_id) await removeDiscussion(channel)
  else await deleteChannel(channel.id)

  // Standing in the thing you just deleted: step back out. For a discussion that means its
  // channel, which resolves to whichever sibling is left.
  if (activeChannelId.value === channel.id) {
    await navigateTo(`/servers/${channel.server_id}/channels/${channel.parent_id ?? ''}`.replace(/\/$/, ''))
  }
}, 'Could not delete it.')

onMounted(async () => {
  // Your own stream first: it's the only subscription that outlives every navigation, and
  // it's what makes a DM appear, a badge move, and a phone ring. Everything else is scoped
  // to a place you happen to be standing.
  userStream.subscribe()
  startPresence()
  // Ask once, so a mention can reach you while you're in another tab. Declined is fine —
  // the sidebar badge still does its job.
  ensureNotifyPermission()

  // Friends load with the sidebar, not with the friends page: the badge on the row is the
  // whole reason you'd click it, and a badge that only appears once you're already there
  // isn't one.
  await Promise.all([fetchServers(), fetchConversations(), loadFriends()])
  await syncServer()
})

watch(activeServerId, syncServer)
watch(() => user.value?.id, () => userStream.subscribe())
onBeforeUnmount(() => { userStream.unsubscribe(); stopPresence() })
</script>

<template>
  <!-- `safe-inset` keeps the header out from under the status bar and the composer clear of
       the gesture bar. `h-screen` is border-box, so the padding comes out of the height
       rather than adding to it — the shell still fits the screen exactly. -->
  <div class="safe-inset flex h-screen text-foreground">
    <!-- Narrow screens (phones, and a very small desktop window) can't hold the sidebar
         beside a conversation, so it lifts out into a drawer over the top of one. Same
         markup either way — only its position, width and visibility differ.

         Scrim and drawer sit at 45: above the floating shelf (40), whose docked music bar
         would otherwise cover the account menu at the foot of the drawer, and below the
         dialogs and menus at 50, which can be opened *from* the drawer. -->

    <div
      v-if="narrow && drawerOpen"
      class="fixed inset-0 z-[45] bg-black/40"
      @click="closeDrawer"
    />
    <!-- `relative` belongs to the wide branch only. Tailwind emits `.relative` after `.fixed`
         in the stylesheet, so listing both would leave the drawer in the flex flow — still
         reserving its width while `-translate-x-full` merely slid it out of sight. -->
    <aside
      class="flex flex-col border-r bg-sidebar transition-transform"
      :class="narrow
        ? ['safe-inset fixed inset-y-0 left-0 z-[45] w-[min(20rem,85vw)]', drawerOpen ? 'translate-x-0' : '-translate-x-full']
        : 'relative shrink-0'"
      :style="narrow ? undefined : { width: `${sidebarWidth}px` }"
    >
      <ResizeHandle v-if="!narrow" edge="right" @resize="startSidebarResize" />
      <div class="flex h-12 shrink-0 items-center gap-2 border-b px-4 font-semibold">
        <img src="/brand/logo.png" alt="" class="h-6 w-6 shrink-0 rounded-md" >
        Side Chat
        <!-- The palette's discoverable handle. ⌘K is how it will actually be opened once
             anybody knows it exists, and a phone has no ⌘ at all — so the shortcut is a
             hint on the button rather than the only way in. -->
        <button
          type="button"
          class="ml-auto flex shrink-0 items-center gap-1.5 rounded-md px-1.5 py-1 text-muted-foreground transition hover:bg-muted hover:text-foreground"
          title="Search everything (⌘K)"
          @click="closeDrawer(); palette?.show()"
        >
          <Search class="h-4 w-4" />
          <kbd v-if="!narrow" class="rounded border px-1 text-[10px] font-normal">⌘K</kbd>
        </button>
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
                  draggable="true"
                  class="mx-2 flex items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted"
                  @dragstart="writeDragPayload($event, {
                    channelId: item.conversation.channel_id,
                    title: chatTitle(item.conversation),
                    type: 'text',
                    path: `/chats/${item.conversation.id}`,
                  })"
                  v-on="openBeside({
                    channelId: item.conversation.channel_id,
                    title: chatTitle(item.conversation),
                    type: 'text',
                    path: `/chats/${item.conversation.id}`,
                  })"
                  :class="item.conversation.id === activeConversationId
                    ? 'bg-muted font-medium text-foreground'
                    : item.conversation.unread_count
                      ? 'font-semibold text-foreground'
                      : 'text-muted-foreground'"
                >
                  <span class="relative grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                    <Users v-if="item.conversation.type === 'group'" class="h-3.5 w-3.5" />
                    <img
                      v-else-if="conversationAvatar(item.conversation, user)"
                      :src="conversationAvatar(item.conversation, user)"
                      :alt="chatTitle(item.conversation)"
                      class="h-full w-full rounded-full object-cover"
                    >
                    <span v-else>{{ initialsOf(chatTitle(item.conversation)) }}</span>
                    <!-- Online/idle dot on the person you're talking to (DMs only — a group has many). -->
                    <PresenceDot
                      v-if="item.conversation.type !== 'group' && otherMembers(item.conversation, user)[0]"
                      :user-id="otherMembers(item.conversation, user)[0]!.id"
                      class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5"
                    />
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

                <NuxtLink
                  v-else-if="item.kind === 'friends'"
                  to="/friends"
                  class="mx-2 flex w-[calc(100%-1rem)] items-center gap-2 rounded px-2 py-1.5 text-sm transition hover:bg-muted"
                  :class="route.path === '/friends' ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground hover:text-foreground'"
                >
                  <Users class="h-4 w-4 shrink-0" />
                  Friends
                  <span
                    v-if="incomingFriends.length"
                    class="ml-auto shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground"
                    :title="`${incomingFriends.length} friend request${incomingFriends.length === 1 ? '' : 's'}`"
                  >{{ incomingFriends.length > 99 ? '99+' : incomingFriends.length }}</span>
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
                    class="flex min-w-0 flex-1 items-center gap-2 py-1.5 text-sm"
                    :class="coarse ? 'pr-10' : 'pr-2'"
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
                      <!-- Invite, nickname, leave and delete all live behind this. On a mouse
                           it appears on hover, which on a touch screen meant they did not
                           exist at all — so a coarse pointer gets it permanently, with a
                           finger-sized target. -->
                      <button
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded bg-muted text-muted-foreground transition hover:text-foreground"
                        :class="coarse ? 'block p-2' : 'hidden p-1 group-hover/sv:block'"
                        :title="`${item.server.name} settings`"
                        @click.prevent
                      >
                        <ChevronDown class="h-3.5 w-3.5" />
                      </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56">
                      <DropdownMenuItem @select="askInvite(item.server)">
                        <UserPlus class="mr-2 h-4 w-4" /> Invite people
                      </DropdownMenuItem>
                      <DropdownMenuItem @select="navigateTo(`/servers/${item.server.id}/requests`)">
                        <Check class="mr-2 h-4 w-4" /> Pending requests
                        <span
                          v-if="item.isActive && pendingCount"
                          class="ml-auto rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground"
                        >{{ pendingCount }}</span>
                      </DropdownMenuItem>
                      <DropdownMenuItem v-if="item.server.is_staff ?? item.server.is_owner" @select="askRenameServer(item.server)">
                        <Pencil class="mr-2 h-4 w-4" /> Rename server
                      </DropdownMenuItem>
                      <!-- Owner only: an admin who could appoint admins would be an owner. -->
                      <DropdownMenuItem v-if="item.server.is_owner" @select="rolesServer = item.server">
                        <Shield class="mr-2 h-4 w-4" /> Roles
                      </DropdownMenuItem>
                      <!-- Owner only, for the same reason as Roles: a bot token is standing
                           write access to the server. -->
                      <DropdownMenuItem v-if="item.server.is_owner" @select="botsServer = item.server">
                        <Bot class="mr-2 h-4 w-4" /> Bots
                      </DropdownMenuItem>
                      <!-- Staff, not owner-only: a welcome message or a scheduled post is
                           running the place, which is what an admin is for. The one rule an
                           admin can't write (handing out roles) is refused by the API. -->
                      <DropdownMenuItem v-if="item.server.is_staff ?? item.server.is_owner" @select="botDashboardServer = item.server">
                        <Zap class="mr-2 h-4 w-4" /> Bot dashboard
                      </DropdownMenuItem>
                      <!-- What *you* are called in this server. Other people's nicknames
                           are set from the roster in the channel Info panel, where you can
                           see who you're renaming. -->
                      <DropdownMenuItem @select="askOwnNickname(item.server.id)">
                        <User class="mr-2 h-4 w-4" /> Change my nickname
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

                <!-- The Text / Voice / Side Spaces heading inside an open server. Folds its
                     own channels away; the count is what tells you what you folded. -->
                <button
                  v-else-if="item.kind === 'channel-section'"
                  type="button"
                  class="mx-2 mt-1 flex w-[calc(100%-1rem)] items-center gap-1 rounded py-1 pl-5 pr-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground transition hover:bg-muted hover:text-foreground"
                  :title="item.open ? `Collapse ${item.label}` : `Expand ${item.label}`"
                  @click="toggleSection(item.server.id, item.channelType)"
                >
                  <ChevronDown v-if="item.open" class="h-3 w-3 shrink-0" />
                  <ChevronRight v-else class="h-3 w-3 shrink-0" />
                  <span class="truncate">{{ item.label }}</span>
                  <span class="ml-auto shrink-0 tabular-nums opacity-60">{{ item.count }}</span>
                </button>

                <!-- A channel inside the open server. -->
                <div v-else-if="item.kind === 'channel'">
                  <div class="group/ch relative">
                    <!-- Draggable onto the main area to open beside what you're reading, or
                         long-pressed to the same end where there is no drag to make. The link
                         still navigates on a plain click; both are the other gesture the same
                         row already invited. See `openBeside`. -->
                    <NuxtLink
                      :to="`/servers/${item.channel.server_id}/channels/${item.target.id}`"
                      draggable="true"
                      class="mx-2 flex items-center gap-2 rounded py-1.5 pr-2 text-sm hover:bg-muted"
                      @dragstart="writeDragPayload($event, {
                        channelId: item.target.id,
                        title: item.channel.name,
                        type: item.channel.type,
                        path: `/servers/${item.channel.server_id}/channels/${item.target.id}`,
                      })"
                      v-on="openBeside({
                        channelId: item.target.id,
                        title: item.channel.name,
                        type: item.channel.type,
                        path: `/servers/${item.channel.server_id}/channels/${item.target.id}`,
                      })"
                      :class="[
                        // A chevron takes the indent the icon would otherwise sit in.
                        item.branched ? 'pl-4' : 'pl-7',
                        // Highlighted for its discussions *and* for its directory, which names
                        // the container rather than anything inside it.
                        item.target.id === activeChannelId || item.channel.id === activeChannelId
                          ? 'bg-muted font-medium text-foreground'
                          : item.channel.unread_count
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground',
                      ]"
                    >
                      <!-- Unfolds the discussions without navigating: seeing what else is in a
                           channel shouldn't cost you the conversation you're reading. -->
                      <button
                        v-if="item.branched"
                        type="button"
                        class="-my-1 shrink-0 rounded p-0.5 text-muted-foreground hover:bg-background hover:text-foreground"
                        :title="item.open ? 'Hide discussions' : `Show ${item.channel.discussions.length} discussions`"
                        @click.prevent.stop="toggleBranch(item.channel.id)"
                      >
                        <ChevronDown v-if="item.open" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                      </button>
                      <MapIcon v-if="item.channel.type === 'space'" class="h-4 w-4 shrink-0" />
                      <Volume2 v-else-if="item.channel.type === 'voice'" class="h-4 w-4 shrink-0" />
                      <Hash v-else class="h-4 w-4 shrink-0" />
                      <span class="truncate">{{ item.channel.name }}</span>
                      <!-- Restricted to an allow-list. Only the people who *can* see it ever
                           see this row at all, so the lock reads as "not everyone is here"
                           rather than as a door they might be turned away from. -->
                      <Lock v-if="item.channel.is_private" class="h-3 w-3 shrink-0 text-muted-foreground" title="Only chosen members" />
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

                    <!-- Owner-only rename/delete. Hover-revealed for a mouse; always present
                         on touch, where there is no hover to reveal them with. -->
                    <span
                      v-if="item.isOwner || canCreateDiscussions || item.branched"
                      class="absolute right-3 top-1/2 -translate-y-1/2 items-center gap-0.5 rounded bg-muted px-0.5"
                      :class="coarse ? 'flex' : 'hidden group-hover/ch:flex'"
                    >
                      <!-- The one entry point that works for a channel with a single discussion,
                           where the header shows no picker to put it in. Open to anyone the
                           server lets start one, which is why it isn't inside `isOwner`. -->
                      <!-- The searchable, sortable list of everything in this channel. Only
                           once there's more than one conversation to list. -->
                      <NuxtLink
                        v-if="item.branched"
                        :to="`/servers/${item.channel.server_id}/discussions/${item.channel.id}`"
                        class="rounded text-muted-foreground hover:text-foreground"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`All discussions in ${item.channel.name}`"
                        @click.stop
                      >
                        <LayoutList class="h-3.5 w-3.5" />
                      </NuxtLink>
                      <button
                        v-if="canCreateDiscussions"
                        class="rounded text-muted-foreground hover:text-foreground"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`New discussion in ${item.channel.name}`"
                        @click.prevent="askNewDiscussion(item.channel)"
                      >
                        <MessageSquarePlus class="h-3.5 w-3.5" />
                      </button>
                      <button
                        v-if="item.isOwner"
                        class="rounded text-muted-foreground hover:text-foreground"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`Rename ${item.channel.name}`"
                        @click.prevent="askRenameChannel(item.channel)"
                      >
                        <Pencil class="h-3.5 w-3.5" />
                      </button>
                      <button
                        v-if="item.isOwner"
                        class="rounded text-muted-foreground hover:text-foreground"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`Who can see ${item.channel.name}`"
                        @click.prevent="askChannelAccess(item.channel)"
                      >
                        <component :is="item.channel.is_private ? Lock : Users" class="h-3.5 w-3.5" />
                      </button>
                      <button
                        v-if="item.isOwner"
                        class="rounded text-muted-foreground hover:text-destructive"
                        :class="coarse ? 'p-2' : 'p-1'"
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
                    :to="`/servers/${item.channel.server_id}/channels/${p.channel_id}`"
                    class="mx-2 flex items-center gap-2 rounded py-0.5 pl-12 pr-2 text-xs text-muted-foreground hover:bg-muted"
                  >
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                      <img v-if="p.user.avatar" :src="p.user.avatar" :alt="nameFor(p.user)" class="h-full w-full rounded-full object-cover">
                      <span v-else>{{ initialsOf(nameFor(p.user)) }}</span>
                    </span>
                    <span class="truncate">{{ nameFor(p.user) }}</span>
                    <!--
                      What they're doing, as they've told everybody. One `ml-auto` on the
                      group rather than on each icon: hung on the icons themselves, whichever
                      came second won the margin and the pair sat apart.

                      Mute and deafen get an icon each. Deafening does shut your own mic as
                      well, so the two travel together most of the time — but they aren't the
                      same fact, and only one of them means "can't hear you either".
                    -->
                    <span class="ml-auto flex shrink-0 items-center gap-1">
                      <ScreenShare v-if="p.screen_sharing" class="h-3 w-3 text-primary" :title="`${nameFor(p.user)} is sharing their screen`" />
                      <AudioLines v-if="p.audio_sharing" class="h-3 w-3 text-primary" :title="`${nameFor(p.user)} is sharing audio`" />
                      <MicOff v-if="p.muted" class="h-3 w-3 text-destructive" :title="`${nameFor(p.user)} is muted`" />
                      <HeadphoneOff v-if="p.deafened" class="h-3 w-3 text-destructive" :title="`${nameFor(p.user)} is deafened — they can't hear the call`" />
                    </span>
                  </NuxtLink>
                </div>

                <!-- A discussion inside an unfolded channel. Indented past the channel's icon,
                     and deliberately plainer than a channel row: no rename/delete, no lock
                     column, no second chevron. The branch is a list of conversations, not a
                     second sidebar. -->
                <div v-else-if="item.kind === 'discussion'">
                  <div class="group/disc relative">
                    <NuxtLink
                      :to="`/servers/${item.channel.server_id}/channels/${item.channel.id}`"
                      draggable="true"
                      class="mx-2 flex items-center gap-2 rounded py-1 pl-12 pr-2 text-sm hover:bg-muted"
                      @dragstart="writeDragPayload($event, {
                        channelId: item.channel.id,
                        title: item.channel.name,
                        type: item.channel.type,
                        path: `/servers/${item.channel.server_id}/channels/${item.channel.id}`,
                      })"
                      v-on="openBeside({
                        channelId: item.channel.id,
                        title: item.channel.name,
                        type: item.channel.type,
                        path: `/servers/${item.channel.server_id}/channels/${item.channel.id}`,
                      })"
                      :class="item.channel.id === activeChannelId
                        ? 'bg-muted font-medium text-foreground'
                        : item.channel.unread_count
                          ? 'font-semibold text-foreground'
                          : 'text-muted-foreground'"
                    >
                      <MessagesSquare class="h-3.5 w-3.5 shrink-0" />
                      <span class="truncate">{{ item.channel.name }}</span>
                      <Lock v-if="item.channel.is_private" class="h-3 w-3 shrink-0 text-muted-foreground" title="Only chosen members" />
                      <span
                        v-if="hasDraft(item.channel.id) && item.channel.id !== activeChannelId"
                        class="ml-auto shrink-0 text-[10px] font-medium italic text-primary"
                        title="You have an unsent draft here"
                      >Draft</span>
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

                    <!-- Staff-only rename/delete, the same pair a channel row carries. Hover-
                         revealed for a mouse; always present on touch, where there is no hover
                         to reveal them with. -->
                    <span
                      v-if="item.isOwner"
                      class="absolute right-3 top-1/2 -translate-y-1/2 items-center gap-0.5 rounded bg-muted px-0.5"
                      :class="coarse ? 'flex' : 'hidden group-hover/disc:flex'"
                    >
                      <button
                        class="rounded text-muted-foreground hover:text-foreground"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`Rename ${item.channel.name}`"
                        @click.prevent="askRenameChannel(item.channel)"
                      >
                        <Pencil class="h-3.5 w-3.5" />
                      </button>
                      <button
                        v-if="item.canDelete"
                        class="rounded text-muted-foreground hover:text-destructive"
                        :class="coarse ? 'p-2' : 'p-1'"
                        :title="`Delete ${item.channel.name}`"
                        @click.prevent="askDeleteChannel(item.channel)"
                      >
                        <Trash2 class="h-3.5 w-3.5" />
                      </button>
                    </span>
                  </div>

                  <!-- Whoever is already talking in this discussion's call. -->
                  <NuxtLink
                    v-for="p in item.voice"
                    :key="p.user.id"
                    :to="`/servers/${item.channel.server_id}/channels/${item.channel.id}`"
                    class="mx-2 flex items-center gap-2 rounded py-0.5 pl-16 pr-2 text-xs text-muted-foreground hover:bg-muted"
                  >
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                      <img v-if="p.user.avatar" :src="p.user.avatar" :alt="nameFor(p.user)" class="h-full w-full rounded-full object-cover">
                      <span v-else>{{ initialsOf(nameFor(p.user)) }}</span>
                    </span>
                    <span class="truncate">{{ nameFor(p.user) }}</span>
                    <span class="ml-auto flex shrink-0 items-center gap-1">
                      <ScreenShare v-if="p.screen_sharing" class="h-3 w-3 text-primary" :title="`${nameFor(p.user)} is sharing their screen`" />
                      <AudioLines v-if="p.audio_sharing" class="h-3 w-3 text-primary" :title="`${nameFor(p.user)} is sharing audio`" />
                      <MicOff v-if="p.muted" class="h-3 w-3 text-destructive" :title="`${nameFor(p.user)} is muted`" />
                      <HeadphoneOff v-if="p.deafened" class="h-3 w-3 text-destructive" :title="`${nameFor(p.user)} is deafened — they can't hear the call`" />
                    </span>
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
            <DropdownMenuItem @select="askEditProfile">
              <User class="mr-2 h-4 w-4" /> Profile
            </DropdownMenuItem>
            <!--
              Encryption keys. Reachable from the account menu rather than from any one
              conversation, because it is an account-wide answer to "what happens on a new
              device" — and somebody who needs it most is often looking at a channel full of
              messages they cannot read.
            -->
            <DropdownMenuItem @select="showKeyBackup = true">
              <KeyRound class="mr-2 h-4 w-4" /> Encryption keys
            </DropdownMenuItem>
            <DropdownMenuItem class="text-destructive focus:text-destructive" @select="logout">
              <LogOut class="mr-2 h-4 w-4" /> Sign out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </aside>

    <!--
      Main content, and — when you've dragged a channel onto it — the docked second pane.

      The route always owns the *left* half: split view borrows the page that was already
      there rather than taking over navigation, which is why every link, deep link and back
      button carries on working without knowing this exists. See useSplitView.

      The drop zone is the whole main area, lit only while one of our channel drags is over
      it, so there's nothing to aim at until there's something to drop.

      On a narrow screen the two panes stop being columns and become **pages**: the same two
      children, laid out full-width in a horizontally snapping scroller you swipe between.
      Two 190px columns is worse than one, but "this, while I watch that" is still the task —
      it just becomes a flick rather than a glance. Only the presentation changes; the state
      (`useSplitView`) is the same one the desktop divider drives, so a split set up on a
      laptop is the pair of pages you swipe between on the phone.
    -->
    <div
      ref="splitArea"
      class="relative flex min-w-0 flex-1"
      :class="pagerSplit && 'snap-x snap-mandatory overflow-x-auto overscroll-x-contain'"
      @dragover="onSplitDragOver"
      @dragleave="onSplitDragLeave"
      @drop="onSplitDrop"
    >
      <main
        class="flex min-w-0 flex-col"
        :class="pagerSplit ? 'snap-start' : 'flex-1'"
        :style="pagerSplit ? { flex: '0 0 100%' } : undefined"
      >
        <slot />
      </main>

      <!-- The docked conversation as the second page. No divider: there is no ratio to set
           when each page is the whole screen. -->
      <!-- `flex` by inline style for the same reason the docked pane sets it: SplitPane's own
           root carries `flex-1`, and only a style beats it. -->
      <SplitPane
        v-if="pagerSplit"
        :pane="pane!"
        class="snap-start"
        :style="{ flex: '0 0 100%' }"
        @close="closeSplit"
        @promote="promotePane"
      />

      <template v-else-if="pane">
        <!-- The divider. `select-none` because a drag that starts on a 5px strip otherwise
             ends up selecting the messages either side of it. -->
        <div
          class="w-1 shrink-0 cursor-col-resize select-none bg-border transition hover:bg-primary/60"
          :class="draggingDivider && 'bg-primary/60'"
          role="separator"
          aria-label="Resize split view"
          @pointerdown="startDividerDrag"
        />
        <SplitPane
          :pane="pane"
          :style="{ flex: `0 0 ${Math.round(ratio * 100)}%` }"
          @close="closeSplit"
          @promote="promotePane"
        />
      </template>

      <!-- Where the dragged channel will land. Covers the right half only: the left half is
           the page you're on, and dropping a channel onto it would mean navigating, which is
           what the sidebar link you're dragging already does with a plain click. -->
      <div
        v-if="dropActive"
        class="pointer-events-none absolute inset-y-0 right-0 z-40 flex w-1/2 items-center justify-center border-l-2 border-dashed border-primary bg-primary/10"
      >
        <span class="rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground shadow">
          Drop to open beside {{ activeChannelId ? 'this channel' : 'this chat' }}
        </span>
      </div>
    </div>

    <!-- The ringing phone. Lives here, not in a page, because a call has to reach you
         wherever you are — including in a conversation you have never once opened. -->
    <IncomingCall />

    <!-- "Which screen?" on the desktop build, where the browser's own picker doesn't exist.
         Here rather than in a call, because a share can be started from a voice channel, a DM
         call or a Side Space, and all three go through one getDisplayMedia. Inert elsewhere. -->
    <ScreenSourcePicker />

    <!-- "Can I drive?" — the consent prompt, the banner while someone is, and the asker's own
         waiting state. Here rather than on the stage because, like a call, a control session
         outlives the page it started on: you can wander into another channel while someone is
         still holding your mouse, and the way to take it back has to come with you. -->
    <RemoteControlPrompt />

    <!-- The floating-window shelf — popped-out widgets, conversations, and the pinned music
         player. Mounted here (not inside a page) so a floated window outlives the page it was
         opened from: a video keeps playing, a chat keeps updating, and the pinned song follows
         you across channels, DMs, groups and servers. Music renders through the shelf now, so
         the old standalone MusicDock is gone. -->
    <!-- On a phone the shelf is still here; it's the chrome that changes — a bubble, a
         full-screen sheet, or (for music) a bar docked along the bottom. See FloatingFrame. -->
    <FloatingWindows />

    <!-- ⌘K. Mounted here, beside the shelf, for the same reason: it has to be reachable from
         every screen in the app, and the thing it navigates to is usually not the page it was
         opened from. -->
    <SearchPalette ref="palette" />

    <!-- Who may be in a channel (staff), and who runs the server (owner). Mounted here
         beside the shelf so they survive the sidebar row that opened them being re-rendered. -->
    <ChannelAccessDialog v-if="accessChannel" :channel="accessChannel" @close="accessChannel = null" />
    <KeyBackupDialog v-if="showKeyBackup" @close="showKeyBackup = false" />
    <ServerRolesDialog v-if="rolesServer" :server="rolesServer" :channel-id="activeChannelId ?? channels[0]?.id ?? null" @close="rolesServer = null" />
    <ServerBotsDialog v-if="botsServer" :server="botsServer" @close="botsServer = null" />
    <ServerBotDashboard
      v-if="botDashboardServer"
      :server="botDashboardServer"
      :channels="channels"
      @close="botDashboardServer = null"
    />

    <NewChatDialog v-model:open="showNewChat" />

    <!-- Your own nickname in a server, reached from that server's menu. -->
    <NicknameDialog
      v-model:open="showNickname"
      :member="user"
      :current-user-id="user?.id ?? null"
    />

    <Dialog v-model:open="showInvite">
      <DialogContent v-if="inviteServer">
        <DialogHeader>
          <DialogTitle>Invite people to {{ inviteServer.name }}</DialogTitle>
          <DialogDescription>
            Share this link. Anyone who opens it can request to join — a member has to
            approve them before they're let in.
          </DialogDescription>
        </DialogHeader>
        <div class="flex items-center gap-2">
          <Input :model-value="inviteServer.invite_url" readonly class="flex-1 font-mono text-xs" />
          <Button variant="outline" size="icon" :title="copied ? 'Copied!' : 'Copy link'" @click="copyInvite">
            <Check v-if="copied" class="h-4 w-4 text-green-600 dark:text-green-400" />
            <Copy v-else class="h-4 w-4" />
          </Button>
        </div>
      </DialogContent>
    </Dialog>

    <!-- Your display name. The email below it is what the account is keyed on, so it's shown
         for recognition but isn't editable here. -->
    <Dialog v-model:open="showProfile">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Profile</DialogTitle>
          <DialogDescription>
            Your display name is what everyone else sees you by — on messages, in calls, and
            in every chat you're in.
          </DialogDescription>
        </DialogHeader>
        <form class="space-y-3" @submit.prevent="onSaveProfile">
          <label class="block space-y-1">
            <span class="text-sm font-medium">Display name</span>
            <Input v-model="nameDraft" placeholder="Your name" maxlength="50" autofocus />
          </label>
          <label class="block space-y-1">
            <span class="text-sm font-medium">Email</span>
            <Input :model-value="user?.email ?? ''" readonly class="text-muted-foreground" />
          </label>
          <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
          <div class="flex justify-end gap-2">
            <Button type="button" variant="outline" :disabled="working" @click="showProfile = false">
              Cancel
            </Button>
            <Button type="submit" :disabled="working || !nameDraft.trim()">
              {{ working ? 'Saving…' : 'Save' }}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>

    <!-- Rename the server (owner) -->
    <Dialog v-model:open="showRenameServer">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Server settings</DialogTitle>
          <DialogDescription>Everyone in the server sees these.</DialogDescription>
        </DialogHeader>
        <form class="space-y-3" @submit.prevent="onRenameServer">
          <label class="block space-y-1">
            <span class="text-sm font-medium">Name</span>
            <Input v-model="nameDraft" placeholder="Server name" maxlength="100" autofocus />
          </label>
          <label class="block space-y-1">
            <span class="text-sm font-medium">Who can start discussions</span>
            <select v-model="discussionPolicyDraft" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
              <option value="everyone">Anyone in the server</option>
              <option value="staff">Only the owner and admins</option>
            </select>
            <span class="block text-xs text-muted-foreground">
              A discussion is a separate conversation inside a channel, with its own messages,
              threads and Side Desk.
            </span>
          </label>
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
    <NewDiscussionDialog
      v-model:open="showNewDiscussion"
      :parent="newDiscussionParent"
      @created="onDiscussionCreated"
    />

    <Dialog v-model:open="showRenameChannel">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Rename {{ targetChannel?.parent_id ? 'discussion' : 'channel' }}</DialogTitle>
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
            This can’t be undone. The
            {{ targetChannel?.parent_id ? 'discussion’s' : 'channel’s' }} messages, threads and
            uploaded files are permanently deleted, for everyone.
            <template v-if="targetChannel?.parent_id">
              The channel’s other discussions are untouched.
            </template>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
          <Button variant="destructive" :disabled="working" @click="onDeleteChannel">
            {{ working ? 'Deleting…' : targetChannel?.parent_id ? 'Delete discussion' : 'Delete channel' }}
          </Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Entrance and exit effects, drawn over everything. Here rather than on the call's
         own page for the same reason VoiceBar is: somebody arriving while you're off
         reading another channel still has to be seen to arrive. -->
    <VoiceEffects />
  </div>
</template>
