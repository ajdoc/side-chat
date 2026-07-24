<script setup lang="ts">
import { ArrowDown, Loader2, PictureInPicture2, X } from 'lucide-vue-next'
import type { Channel, GifResult, Message } from '~/types'
import type { FloatingConversationIcon } from '~/composables/useFloatingWindows'
import { Button } from '~/components/ui/button'
import { mentionNamesKey, useChannelMembers } from '~/composables/useChannelMembers'

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

const route = useRoute()
const { user } = useAuth()
const { messages, hasMore, loadingOlder, load, loadOlder, ensureLoaded, send, edit, remove, toggleReaction, togglePin, subscribe, unsubscribe } = useMessages()
const {
  readersByMessage,
  load: loadReads,
  markRead,
  markReadIfVisible,
  subscribe: subscribeReads,
  unsubscribe: unsubscribeReads,
} = useReads()
const {
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()

const { members: mentionMembers, names: mentionNames, load: loadMembers } = useChannelMembers()
// Pop this conversation out into a floating window that follows you around the app.
const { open: openFloating, isConversationFloating } = useFloatingWindows()
function floatConversation() {
  openFloating({ kind: 'conversation', channelId: props.channel.id, title: props.title, icon: props.floatIcon ?? 'channel' })
}
// The header's Side Chats button reads this shared count; load it per channel so the badge
// is live from the moment you land, then keep it fresh over the channel stream.
const { sideChats, loadSideChats } = useSideChats()
// So a message body deep in the virtual list can render `@Name` as a chip without each
// MessageItem having to be handed the roster. See MarkdownBody / useChannelMembers.
provide(mentionNamesKey, mentionNames)

const channelId = computed(() => props.channel.id)

// Which messages open a new calendar day, and the label to print above them.
const daySeparators = useDaySeparators(messages)

// A thread started from *this* timeline lives under `thread`/`threads`. A side chat's own
// threads live under `scthread`/`scthreads` (see SideChatPanel), a separate namespace — which
// is the whole reason a main-chat thread and a side chat can now stand open at the same time
// instead of the one being read as a thread of the other.
const threadPanelOpen = computed(() => !!(route.query.thread || route.query.threads))
const sideChatThreadPanelOpen = computed(() => !!(route.query.scthread || route.query.scthreads))
const sideChatPanelOpen = computed(() => !!(route.query.sidechat || route.query.sidechats))
const infoPanelOpen = computed(() => route.query.info === '1')
const deskPanelOpen = computed(() => !!route.query.desk)
// The open side chat's id, when one is in view mode — it scopes an alongside thread column
// to that side chat rather than the channel.
const activeSideChatId = computed(() => {
  const s = route.query.sidechat
  return typeof s === 'string' && s !== 'new' ? Number(s) : null
})

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

function jumpToLatest() {
  scrollToBottom()
}

// Jump to a message referenced by a reply, paging in older history first if needed.
async function onJumpToReply(id: number) {
  const found = await ensureLoaded(id)
  if (!found) return // message was deleted or otherwise unavailable
  const idx = messages.value.findIndex(m => m.id === id)
  if (idx < 0) return
  nextTick(() => scroller.value?.scrollToItem(idx))
  clearTimeout(highlightTimer)
  highlightedMessageId.value = id
  highlightTimer = setTimeout(() => { highlightedMessageId.value = null }, 1500)
}

/**
 * Merge a patch into the current query (null deletes a key), instead of replacing it.
 *
 * This is what lets a thread and a side chat coexist: opening one preserves whatever else is
 * already standing, rather than wiping the URL. Info and Side Desk, being full-column
 * surfaces, are always cleared here — a thread or side chat takes precedence over them.
 */
function patchQuery(patch: Record<string, string | null>) {
  navigateTo({ path: route.path, query: mergeQuery(route.query, { info: null, desk: null, ...patch }) })
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
  patchQuery({ sidechat: String(id), sidechats: null, from: null })
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
  loadMembers(id) // for @mention autocomplete + chips; not worth blocking the timeline on
  loadSideChats(id) // for the header badge; also not worth blocking the timeline on
  await Promise.all([load(id), loadReads(id)])
  subscribe(id)
  subscribeReads(id)
  subscribeTyping(`channel.${id}`)
  markRead(messages.value.at(-1)?.id ?? null)
  emit('read')
  scrollToBottom()
}

function closeChannel(id: number) {
  sideChats.value = [] // drop the old channel's count so the badge never flashes stale
  unsubscribeTyping(`channel.${id}`)
  unsubscribeReads(id)
  unsubscribe(id)
}

async function onSend(body: string, files: File[], gif?: GifResult, uploadIds: string[] = []) {
  if (sending.value) return
  sending.value = true
  try {
    await send(body, replyingTo.value?.id ?? null, files, gif, uploadIds)
    stopTyping()
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
  // Anything that arrives while you're looking at the channel is, by definition, read.
  markReadIfVisible(messages.value)
  emit('read')
})

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
      <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
        <div class="flex min-w-0 items-center gap-2">
          <slot name="icon" />
          <div class="min-w-0">
            <p class="truncate font-semibold leading-tight">{{ title }}</p>
            <p v-if="subtitle" class="truncate text-xs leading-tight text-muted-foreground">
              {{ subtitle }}
            </p>
          </div>
        </div>
        <div class="flex shrink-0 items-center gap-1">
          <slot name="actions" />
          <button
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
             message. Carries a dot when something arrived below you while you were up here. -->
        <Transition
          enter-active-class="transition duration-150"
          leave-active-class="transition duration-150"
          enter-from-class="translate-y-2 opacity-0"
          leave-to-class="translate-y-2 opacity-0"
        >
          <button
            v-if="!atBottom"
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
        <TypingIndicator :label="typingLabel" />
        <MessageComposer
          :placeholder="`Message ${prefix ?? ''}${title}`"
          :sending="sending"
          :channel-id="channelId"
          :mention-members="mentionMembers"
          @submit="onSend"
          @typing="notifyTyping"
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

    <!-- Info and Side Desk each take the whole side column, so they yield to a thread or a
         side chat rather than stack beside them. Info reuses the reply-jump. -->
    <ChannelInfoPanel
      v-if="infoPanelOpen && !threadPanelOpen && !sideChatPanelOpen"
      :channel-id="channelId"
      @jump="onJumpToReply"
    />
    <SideDeskPanel
      v-if="deskPanelOpen && !threadPanelOpen && !sideChatPanelOpen"
      :channel-id="channelId"
      @jump="onJumpToReply"
    />

    <!-- Forward a message from this timeline into another chat or channel. -->
    <ForwardDialog v-model:message="forwardTarget" />
  </div>
</template>
