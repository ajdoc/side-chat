<script setup lang="ts">
import { ArrowDown, Loader2 } from 'lucide-vue-next'
import type { FloatingConversationWindow } from '~/composables/useFloatingWindows'
import type { GifResult, Message } from '~/types'
import { mentionNamesKey, useChannelMembers } from '~/composables/useChannelMembers'

/**
 * A conversation, floated. Every conversation — server channel, DM, group — is a channel, so
 * this is a compact {@link ChannelView}: its own {@link useMessages} instance (the composable
 * holds message state in plain refs, one set per call), a scrollable list of {@link MessageItem}
 * and a {@link MessageComposer}. Threads, side chats and the Side Space aren't offered here —
 * those are full-column surfaces that belong to the main view, not a 360px window — so the
 * message rows are opened without those actions.
 *
 * It runs its own live subscription on the channel stream. Floating a chat you're *also* looking
 * at in the main column is pointless and not the use case (you float one to keep half an eye on
 * it while you're elsewhere), so the rare double-subscribe isn't worth guarding against.
 */
const props = defineProps<{ win: FloatingConversationWindow }>()

const { user } = useAuth()
const { messages, hasMore, loadingOlder, load, loadOlder, ensureLoaded, send, edit, remove, toggleReaction, togglePin, subscribe, unsubscribe } = useMessages()
const { members: mentionMembers, names: mentionNames, load: loadMembers } = useChannelMembers()
provide(mentionNamesKey, mentionNames)

const channelId = computed(() => props.win.channelId)

const scroller = ref<HTMLElement | null>(null)
const sending = ref(false)
const replyingTo = ref<Message | null>(null)
const atBottom = ref(true)
const highlightedId = ref<number | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | undefined

function nearBottom(el: HTMLElement, threshold = 80) {
  return el.scrollHeight - el.scrollTop - el.clientHeight <= threshold
}

function onScroll() {
  const el = scroller.value
  if (!el) return
  atBottom.value = nearBottom(el)
  // Reached the top with older history above — page it in, holding the reading position.
  if (el.scrollTop <= 4 && hasMore.value && !loadingOlder.value) {
    const prevHeight = el.scrollHeight
    void loadOlder().then(() => nextTick(() => {
      if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight - prevHeight
    }))
  }
}

function scrollToBottom() {
  nextTick(() => {
    const el = scroller.value
    if (el) el.scrollTop = el.scrollHeight
    atBottom.value = true
  })
}

async function onJumpToReply(id: number) {
  const found = await ensureLoaded(id)
  if (!found) return
  await nextTick()
  const el = scroller.value?.querySelector(`[data-mid="${id}"]`) as HTMLElement | null
  el?.scrollIntoView({ block: 'center' })
  clearTimeout(highlightTimer)
  highlightedId.value = id
  highlightTimer = setTimeout(() => { highlightedId.value = null }, 1500)
}

async function onSend(body: string, files: File[], gif?: GifResult, uploadIds: string[] = []) {
  if (sending.value) return
  sending.value = true
  try {
    await send(body, replyingTo.value?.id ?? null, files, gif, uploadIds)
    replyingTo.value = null
    scrollToBottom()
  } finally {
    sending.value = false
  }
}

// Follow the conversation only while resting at its foot — reading history isn't yanked down.
watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid && atBottom.value) scrollToBottom()
})

let openedId: number | null = null
async function openChannel(id: number) {
  loadMembers(id)
  await load(id)
  subscribe(id)
  scrollToBottom()
}
function closeChannel(id: number) {
  unsubscribe(id)
}
async function sync() {
  const id = channelId.value
  if (openedId === id) return
  if (openedId) closeChannel(openedId)
  openedId = id
  await openChannel(id)
}

onMounted(sync)
watch(channelId, sync)
onBeforeUnmount(() => { if (openedId) closeChannel(openedId) })
</script>

<template>
  <div class="flex h-full flex-col">
    <div ref="scroller" class="relative min-h-0 flex-1 overflow-y-auto px-2 py-2" @scroll.passive="onScroll">
      <div v-if="loadingOlder" class="flex justify-center py-1">
        <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
      </div>
      <p v-if="!messages.length" class="p-3 text-xs text-muted-foreground">
        This is the beginning of <span class="font-medium">{{ win.title }}</span>. Say hello 👋
      </p>
      <div v-for="m in messages" :key="m.id" :data-mid="m.id">
        <MessageItem
          :message="m"
          :current-user-id="user?.id ?? null"
          forwardable
          :highlighted="m.id === highlightedId"
          @reply="replyingTo = $event"
          @save="edit"
          @remove="remove"
          @jump-to-reply="onJumpToReply"
          @toggle-reaction="toggleReaction"
          @toggle-pin="togglePin"
        />
      </div>
    </div>

    <!-- Jump to latest, while reading history. -->
    <div v-if="!atBottom" class="pointer-events-none relative">
      <button
        type="button"
        class="pointer-events-auto absolute bottom-1 right-3 flex items-center gap-1 rounded-full border bg-background px-2.5 py-1 text-[11px] font-medium shadow-md hover:bg-muted"
        @click="scrollToBottom"
      >
        Latest <ArrowDown class="h-3 w-3" />
      </button>
    </div>

    <div class="shrink-0 border-t">
      <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-3 py-1 text-[11px] text-muted-foreground">
        <span class="truncate">Replying to <span class="font-medium">{{ replyingTo.user.name }}</span></span>
        <button class="hover:text-foreground" @click="replyingTo = null">✕</button>
      </div>
      <MessageComposer
        :placeholder="`Message ${win.title}`"
        :sending="sending"
        :channel-id="channelId"
        :mention-members="mentionMembers"
        @submit="onSend"
      />
    </div>
  </div>
</template>
