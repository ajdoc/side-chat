<script setup lang="ts">
import { ArrowDown, Loader2, LockKeyhole, Menu, PictureInPicture2, Search, X } from 'lucide-vue-next'
import type { Channel, GifResult, Message } from '~/types'
import type { FloatingConversationIcon } from '~/composables/useFloatingWindows'
import type { SealedFile } from '~/lib/crypto/envelope'
import { Button } from '~/components/ui/button'
import { memberBadgesKey, mentionNamesKey, useChannelMembers } from '~/composables/useChannelMembers'
import { emoteOnly } from '~/lib/spaceEmotes'

/**
 * A channel's timeline: the messages, the composer, threads, pins, read receipts, typing.
 *
 * Lifted wholesale out of the server-channel page so that a DM could have it, because a
 * DM *is* a channel — a conversation owns one, and every composable below is addressed by
 * `channel.id` and has no idea whether that channel belongs to a server or to a chat with
 * one other person in it. That's the entire reason DMs cost so little: not one line of the
 * message stack knows they exist.
 *
 * What differs between the two lives in the slots. A server channel puts a Hash and a
 * Threads button in the header and a VoiceChannel stage on top; a chat puts an avatar, a
 * call button, and a "join the call" banner. Underneath, this is the same component.
 */
const props = defineProps<{
  channel: Channel
  title: string
  /** Prefixed to the title in the composer placeholder — "#" for a server text channel. */
  prefix?: string
  /** Shown under the title in the header (a group's member list, say). */
  subtitle?: string
  /** Which icon a floated copy of this conversation wears — a hash, a DM, or a group. */
  floatIcon?: FloatingConversationIcon
  /**
   * Fold the conversation away, leaving the header, whatever is in the `call` slot, and the
   * side panels.
   *
   * For a surface where the thing above the timeline is the point rather than an accessory —
   * a Side Space, where the room wants the whole window and the chat is what you turn to
   * between conversations. Everything stays *mounted* (see `v-show`): the subscription, the
   * scroll position, the draft in the composer and the unread bookkeeping all survive being
   * hidden, so folding the chat away and back is free and loses nothing.
   */
  collapseTimeline?: boolean
}>()

const emit = defineEmits<{ read: [] }>()

/**
 * Which side columns are open, and how opening one is expressed.
 *
 * The URL when this is the page you're on; the docked pane's own in-memory state when it
 * isn't. Everything below reads and writes it identically either way — see useSurfaceRoute.
 */
const surface = useSurfaceRoute()
const query = surface.query
const { user } = useAuth()
const { messages, hasMore, hasNewer, loadingOlder, encrypted, encryptFiles, load, loadOlder, ensureLoaded, jumpTo, returnToLatest, send, edit, remove, toggleReaction, togglePin, subscribe, unsubscribe } = useMessages()
const {
  readersByMessage,
  load: loadReads,
  markRead,
  markReadIfVisible,
  subscribe: subscribeReads,
  unsubscribe: unsubscribeReads,
} = useReads()
const {
  typists,
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()
// In a Side Space the same two facts — who's typing, and what they just said — are also drawn
// over people's heads in the room. Fed from here rather than from the stage because this is
// where both already arrive: a second set of whisper handlers on `channel.{id}` would come off
// with the first one torn down. See useSpaceChatBubbles.
const { noteTyping, forgetTyping, noteSaid, noteEmote, clearBubbles } = useSpaceChatBubbles()

const { members: mentionMembers, names: mentionNames, badges: memberBadges, load: loadMembers } = useChannelMembers()
// What `/` offers in this channel — built-ins plus whatever the bots here answer to.
const { commands: slashCommands, load: loadCommands } = useSlashCommands()
// Pop this conversation out into a floating window that follows you around the app.
const { open: openFloating, isConversationFloating } = useFloatingWindows()
function floatConversation() {
  openFloating({ kind: 'conversation', channelId: props.channel.id, title: props.title, icon: props.floatIcon ?? 'channel' })
}
// The header's Side Chats button reads this shared count; load it per channel so the badge
// is live from the moment you land, then keep it fresh over the channel stream.
const { sideChats, loadSideChats } = useSideChats()
// Below `md` the sidebar is a drawer, and the header carries the only handle back to it.
const { narrow, toggle: toggleDrawer } = useNavDrawer()
const { isMobile } = usePlatform()
// So a message body deep in the virtual list can render `@Name` as a chip without each
// MessageItem having to be handed the roster. See MarkdownBody / useChannelMembers.
provide(mentionNamesKey, mentionNames)
// Same reasoning: the author line on every message wants these, and the roster already
// has them scoped to this channel's server. See useChannelMembers.
provide(memberBadgesKey, memberBadges)

const channelId = computed(() => props.channel.id)

// Which messages open a new calendar day, and the label to print above them.
const daySeparators = useDaySeparators(messages)

// A thread started from *this* timeline lives under `thread`/`threads`. A side chat's own
// threads live under `scthread`/`scthreads` (see SideChatPanel), a separate namespace — which
// is the whole reason a main-chat thread and a side chat can now stand open at the same time
// instead of the one being read as a thread of the other.
const threadPanelOpen = computed(() => !!(query.value.thread || query.value.threads))
const sideChatThreadPanelOpen = computed(() => !!(query.value.scthread || query.value.scthreads))
const sideChatPanelOpen = computed(() => !!(query.value.sidechat || query.value.sidechats))
const infoPanelOpen = computed(() => query.value.info === '1')
const searchPanelOpen = computed(() => query.value.search === '1')
const deskPanelOpen = computed(() => !!query.value.desk)
// The open side chat's id, when one is in view mode — it scopes an alongside thread column
// to that side chat rather than the channel.
const activeSideChatId = computed(() => {
  const s = query.value.sidechat
  return s && s !== 'new' ? Number(s) : null
})

const showEncryption = ref(false)

/**
 * Encryption was just switched.
 *
 * The broadcast updates every *other* client, including our own other tabs — but the tab that
 * made the change is excluded from its own socket echo, so the composer here would keep the
 * old state until something else reloaded the timeline. Reloading is the simplest correct
 * answer: it re-reads the encryption block off the page and collects any keys the new era
 * needs, in one round trip.
 */
async function onEncryptionSaved() {
  await load(props.channel.id)
}

const sending = ref(false)
const replyingTo = ref<Message | null>(null)
// The message the forward picker is open for, or null when it's closed.
const forwardTarget = ref<Message | null>(null)
const scroller = ref<any>(null)
const highlightedMessageId = ref<number | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | undefined

// Whether the timeline is resting at (or very near) its foot, and whether messages have
// landed below the fold since we last were. Together they drive the "jump to latest"
// pill and decide whether an incoming message should pull the view down or stay put.
const atBottom = ref(true)
const hasNewBelow = ref(false)

/** The scrolling element itself — DynamicScroller's root *is* the scroll container. */
function scrollEl(): HTMLElement | null {
  return (scroller.value?.$el as HTMLElement | undefined) ?? null
}

function nearBottom(el: HTMLElement, threshold = 120) {
  return el.scrollHeight - el.scrollTop - el.clientHeight <= threshold
}

function onScroll() {
  const el = scrollEl()
  if (!el) return
  atBottom.value = nearBottom(el)
  if (atBottom.value) hasNewBelow.value = false
}

/**
 * Pin the view to the foot of the timeline.
 *
 * A single pass lands short: DynamicScroller measures item heights lazily, so at the
 * moment we ask, `scrollHeight` still reflects estimates for everything below the fold and
 * the channel opens a screen or two above the newest message. Nudging across frames lets
 * each freshly-measured row correct the target until it settles at the true bottom.
 *
 * A fixed handful of frames used to land short on longer or slower channels — the rows were
 * still measuring when we stopped pushing. So instead of counting frames, we keep pinning
 * until `scrollHeight` holds steady for a few frames running (measurement has settled), with
 * a generous ceiling only as a backstop against a channel that somehow never quiets down.
 */
function scrollToBottom() {
  let lastHeight = -1
  let steady = 0
  const step = (budget: number) => {
    const el = scrollEl()
    if (!el) return
    el.scrollTop = el.scrollHeight
    atBottom.value = true
    hasNewBelow.value = false

    steady = el.scrollHeight === lastHeight ? steady + 1 : 0
    lastHeight = el.scrollHeight
    if (budget > 0 && steady < 3) requestAnimationFrame(() => step(budget - 1))
  }
  nextTick(() => step(60))
}

/**
 * Back to the live end.
 *
 * Two different journeys behind one button. Usually the newest message is already loaded
 * and this is a scroll. After a search jump it isn't: the loaded window sits somewhere in
 * history with unfetched messages below it, so getting back is a refetch first.
 */
async function jumpToLatest() {
  if (hasNewer.value) {
    await returnToLatest()
    await nextTick()
  }
  scrollToBottom()
}

/** Put the view on a message that is already loaded, and flash it. */
function revealLoaded(id: number) {
  const idx = messages.value.findIndex(m => m.id === id)
  if (idx < 0) return
  nextTick(() => scroller.value?.scrollToItem(idx))
  clearTimeout(highlightTimer)
  highlightedMessageId.value = id
  highlightTimer = setTimeout(() => { highlightedMessageId.value = null }, 1500)
}

// Jump to a message referenced by a reply, paging in older history first if needed.
async function onJumpToReply(id: number) {
  const found = await ensureLoaded(id)
  if (!found) return // message was deleted or otherwise unavailable
  revealLoaded(id)
}

/**
 * Jump to a search result — which may be thousands of messages back, or in another channel.
 *
 * Paging backwards (what a reply's jump does) is hopeless at that distance, so this
 * re-anchors the loaded window on the message instead. A result in a *different* channel is
 * a navigation, not a jump: the row is a link, and the destination page picks the message up
 * from `?jump=` as it mounts.
 */
async function onJumpToSearchResult(id: number, targetChannelId: number) {
  if (targetChannelId !== props.channel.id) return
  const found = await jumpTo(id)
  // Deleted since it was indexed — the window still lands where it was, so say nothing and
  // leave the reader where the message used to be rather than bouncing them back.
  if (found) revealLoaded(id)
}

/**
 * Merge a patch into the current query (null deletes a key), instead of replacing it.
 *
 * This is what lets a thread and a side chat coexist: opening one preserves whatever else is
 * already standing, rather than wiping the URL. Info and Side Desk, being full-column
 * surfaces, are always cleared here — a thread or side chat takes precedence over them.
 */
function patchQuery(patch: Record<string, string | null>) {
  surface.patch({ info: null, desk: null, ...patch })
}

/**
 * Open or close the search column.
 *
 * Not routed through patchQuery: that clears `info` and `desk` on the way past, which is
 * right for a thread (it takes precedence over them) and wrong here — search is a peer of
 * those two, and the template already decides which of the three gets the column.
 */
function toggleSearchPanel() {
  surface.patch({ search: searchPanelOpen.value ? null : '1', info: null, desk: null })
}

function onCreateThread(messageId: number) {
  patchQuery({ thread: 'new', threads: null, from: String(messageId) })
}
function onOpenThread(id: number) {
  patchQuery({ thread: String(id), threads: null, from: null })
}
function onCreateSideChat(messageId: number) {
  patchQuery({ sidechat: 'new', sidechats: null, from: String(messageId) })
}
function onOpenSideChat(id: number) {
  patchQuery({ sidechat: String(id), sidechats: null, from: null, screply: null })
}

/**
 * Open a side chat *and* its reply box — the card's "Reply".
 *
 * Carried in the URL rather than through a prop or an event bus, because the panel that
 * has to act on it is a sibling: everything else about which side chat is open already
 * lives in the query, and this is one more fact of the same kind.
 */
function onReplyToSideChat(id: number) {
  patchQuery({ sidechat: String(id), sidechats: null, from: null, screply: '1' })
}

async function onScrollStart() {
  const anchorId = await loadOlder()
  if (anchorId != null) {
    nextTick(() => {
      const idx = messages.value.findIndex(m => m.id === anchorId)
      if (idx >= 0) scroller.value?.scrollToItem(idx)
    })
  }
}

async function openChannel(id: number) {
  replyingTo.value = null
  bubbledUpTo = 0 // a different channel's ids say nothing about this one's
  loadMembers(id) // for @mention autocomplete + chips; not worth blocking the timeline on
  loadCommands(id) // likewise for the `/` menu
  loadSideChats(id) // for the header badge; also not worth blocking the timeline on
  await Promise.all([load(id), loadReads(id)])
  subscribe(id)
  subscribeReads(id)
  subscribeTyping(`channel.${id}`)
  markRead(messages.value.at(-1)?.id ?? null)
  emit('read')

  // Everything that just landed was said before you got here. Set synchronously, in the same
  // turn as the load, so the watcher below can't slip in front of it — that race is why this
  // is a mark rather than a flag. Zero on an empty channel, which is right: the first thing
  // ever said in the room is newer than nothing.
  bubbledUpTo = messages.value.at(-1)?.id ?? 0

  // Arrived from a search result in another channel: the palette put the message id in the
  // URL because the page it navigates to mounts its own timeline, and this is the only
  // moment that timeline exists and knows where it was asked to land.
  const jump = Number(query.value.jump)
  if (jump) {
    // Consumed, not kept: a reload of this URL an hour later should show the conversation,
    // not silently re-anchor on a message the reader has long since dealt with.
    surface.patch({ jump: null })
    await onJumpToSearchResult(jump, id)
    return
  }

  scrollToBottom()
}

function closeChannel(id: number) {
  sideChats.value = [] // drop the old channel's count so the badge never flashes stale
  clearBubbles() // nothing said in the room you're leaving should be hanging over it when you return
  unsubscribeTyping(`channel.${id}`)
  unsubscribeReads(id)
  unsubscribe(id)
}

async function onSend(
  body: string,
  files: File[],
  gif?: GifResult,
  uploadIds: string[] = [],
  // Keys for the big files the composer already encrypted and staged — see its stage().
  uploadMeta: SealedFile[] = [],
) {
  if (sending.value) return
  sending.value = true
  try {
    await send(body, replyingTo.value?.id ?? null, files, gif, uploadIds, uploadMeta)
    stopTyping()
    /*
     * Your own line over your own head.
     *
     * Raised from what you typed, rather than from the message that comes back or from the
     * watcher below. Your send is never broadcast to you (suppressed by socket id — see
     * useMessages.send), so this is the only thing that knows you said it; and taking the text
     * straight off the composer keeps your own bubble clear of every question the *arrival* of
     * somebody else's message has to answer first — is it fresh, has the timeline finished
     * loading, is it a widget card. You typed it a moment ago. That's the whole test.
     */
    if (isSpace.value && user.value) {
      const emote = files.length ? null : emoteOnly(body)

      if (emote) noteEmote(user.value.id, emote)
      else if (body.trim()) noteSaid(user.value.id, body)
      else if (files.length) noteSaid(user.value.id, files.length > 1 ? '📎 sent some files' : '📎 sent a file')
    }
    replyingTo.value = null
    scrollToBottom()
  } finally {
    sending.value = false
  }
}

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  // Follow the conversation only while you're already at the foot of it; if you've scrolled
  // up to read history, a new message reveals the "jump to latest" pill instead of yanking
  // you away. (Your own sends scroll you down explicitly, from onSend.)
  if (nid && oid && nid > oid) {
    if (atBottom.value) scrollToBottom()
    else hasNewBelow.value = true
  }
  // Somebody else said something just now. Deliberately outside the guard above, which asks a
  // different question — it needs a *previous* message to compare against, and the first thing
  // ever said in a room is exactly the message most worth a bubble.
  if (nid && nid !== oid) raiseBubble(messages.value.at(-1))
  // Anything that arrives while you're looking at the channel is, by definition, read.
  markReadIfVisible(messages.value)
  emit('read')
})

/** Only a walkable room draws anyone's chat over their head; everywhere else this is inert. */
const isSpace = computed(() => props.channel.type === 'space')

/**
 * How recently a message must have been said to be worth a bubble.
 *
 * A bubble is "somebody just spoke", so the timeline arriving — on entry, on a search jump,
 * on returning to the live end — must not raise a roomful of them for a conversation that
 * happened this morning. Generous rather than tight because it's compared against the
 * *server's* clock: a minute of skew between two machines is unremarkable, and the cost of
 * being wrong the generous way is one stale bubble, against silently swallowing every real
 * one on a machine whose clock runs fast.
 */
const BUBBLE_FRESH_MS = 60_000

/**
 * The newest message id that was already here when we arrived, or that has already had its
 * moment over somebody's head.
 *
 * An id rather than a "the timeline has finished loading" flag, which is what this was: that
 * flag had to be raised on a tick, in a race with the very watcher it was gating, and a race
 * is a poor thing to hang a feature on. A highwater mark asks the question directly — is this
 * message newer than anything I've seen in this channel — and answers it the same way whether
 * the timeline is still settling or has been open for an hour. Reset per channel by
 * openChannel, which sets it the instant the history lands.
 */
let bubbledUpTo = 0

/**
 * A message, as a line over its author's avatar in the room.
 *
 * Everything a person says qualifies. System messages are the room talking about itself rather
 * than somebody saying something, and a widget is a card — neither has a line in it to read, so
 * those two are the exclusions and nothing else is. A message with only files attached *is*
 * somebody saying something, so it gets a bubble saying that much rather than nothing.
 */
function raiseBubble(m: Message | undefined) {
  if (!isSpace.value || !m) return

  // Anything at or below the mark is history, however it got here — the first page, a search
  // jump, the return to the live end. Raised here so a message is only ever bubbled once.
  if (m.id <= bubbledUpTo) return
  bubbledUpTo = m.id

  // Asked the way the rest of the app asks it — see Message::isSystem/isWidget and
  // MessageItem. A plain message carries no `type` at all rather than `'user'`, so testing
  // *for* the kind that speaks silently rejected every real message in the room.
  if (m.type === 'system' || m.type === 'widget' || !m.user) return
  if (Date.now() - Date.parse(m.created_at) > BUBBLE_FRESH_MS) return

  // A message that is nothing but emoji is an emote, and gets the glyph over the head rather
  // than a speech bubble with an emoji in it — the same message, drawn as what it is. Which is
  // the whole point of being able to send one from the composer: in a room it *is* a gesture.
  const emote = m.attachments?.length ? null : emoteOnly(m.body ?? '')
  if (emote) return noteEmote(m.user.id, emote)

  const line = m.body?.trim()
    || (m.attachments?.length ? (m.attachments.length > 1 ? '📎 sent some files' : '📎 sent a file') : '')
  if (line) noteSaid(m.user.id, line)
}

/**
 * A reaction in this channel pops over the reactor's head in the room.
 *
 * Wired here rather than in the stage for the same reason the said and typing bubbles are: this
 * is the component that owns the channel's stream, and a listener attached from the canvas
 * would be a second thing to tear down when either one of them goes.
 *
 * Gated on the channel, because a reaction landing in a DM you happen to also have open is not
 * something anybody in *this* room did. And gated on the room being a room at all — bubbles are
 * only ever drawn by a Side Space, so anywhere else this is bookkeeping for an absent canvas.
 *
 * Reacting is already an emote; it was only ever drawn in one of the two places you were
 * looking. See lib/spaceEmotes.ts.
 */
onScopeDispose(onReactionAdded((e) => {
  if (!isSpace.value || e.channelId !== props.channel.id) return

  noteEmote(e.userId, e.emoji)
}))

/**
 * Every keystroke: tell the channel, and — in a room — put the dots over your own head too.
 *
 * Your own bubble is drawn locally because the whisper deliberately never comes back to you,
 * and a room where everybody's typing is visible except yours is one where you can't tell
 * whether the thing is working.
 */
function onTyping() {
  notifyTyping()
  if (isSpace.value && user.value) noteTyping(user.value.id)
}

// Who's typing, as a bubble of dots. Deep, because useTyping re-stamps an existing typist in
// place while they keep going — and that re-stamp is exactly what has to keep the bubble up.
watch(typists, (now, before) => {
  if (!isSpace.value) return

  for (const t of now) noteTyping(t.id)
  for (const gone of (before ?? []).filter(b => !now.some(t => t.id === b.id))) forgetTyping(gone.id)
}, { deep: true })

// Coming back to a tab you left open counts as reading what piled up while you were away.
function onVisibilityChange() {
  markReadIfVisible(messages.value)
  emit('read')
}

/**
 * The channel we currently have loaded and subscribed, or null.
 *
 * Tracked explicitly rather than derived from the route: on a cold load the route names a
 * channel before the channel list has arrived, and tearing down by route id would then try
 * to close a subscription that was never opened.
 */
let openedId: number | null = null

async function syncChannel() {
  const id = channelId.value
  if (openedId === id) return

  if (openedId) closeChannel(openedId)

  openedId = id
  await openChannel(id)
}

onMounted(() => {
  document.addEventListener('visibilitychange', onVisibilityChange)
  syncChannel()
})
watch(channelId, syncChannel)
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  if (openedId) closeChannel(openedId)
})
</script>

<template>
  <div class="flex min-h-0 flex-1">
    <div class="flex min-w-0 flex-1 flex-col">
      <!--
        Two groups that both want the whole bar.

        On a phone the actions (Call, Side chats, Threads, Side Desk, Info, the group menu)
        are wider than the screen on their own. Held at their natural width they pushed the
        title and the drawer button off the left edge and sat on top of them, so instead the
        actions get a bounded slice of the header and scroll sideways inside it, and the title
        keeps the rest. On a wide screen everything fits and nothing scrolls.
      -->
      <header class="flex h-12 shrink-0 items-center justify-between gap-1 border-b px-2 sm:px-4">
        <div class="flex min-w-0 flex-1 items-center gap-2">
          <!-- On a narrow screen the sidebar is a drawer, and this is the only way back to it. -->
          <button
            v-if="narrow"
            type="button"
            class="-ml-1 shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            title="Channels and chats"
            @click="toggleDrawer"
          >
            <Menu class="h-5 w-5" />
          </button>
          <slot name="icon" />
          <div class="min-w-0">
            <!-- Which channel this conversation is a discussion of, and the way out to its
                 siblings. Above the title rather than beside it: the discussion's name is what
                 you're reading, and the channel is the context you're reading it in. -->
            <slot name="breadcrumb" />
            <p class="truncate font-semibold leading-tight">{{ title }}</p>
            <p v-if="subtitle" class="truncate text-xs leading-tight text-muted-foreground">
              {{ subtitle }}
            </p>
          </div>
        </div>
        <!-- `[&>*]:shrink-0` is what makes it scroll rather than squash: without it the
             buttons would just compress to fit and never overflow the strip. -->
        <div class="scroll-strip flex max-w-[65%] shrink items-center gap-1 [&>*]:shrink-0 md:max-w-none md:shrink-0 md:overflow-visible">
          <slot name="actions" />
          <!-- Search this conversation. First in the action strip, before the slotted
               buttons, so it keeps the same spot in a channel, a DM and a Side Space rather
               than sliding around with whatever else that surface offers. -->
          <button
            type="button"
            class="rounded p-1.5 transition-colors hover:bg-muted"
            :class="searchPanelOpen ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
            title="Search this conversation"
            @click="toggleSearchPanel"
          >
            <Search class="h-4 w-4" />
          </button>
          <!--
            Encryption for *this* timeline.

            On the timeline rather than in the sidebar because that is where the setting
            applies: a channel is a container of discussions, each with its own switch, and a
            padlock on the sidebar row would be a padlock on a conversation nobody is having.
            Lit when on, so the state is legible without opening anything.
          -->
          <button
            type="button"
            class="rounded p-1.5 transition-colors hover:bg-muted"
            :class="encrypted ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground hover:text-foreground'"
            :title="encrypted ? 'End-to-end encrypted — change' : 'Encryption is off'"
            @click="showEncryption = true"
          >
            <LockKeyhole class="h-4 w-4" />
          </button>
          <button
            v-if="!isMobile"
            type="button"
            class="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            :title="isConversationFloating(channel.id) ? 'Already floating — brings it to the front' : 'Float this chat in a window'"
            @click="floatConversation"
          >
            <PictureInPicture2 class="h-4 w-4" />
          </button>
        </div>
      </header>

      <!-- The call, when there is one. A voice channel's stage, or a chat's call banner —
           and everything below this line is unaware either exists. -->
      <slot name="call" />

      <p v-if="!messages.length && !collapseTimeline" class="p-4 text-sm text-muted-foreground">
        <slot name="empty">
          This is the beginning of
          <span class="font-medium">{{ prefix }}{{ title }}</span>. Say hello 👋
        </slot>
      </p>

      <div v-show="!collapseTimeline" class="relative min-h-0 flex-1">
        <div v-if="loadingOlder" class="absolute inset-x-0 top-0 z-10 flex justify-center py-1">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
        <ClientOnly>
          <DynamicScroller
            ref="scroller"
            class="h-full px-2 py-2"
            :items="messages"
            :min-item-size="52"
            key-field="id"
            @scroll.passive="onScroll"
            @scroll-start="hasMore && onScrollStart()"
          >
            <template #default="{ item, active }">
              <DynamicScrollerItem
                :item="item"
                :active="active"
                :size-dependencies="[
                  item.body, item.reply_to, item.forwarded_from, item.started_thread, item.started_side_chat, item.edited, item.attachments,
                  item.reactions, item.comments, item.link_previews, item.pinned, readersByMessage[item.id],
                  daySeparators.get(item.id),
                ]"
              >
                <!-- Day divider: printed above the first message of each calendar day, so
                     scrolling back through history keeps its bearings. Part of this row's
                     measured height (hence the size-dependency above), which is what keeps
                     it correct under virtual scrolling and prepended history. -->
                <div v-if="daySeparators.get(item.id)" class="relative my-2 flex items-center justify-center">
                  <div class="absolute inset-x-2 top-1/2 h-px bg-border" />
                  <span class="relative rounded-full border bg-background px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                    {{ daySeparators.get(item.id) }}
                  </span>
                </div>

                <MessageItem
                  :message="item"
                  :current-user-id="user?.id ?? null"
                  thread-actions
                  side-chat-create
                  side-chat-actions
                  forwardable
                  :highlighted="item.id === highlightedMessageId"
                  :readers="readersByMessage[item.id]"
                  @reply="replyingTo = $event"
                  @save="edit"
                  @remove="remove"
                  @create-thread="onCreateThread"
                  @open-thread="onOpenThread"
                  @create-side-chat="onCreateSideChat"
                  @open-side-chat="onOpenSideChat"
                  @reply-to-side-chat="onReplyToSideChat"
                  @jump-to-reply="onJumpToReply"
                  @toggle-reaction="toggleReaction"
                  @toggle-pin="togglePin"
                  @forward="forwardTarget = $event"
                />
              </DynamicScrollerItem>
            </template>
          </DynamicScroller>
        </ClientOnly>

        <!-- Jump to latest: only while you're reading history, so it never covers the newest
             message. Carries a dot when something arrived below you while you were up here.

             `hasNewer` forces it on regardless of scroll position: after a search jump the
             loaded window ends mid-history, so you can be at the bottom of it and still be
             months behind — and this pill is the only way back. -->
        <Transition
          enter-active-class="transition duration-150"
          leave-active-class="transition duration-150"
          enter-from-class="translate-y-2 opacity-0"
          leave-to-class="translate-y-2 opacity-0"
        >
          <button
            v-if="!atBottom || hasNewer"
            type="button"
            class="absolute bottom-3 right-4 z-10 flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-xs font-medium shadow-md hover:bg-muted"
            @click="jumpToLatest"
          >
            <span v-if="hasNewBelow" class="h-2 w-2 shrink-0 rounded-full bg-primary" />
            {{ hasNewBelow ? 'New messages' : 'Jump to latest' }}
            <ArrowDown class="h-3.5 w-3.5" />
          </button>
        </Transition>
      </div>

      <div v-show="!collapseTimeline" class="shrink-0 border-t">
        <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-4 py-1.5 text-xs text-muted-foreground">
          <span class="truncate">Replying to <span class="font-medium">{{ replyingTo.user.name }}</span></span>
          <button class="hover:text-foreground" @click="replyingTo = null"><X class="h-3.5 w-3.5" /></button>
        </div>
        <!--
          The padlock, above the composer where somebody is about to type.

          Says what is true *now*, which is the one thing the timeline can't: the messages on
          screen each carry their own state, and a channel that was locked an hour ago may not
          be any more. It reads from `encrypted`, which the server sends with every page.
        -->
        <p
          v-if="encrypted"
          class="flex items-center gap-1.5 px-4 py-1 text-xs text-muted-foreground"
        >
          <LockKeyhole class="size-3 shrink-0" />
          <span>
            <template v-if="encryptFiles">End-to-end encrypted — files included.</template>
            <template v-else>End-to-end encrypted. <strong>Files are not encrypted</strong> on this server.</template>
            Search, bots and link previews are off for new messages, and a GIF from the picker
            is a link to its provider rather than an encrypted file.
          </span>
        </p>
        <TypingIndicator :label="typingLabel" />
        <MessageComposer
          :placeholder="`Message ${prefix ?? ''}${title}`"
          :sending="sending"
          :channel-id="channelId"
          :encrypted="encrypted && encryptFiles"
          :mention-members="mentionMembers"
          :commands="slashCommands"
          @submit="onSend"
          @typing="onTyping"
        />
      </div>
    </div>

    <!-- A thread started from this timeline, right beside it and scoped to the channel. It no
         longer cares whether a side chat is open — that used to reinterpret it as a thread of
         the side chat; now the two have separate query keys and simply stand side by side. -->
    <ThreadPanel v-if="threadPanelOpen" :channel-id="channelId" :side-chat-id="null" />

    <!-- The side chat workspace and, to its right, a thread scoped to that side chat. Both
         share the URL, so the side chat and its own thread can stand open together. -->
    <SideChatPanel v-if="sideChatPanelOpen" :channel-id="channelId" />
    <ThreadPanel
      v-if="sideChatThreadPanelOpen && activeSideChatId != null"
      :channel-id="channelId"
      :side-chat-id="activeSideChatId"
    />

    <!-- Searching this conversation. Like Info and Side Desk it takes the whole side column,
         so it yields to a thread or a side chat rather than stacking beside them. -->
    <SearchPanel
      v-if="searchPanelOpen && !threadPanelOpen && !sideChatPanelOpen"
      :channel-id="channelId"
      @jump="onJumpToSearchResult"
    />

    <!-- Info and Side Desk each take the whole side column, so they yield to a thread or a
         side chat rather than stack beside them. Info reuses the reply-jump. -->
    <ChannelInfoPanel
      v-if="infoPanelOpen && !threadPanelOpen && !sideChatPanelOpen && !searchPanelOpen"
      :channel-id="channelId"
      @jump="onJumpToReply"
    />
    <SideDeskPanel
      v-if="deskPanelOpen && !threadPanelOpen && !sideChatPanelOpen && !searchPanelOpen"
      :channel-id="channelId"
      @jump="onJumpToReply"
    />

    <!-- Forward a message from this timeline into another chat or channel. -->
    <ForwardDialog v-model:message="forwardTarget" />

    <!--
      The encryption switch. Refuses to open on a container — a channel that holds discussions
      has no timeline of its own to encrypt, and the endpoint would reject it anyway (see
      ToggleEncryptionRequest), so offering the dialog would be offering a dead end.
    -->
    <EncryptionDialog
      v-if="showEncryption"
      :channel="channel"
      @close="showEncryption = false"
      @saved="onEncryptionSaved"
    />
  </div>
</template>
