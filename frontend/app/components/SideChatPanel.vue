<script setup lang="ts">
import { CheckCircle2, Info, Loader2, MessageSquare, MessagesSquare, PenTool, Pin, Plus, Rocket, UserPlus, Users, X } from 'lucide-vue-next'
import type { GifResult, Message } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * The side chat *workspace* — the right-hand panel that keeps the main chat visible while a
 * side chat runs alongside it. The list and create modes are a thread panel's; the view
 * mode is where a side chat pulls decisively ahead of a thread: it's a tabbed workspace, not
 * just a timeline.
 *
 *   - **Chat** — the conversation, its roster, its decisions and pins.
 *   - **Info** — the side chat about itself: who's here, where it came from, what it decided.
 *   - **Whiteboard** — a shared, persistent, real-time board (see Whiteboard).
 *
 * And because a side chat can own threads of its own, opening one leaves this panel in place
 * and adds a second column beside it — the ThreadPanel, scoped to this side chat. Which is
 * why the query helpers here *merge* rather than replace: the two columns share the URL.
 */
const props = defineProps<{ channelId: number }>()
const route = useRoute()
const { user } = useAuth()

const { sideChats, loadSideChats, createSideChat, join, leave } = useSideChats()
const {
  sideChat, messages, highlights, hasMore, loadingOlder,
  loadSideChat, loadOlder, ensureLoaded,
  send, edit, remove, toggleReaction, togglePin, toggleDecision,
  subscribe, unsubscribe,
} = useSideChatMessages()
const {
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()

const mode = computed<'list' | 'create' | 'view' | null>(() => {
  if (route.query.sidechats === '1') return 'list'
  if (route.query.sidechat === 'new') return 'create'
  if (route.query.sidechat) return 'view'
  return null
})
const activeId = computed(() => (mode.value === 'view' ? Number(route.query.sidechat) : null))
const fromMessageId = computed(() => (route.query.from ? Number(route.query.from) : null))

const TABS = [
  { key: 'chat', label: 'Chat', icon: MessageSquare },
  { key: 'info', label: 'Info', icon: Info },
  { key: 'board', label: 'Board', icon: PenTool },
] as const
const sctab = computed<'chat' | 'info' | 'board'>(() => {
  const t = route.query.sctab
  return t === 'info' || t === 'board' ? t : 'chat'
})

const joined = computed(() =>
  !!user.value && (sideChat.value?.participant_ids?.includes(user.value.id) ?? false),
)

const newName = ref('')
const creating = ref(false)
const joining = ref(false)
const showAddPeople = ref(false)
const sending = ref(false)
const replyingTo = ref<Message | null>(null)
// The message the forward picker is open for, or null when it's closed.
const forwardTarget = ref<Message | null>(null)
const scroller = ref<any>(null)
const highlightedMessageId = ref<number | null>(null)
let highlightTimer: ReturnType<typeof setTimeout> | undefined

function scrollBottom() {
  nextTick(() => scroller.value?.scrollToItem(messages.value.length - 1))
}

async function onJumpToReply(id: number) {
  // Jumping to a message always lands on the Chat tab.
  if (sctab.value !== 'chat') setQuery({ sctab: null })
  const found = await ensureLoaded(id)
  if (!found) return
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

/** Replace the whole query — for entering/leaving the workspace outright. */
function goto(query: Record<string, string>) {
  navigateTo({ path: route.path, query })
}
/** Merge into the current query — so a thread column can stay open alongside a tab switch. */
function setQuery(patch: Record<string, string | null>) {
  const q: Record<string, string> = {}
  for (const [k, v] of Object.entries(route.query)) if (typeof v === 'string') q[k] = v
  for (const [k, v] of Object.entries(patch)) {
    if (v === null) delete q[k]
    else q[k] = v
  }
  navigateTo({ path: route.path, query: q })
}
function close() {
  navigateTo({ path: route.path, query: {} })
}

// Threads live in a second column; opening one keeps this workspace open.
function openThreads() {
  setQuery({ threads: '1', thread: null, from: null })
}
function onCreateThread(messageId: number) {
  setQuery({ threads: null, thread: 'new', from: String(messageId) })
}
function onOpenThread(id: number) {
  setQuery({ threads: null, thread: String(id), from: null })
}

async function submitCreate() {
  const name = newName.value.trim()
  if (!name || creating.value) return
  creating.value = true
  try {
    const s = await createSideChat(props.channelId, { name, message_id: fromMessageId.value ?? null })
    newName.value = ''
    goto({ sidechat: String(s.id) })
  } finally {
    creating.value = false
  }
}

async function onJoin() {
  if (!activeId.value || joining.value) return
  joining.value = true
  try {
    await join(activeId.value)
  } finally {
    joining.value = false
  }
}

async function onLeave() {
  if (!activeId.value) return
  await leave(activeId.value)
}

async function onSend(body: string, files: File[], gif?: GifResult) {
  if (!activeId.value || sending.value) return
  sending.value = true
  try {
    await send(activeId.value, body, replyingTo.value?.id ?? null, files, gif)
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
    unsubscribeTyping(`sidechat.${subscribedId}`)
    unsubscribe(subscribedId)
    subscribedId = null
  }
}

watch(
  () => [mode.value, activeId.value] as const,
  async () => {
    teardown()
    replyingTo.value = null
    if (mode.value === 'list') {
      await loadSideChats(props.channelId)
    } else if (mode.value === 'view' && activeId.value) {
      await loadSideChat(activeId.value)
      subscribe(activeId.value)
      subscribeTyping(`sidechat.${activeId.value}`)
      subscribedId = activeId.value
      scrollBottom()
    }
  },
  { immediate: true },
)

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid) scrollBottom()
})
onBeforeUnmount(teardown)

const roster = computed(() => sideChat.value?.participants ?? [])
const hasHighlights = computed(() =>
  highlights.value.decisions.length > 0 || highlights.value.pinned.length > 0,
)

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
function excerpt(body: string | null) {
  const text = (body ?? '').replace(/\s+/g, ' ').trim()
  return text.length > 80 ? `${text.slice(0, 80)}…` : text || '(no text)'
}
function relTime(iso: string) {
  const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (secs < 60) return 'just now'
  const mins = Math.round(secs / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  return hrs < 24 ? `${hrs}h ago` : `${Math.round(hrs / 24)}d ago`
}
</script>

<template>
  <aside class="flex w-[380px] shrink-0 flex-col border-l">
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex min-w-0 items-center gap-2 font-semibold">
        <Rocket class="h-4 w-4 text-muted-foreground" />
        <span v-if="mode === 'list'">Side Chats</span>
        <span v-else-if="mode === 'create'">New side chat</span>
        <span v-else class="truncate">{{ sideChat?.name ?? 'Side Chat' }}</span>
      </div>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <!-- LIST -->
    <div v-if="mode === 'list'" class="flex-1 overflow-y-auto p-2">
      <Button variant="outline" size="sm" class="mb-2 w-full gap-1.5" @click="goto({ sidechat: 'new' })">
        <Plus class="h-4 w-4" /> New side chat
      </Button>
      <button
        v-for="s in sideChats"
        :key="s.id"
        class="block w-full rounded p-2 text-left hover:bg-muted"
        @click="goto({ sidechat: String(s.id) })"
      >
        <div class="text-sm font-medium">{{ s.name }}</div>
        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
          <span class="flex items-center gap-1"><Users class="h-3 w-3" /> {{ s.participants_count ?? 0 }}</span>
          <span>· {{ s.messages_count ?? 0 }} messages</span>
          <span v-if="(s.decisions_count ?? 0) > 0" class="flex items-center gap-1">
            · <CheckCircle2 class="h-3 w-3" /> {{ s.decisions_count }}
          </span>
          <span>· {{ relTime(s.last_active_at) }}</span>
        </div>
      </button>
      <p v-if="!sideChats.length" class="p-3 text-sm text-muted-foreground">No side chats yet.</p>
    </div>

    <!-- CREATE -->
    <form v-else-if="mode === 'create'" class="space-y-3 p-4" @submit.prevent="submitCreate">
      <p class="text-sm text-muted-foreground">
        {{ fromMessageId ? 'Spin a side chat off this message.' : 'Start a new side chat in this channel.' }}
      </p>
      <Input v-model="newName" placeholder="e.g. Dashboard Redesign" autofocus />
      <Button type="submit" class="w-full" :disabled="!newName.trim() || creating">
        {{ creating ? 'Creating…' : 'Create side chat' }}
      </Button>
    </form>

    <!-- VIEW: the tabbed workspace -->
    <template v-else-if="mode === 'view'">
      <!-- Tab bar -->
      <nav class="flex shrink-0 border-b">
        <button
          v-for="t in TABS"
          :key="t.key"
          class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2 text-sm transition-colors"
          :class="sctab === t.key
            ? 'border-primary font-medium text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground'"
          @click="setQuery({ sctab: t.key === 'chat' ? null : t.key })"
        >
          <component :is="t.icon" class="h-4 w-4" /> {{ t.label }}
        </button>
      </nav>

      <!-- CHAT (kept mounted so its scroll position and subscription survive tab switches) -->
      <div v-show="sctab === 'chat'" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-2">
          <div class="flex items-center -space-x-1.5">
            <span
              v-for="m in roster.slice(0, 5)"
              :key="m.id"
              class="grid h-6 w-6 place-items-center overflow-hidden rounded-full border-2 border-background bg-primary text-[9px] font-semibold text-primary-foreground"
              :title="m.name"
            >
              <img v-if="m.avatar" :src="m.avatar" :alt="m.name" class="h-full w-full object-cover">
              <span v-else>{{ initials(m.name) }}</span>
            </span>
            <span
              v-if="(sideChat?.participants_count ?? 0) > 5"
              class="grid h-6 min-w-6 place-items-center rounded-full border-2 border-background bg-muted px-1 text-[9px] font-semibold text-muted-foreground"
            >
              +{{ (sideChat?.participants_count ?? 0) - 5 }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <!-- Browse this side chat's own threads — opens the scoped list in the second column. -->
            <button
              class="flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
              title="Threads in this side chat"
              @click="openThreads"
            >
              <MessagesSquare class="h-3.5 w-3.5" /> Threads
              <span v-if="(sideChat?.threads_count ?? 0) > 0" class="font-semibold">· {{ sideChat?.threads_count }}</span>
            </button>
            <span class="flex items-center gap-1 text-xs text-muted-foreground">
              <Users class="h-3.5 w-3.5" /> {{ sideChat?.participants_count ?? 0 }}
            </span>
            <button
              v-if="joined"
              class="flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
              title="Add people"
              @click="showAddPeople = true"
            >
              <UserPlus class="h-3.5 w-3.5" /> Add
            </button>
          </div>
        </div>

        <div v-if="hasHighlights" class="m-3 mb-0 shrink-0 rounded-lg border bg-muted/30 p-2 text-sm">
          <div v-if="highlights.decisions.length" class="mb-1.5">
            <div class="mb-1 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
              <CheckCircle2 class="h-3.5 w-3.5" /> Decisions · {{ highlights.decisions.length }}
            </div>
            <button
              v-for="d in highlights.decisions"
              :key="d.id"
              class="block w-full truncate rounded px-1.5 py-1 text-left text-xs hover:bg-muted"
              :title="d.body ?? ''"
              @click="onJumpToReply(d.id)"
            >
              <span class="font-medium">{{ d.user.name }}:</span> {{ excerpt(d.body) }}
            </button>
          </div>
          <div v-if="highlights.pinned.length">
            <div class="mb-1 flex items-center gap-1 text-xs font-semibold text-primary">
              <Pin class="h-3.5 w-3.5" /> Pinned · {{ highlights.pinned.length }}
            </div>
            <button
              v-for="p in highlights.pinned"
              :key="p.id"
              class="block w-full truncate rounded px-1.5 py-1 text-left text-xs hover:bg-muted"
              :title="p.body ?? ''"
              @click="onJumpToReply(p.id)"
            >
              <span class="font-medium">{{ p.user.name }}:</span> {{ excerpt(p.body) }}
            </button>
          </div>
        </div>

        <div v-if="sideChat?.parent_message" class="m-3 mb-0 shrink-0 rounded-lg border bg-muted/40 p-3 text-sm">
          <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
          <span class="font-medium">{{ sideChat.parent_message.user.name }}</span>
          <MarkdownBody v-if="sideChat.parent_message.body" :source="sideChat.parent_message.body" />
        </div>
        <div v-else-if="sideChat?.origin_author" class="m-3 mb-0 shrink-0 rounded-lg border border-dashed bg-muted/20 p-3 text-sm">
          <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
          <span class="font-medium">{{ sideChat.origin_author }}</span>
          <p v-if="sideChat.origin_excerpt" class="text-muted-foreground">{{ sideChat.origin_excerpt }}</p>
          <p class="mt-1 text-[11px] italic text-muted-foreground">The original message was deleted.</p>
        </div>

        <p v-if="!messages.length" class="p-3 text-sm text-muted-foreground">No messages yet. Start the conversation.</p>

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
                  :size-dependencies="[item.body, item.reply_to, item.started_thread, item.edited, item.attachments, item.reactions, item.comments, item.link_previews, item.pinned, item.decided]"
                >
                  <MessageItem
                    :message="item"
                    :current-user-id="user?.id ?? null"
                    thread-actions
                    side-chat-actions
                    forwardable
                    :joined="joined"
                    :highlighted="item.id === highlightedMessageId"
                    @reply="replyingTo = $event"
                    @save="edit"
                    @remove="remove"
                    @create-thread="onCreateThread"
                    @open-thread="onOpenThread"
                    @jump-to-reply="onJumpToReply"
                    @toggle-reaction="toggleReaction"
                    @toggle-pin="togglePin"
                    @toggle-decision="toggleDecision"
                    @forward="forwardTarget = $event"
                  />
                </DynamicScrollerItem>
              </template>
            </DynamicScroller>
          </ClientOnly>
        </div>

        <div class="shrink-0 border-t">
          <div v-if="!joined" class="p-3">
            <Button class="w-full" :disabled="joining" @click="onJoin">
              {{ joining ? 'Joining…' : 'Join this side chat to take part' }}
            </Button>
          </div>
          <template v-else>
            <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
              <span class="truncate">Replying to <span class="font-medium">{{ replyingTo.user.name }}</span></span>
              <button class="hover:text-foreground" @click="replyingTo = null"><X class="h-3.5 w-3.5" /></button>
            </div>
            <TypingIndicator :label="typingLabel" />
            <MessageComposer placeholder="Message…" :sending="sending" @submit="onSend" @typing="notifyTyping" />
          </template>
        </div>
      </div>

      <!-- INFO -->
      <SideChatInfo
        v-if="sctab === 'info'"
        :side-chat="sideChat"
        :highlights="highlights"
        :joined="joined"
        @jump="onJumpToReply"
        @add-people="showAddPeople = true"
        @leave="onLeave"
      />

      <!-- WHITEBOARD -->
      <Whiteboard
        v-if="sctab === 'board' && activeId"
        :key="activeId"
        :base-path="`/api/side-chats/${activeId}/whiteboard`"
        :stream-name="`sidechat.${activeId}`"
        :can-draw="joined"
        readonly-hint="Join this side chat to draw"
      />
    </template>

    <SideChatAddPeopleDialog
      v-if="mode === 'view' && activeId"
      v-model:open="showAddPeople"
      :side-chat-id="activeId"
      :channel-id="channelId"
      :existing-ids="sideChat?.participant_ids ?? []"
    />

    <!-- Forward a message from this side chat into another chat or channel. -->
    <ForwardDialog v-model:message="forwardTarget" />
  </aside>
</template>
