<script setup lang="ts">
import { Loader2, X } from 'lucide-vue-next'
import type { Channel, Message } from '~/types'
import { Button } from '~/components/ui/button'

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

const channelId = computed(() => props.channel.id)

const threadPanelOpen = computed(() => !!(route.query.thread || route.query.threads))
const infoPanelOpen = computed(() => route.query.info === '1')

const sending = ref(false)
const replyingTo = ref<Message | null>(null)
const scroller = ref<any>(null)
const highlightedMessageId = ref<number | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | undefined

function scrollToBottom() {
  nextTick(() => scroller.value?.scrollToItem(messages.value.length - 1))
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

function onCreateThread(messageId: number) {
  navigateTo({ path: route.path, query: { thread: 'new', from: String(messageId) } })
}
function onOpenThread(id: number) {
  navigateTo({ path: route.path, query: { thread: String(id) } })
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
  await Promise.all([load(id), loadReads(id)])
  subscribe(id)
  subscribeReads(id)
  subscribeTyping(`channel.${id}`)
  markRead(messages.value.at(-1)?.id ?? null)
  emit('read')
  scrollToBottom()
}

function closeChannel(id: number) {
  unsubscribeTyping(`channel.${id}`)
  unsubscribeReads(id)
  unsubscribe(id)
}

async function onSend(body: string, files: File[]) {
  if (sending.value) return
  sending.value = true
  try {
    await send(body, replyingTo.value?.id ?? null, files)
    stopTyping()
    replyingTo.value = null
    scrollToBottom()
  } finally {
    sending.value = false
  }
}

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid) scrollToBottom()
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
        </div>
      </header>

      <!-- The call, when there is one. A voice channel's stage, or a chat's call banner —
           and everything below this line is unaware either exists. -->
      <slot name="call" />

      <p v-if="!messages.length" class="p-4 text-sm text-muted-foreground">
        <slot name="empty">
          This is the beginning of
          <span class="font-medium">{{ prefix }}{{ title }}</span>. Say hello 👋
        </slot>
      </p>

      <div class="relative min-h-0 flex-1">
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
            @scroll-start="hasMore && onScrollStart()"
          >
            <template #default="{ item, active }">
              <DynamicScrollerItem
                :item="item"
                :active="active"
                :size-dependencies="[
                  item.body, item.reply_to, item.started_thread, item.edited, item.attachments,
                  item.reactions, item.link_previews, item.pinned, readersByMessage[item.id],
                ]"
              >
                <MessageItem
                  :message="item"
                  :current-user-id="user?.id ?? null"
                  thread-actions
                  :highlighted="item.id === highlightedMessageId"
                  :readers="readersByMessage[item.id]"
                  @reply="replyingTo = $event"
                  @save="edit"
                  @remove="remove"
                  @create-thread="onCreateThread"
                  @open-thread="onOpenThread"
                  @jump-to-reply="onJumpToReply"
                  @toggle-reaction="toggleReaction"
                  @toggle-pin="togglePin"
                />
              </DynamicScrollerItem>
            </template>
          </DynamicScroller>
        </ClientOnly>
      </div>

      <div class="shrink-0 border-t">
        <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-4 py-1.5 text-xs text-muted-foreground">
          <span class="truncate">Replying to <span class="font-medium">{{ replyingTo.user.name }}</span></span>
          <button class="hover:text-foreground" @click="replyingTo = null"><X class="h-3.5 w-3.5" /></button>
        </div>
        <TypingIndicator :label="typingLabel" />
        <MessageComposer
          :placeholder="`Message ${prefix ?? ''}${title}`"
          :sending="sending"
          @submit="onSend"
          @typing="notifyTyping"
        />
      </div>
    </div>

    <ThreadPanel v-if="threadPanelOpen" :channel-id="channelId" />
    <!-- Reuses the reply-jump: page older history in, scroll to it, flash a highlight. -->
    <ChannelInfoPanel v-else-if="infoPanelOpen" :channel-id="channelId" @jump="onJumpToReply" />
  </div>
</template>
