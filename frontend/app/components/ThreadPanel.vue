<script setup lang="ts">
import { Loader2, MessagesSquare, SendHorizontal, X } from 'lucide-vue-next'
import type { Message } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

const props = defineProps<{ channelId: number }>()
const route = useRoute()
const { user } = useAuth()

const { threads, loadThreads, createThread } = useThreads()
const { thread, messages, gone, hasMore, loadingOlder, loadThread, loadOlder, ensureLoaded, send, edit, remove, toggleReaction, togglePin, subscribe, unsubscribe } = useThreadMessages()
const {
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()

const mode = computed<'list' | 'create' | 'view' | null>(() => {
  if (route.query.threads === '1') return 'list'
  if (route.query.thread === 'new') return 'create'
  if (route.query.thread) return 'view'
  return null
})
const activeThreadId = computed(() => (mode.value === 'view' ? Number(route.query.thread) : null))
const fromMessageId = computed(() => (route.query.from ? Number(route.query.from) : null))

const newName = ref('')
const creating = ref(false)
const sending = ref(false)
const replyingTo = ref<Message | null>(null)
const scroller = ref<any>(null)
const highlightedMessageId = ref<number | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | undefined

function scrollBottom() {
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

async function onScrollStart() {
  const anchorId = await loadOlder()
  if (anchorId != null) {
    nextTick(() => {
      const idx = messages.value.findIndex(m => m.id === anchorId)
      if (idx >= 0) scroller.value?.scrollToItem(idx)
    })
  }
}
function goto(query: Record<string, string>) {
  navigateTo({ path: route.path, query })
}
function close() {
  navigateTo({ path: route.path, query: {} })
}

async function submitCreate() {
  const name = newName.value.trim()
  if (!name || creating.value) return
  creating.value = true
  try {
    const t = await createThread(props.channelId, { name, message_id: fromMessageId.value ?? null })
    newName.value = ''
    goto({ thread: String(t.id) })
  } finally {
    creating.value = false
  }
}

async function onSend(body: string, files: File[]) {
  if (!activeThreadId.value || sending.value) return
  sending.value = true
  try {
    await send(activeThreadId.value, body, replyingTo.value?.id ?? null, files)
    stopTyping()
    replyingTo.value = null
    scrollBottom()
  } finally {
    sending.value = false
  }
}

let subscribedId: number | null = null
function teardown() {
  if (subscribedId) {
    unsubscribeTyping(`thread.${subscribedId}`)
    unsubscribe(subscribedId)
    subscribedId = null
  }
}

watch(
  () => [mode.value, activeThreadId.value] as const,
  async () => {
    teardown()
    replyingTo.value = null
    if (mode.value === 'list') {
      await loadThreads(props.channelId)
    } else if (mode.value === 'view' && activeThreadId.value) {
      await loadThread(activeThreadId.value)
      subscribe(activeThreadId.value)
      subscribeTyping(`thread.${activeThreadId.value}`)
      subscribedId = activeThreadId.value
      scrollBottom()
    }
  },
  { immediate: true },
)

// Thread was deleted (its parent message was removed) — close the panel.
watch(gone, (v) => { if (v) close() })

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid) scrollBottom()
})
onBeforeUnmount(teardown)
</script>

<template>
  <aside class="flex w-[360px] shrink-0 flex-col border-l">
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex items-center gap-2 font-semibold">
        <MessagesSquare class="h-4 w-4 text-muted-foreground" />
        <span v-if="mode === 'list'">Threads</span>
        <span v-else-if="mode === 'create'">New thread</span>
        <span v-else class="truncate">{{ thread?.name ?? 'Thread' }}</span>
      </div>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <!-- LIST -->
    <div v-if="mode === 'list'" class="flex-1 overflow-y-auto p-2">
      <button
        v-for="t in threads"
        :key="t.id"
        class="block w-full rounded p-2 text-left hover:bg-muted"
        @click="goto({ thread: String(t.id) })"
      >
        <div class="text-sm font-medium">{{ t.name }}</div>
        <div class="text-xs text-muted-foreground">
          {{ t.replies_count ?? 0 }} {{ (t.replies_count ?? 0) === 1 ? 'reply' : 'replies' }}
          <template v-if="t.creator"> · started by {{ t.creator.name }}</template>
        </div>
      </button>
      <p v-if="!threads.length" class="p-3 text-sm text-muted-foreground">No threads yet.</p>
    </div>

    <!-- CREATE -->
    <form v-else-if="mode === 'create'" class="space-y-3 p-4" @submit.prevent="submitCreate">
      <p class="text-sm text-muted-foreground">
        {{ fromMessageId ? 'Start a thread from this message.' : 'Start a new thread in this channel.' }}
      </p>
      <Input v-model="newName" placeholder="Thread name" autofocus />
      <Button type="submit" class="w-full" :disabled="!newName.trim() || creating">
        {{ creating ? 'Creating…' : 'Create thread' }}
      </Button>
    </form>

    <!-- VIEW -->
    <template v-else-if="mode === 'view'">
      <div class="flex min-h-0 flex-1 flex-col">
        <div v-if="thread?.parent_message" class="m-3 mb-0 shrink-0 rounded-lg border bg-muted/40 p-3 text-sm">
          <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
          <span class="font-medium">{{ thread.parent_message.user.name }}</span>
          <MarkdownBody v-if="thread.parent_message.body" :source="thread.parent_message.body" />
        </div>

        <p v-if="!messages.length" class="p-3 text-sm text-muted-foreground">No replies yet. Start the conversation.</p>

        <div class="relative min-h-0 flex-1">
          <div v-if="loadingOlder" class="absolute inset-x-0 top-0 z-10 flex justify-center py-1">
            <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
          
          <ClientOnly>
            <DynamicScroller
              ref="scroller"
              class="h-full px-1 py-1"
              :items="messages"
              :min-item-size="48"
              key-field="id"
              @scroll-start="hasMore && onScrollStart()"
            >
              <template #default="{ item, active }">
                <DynamicScrollerItem
                  :item="item"
                  :active="active"
                  :size-dependencies="[item.body, item.reply_to, item.edited, item.attachments, item.reactions, item.comments, item.link_previews, item.pinned]"
                >
                  <MessageItem
                    :message="item"
                    :current-user-id="user?.id ?? null"
                    :highlighted="item.id === highlightedMessageId"
                    @reply="replyingTo = $event"
                    @save="edit"
                    @remove="remove"
                    @jump-to-reply="onJumpToReply"
                    @toggle-reaction="toggleReaction"
                    @toggle-pin="togglePin"
                  />
                </DynamicScrollerItem>
              </template>
            </DynamicScroller>
          </ClientOnly>
        </div>
      </div>

      <div class="shrink-0 border-t">
        <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
          <span class="truncate">Replying to <span class="font-medium">{{ replyingTo.user.name }}</span></span>
          <button class="hover:text-foreground" @click="replyingTo = null"><X class="h-3.5 w-3.5" /></button>
        </div>
        <TypingIndicator :label="typingLabel" />
        <MessageComposer placeholder="Reply…" :sending="sending" @submit="onSend" @typing="notifyTyping" />
      </div>
    </template>
  </aside>
</template>
