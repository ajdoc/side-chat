<script setup lang="ts">
import { ChevronDown, ChevronLeft, Loader2, MessagesSquare, Pencil, SendHorizontal, Trash2, Users, X } from 'lucide-vue-next'
import type { GifResult, Message, Thread } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * `sideChatId` scopes the panel to a side chat's own threads: it opens as a *second* column
 * beside the side chat workspace, lists/creates threads under `side-chats/{id}/threads`, and
 * closing it returns to the side chat rather than clearing the whole workspace. Unset, it's
 * the channel's Threads panel exactly as before.
 */
const props = defineProps<{ channelId: number, sideChatId?: number | null }>()

// Draggable, remembered width (its left border carries the handle).
// Below `md` there is no room for a column beside the timeline: the panel takes the
// whole screen instead, and its own close button is the way back.
const { narrow } = useNavDrawer()
const { width: panelWidth, startResize } = useResizable('thread', 360, { min: 280, max: 640 })
// The URL on the page you're on, a docked pane's own state inside one. See useSurfaceRoute.
const surface = useSurfaceRoute()
const { user } = useAuth()
// Names follow whatever people are called in this server or chat — see useNicknames.
const { nameFor } = useNicknames()

const { threads, sideChatThreads, loadThreads, createThread, loadSideChatThreads, createSideChatThread, renameThread, deleteThread } = useThreads()
const scoped = computed(() => props.sideChatId != null)
const list = computed(() => (scoped.value ? sideChatThreads.value : threads.value))
const { thread, messages, gone, hasMore, loadingOlder, loadThread, loadOlder, ensureLoaded, send, edit, remove, toggleReaction, togglePin, subscribe, unsubscribe } = useThreadMessages()

// Which replies open a new calendar day, and the label to print above them.
const daySeparators = useDaySeparators(messages)
// A thread has no roster of its own — its participants are the people in the parent channel.
const { members: participants, load: loadParticipants } = useChannelMembers()
const {
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()

// Each scope owns its own query keys, so the channel's thread column and a side chat's own
// thread column can be open at once without overwriting each other. The channel thread keeps
// the original `thread`/`threads`/`from`; a side chat's threads live under `sc…`.
const keys = computed(() => (scoped.value
  ? { view: 'scthread', list: 'scthreads', from: 'scfrom' }
  : { view: 'thread', list: 'threads', from: 'from' }))

const mode = computed<'list' | 'create' | 'view' | null>(() => {
  const q = surface.query.value
  if (q[keys.value.list] === '1') return 'list'
  if (q[keys.value.view] === 'new') return 'create'
  if (q[keys.value.view]) return 'view'
  return null
})
const activeThreadId = computed(() => (mode.value === 'view' ? Number(surface.query.value[keys.value.view]) : null))
const fromMessageId = computed(() => {
  const v = surface.query.value[keys.value.from]
  return v ? Number(v) : null
})

const newName = ref('')
const creating = ref(false)
const sending = ref(false)
const replyingTo = ref<Message | null>(null)
// The reply the forward picker is open for, or null when it's closed.
const forwardTarget = ref<Message | null>(null)
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
/** Merge into the current query, so any other open column (a side chat, the other thread) stays. */
function setQuery(patch: Record<string, string | null>) {
  surface.patch(patch)
}
/** Open a thread in this scope's keys; merging leaves any other column standing. */
function openThread(id: number) {
  setQuery({ [keys.value.view]: String(id), [keys.value.list]: null, [keys.value.from]: null })
}
/**
 * Back to this scope's thread list.
 *
 * Its own keys only, like everything else here: the channel's thread column and a side
 * chat's are two independent columns sharing one URL, and pressing Back on either must
 * not disturb the other.
 */
function backToList() {
  setQuery({ [keys.value.list]: '1', [keys.value.view]: null, [keys.value.from]: null })
}

/** Close just this column — its own keys — so a side chat (or the other thread) beside it stays. */
function close() {
  setQuery({ [keys.value.view]: null, [keys.value.list]: null, [keys.value.from]: null })
}

async function submitCreate() {
  const name = newName.value.trim()
  if (!name || creating.value) return
  creating.value = true
  try {
    const payload = { name, message_id: fromMessageId.value ?? null }
    const t = scoped.value
      ? await createSideChatThread(props.sideChatId!, payload)
      : await createThread(props.channelId, payload)
    newName.value = ''
    openThread(t.id)
  } finally {
    creating.value = false
  }
}

// --- managing a thread from the *list*, without opening it ---------------
// Held as the row itself: the rename seeds from its name and the confirmation quotes it.
const renamingRow = ref<Thread | null>(null)
const deletingRow = ref<Thread | null>(null)
const rowName = ref('')
const rowBusy = ref(false)
// Touch has no hover to reveal the controls with, so there they're always on.
const coarse = import.meta.client && window.matchMedia?.('(pointer: coarse)').matches

function startRowRename(t: Thread) {
  rowName.value = t.name
  deletingRow.value = null
  renamingRow.value = t
}

async function submitRowRename(t: Thread) {
  const name = rowName.value.trim()
  if (!name || rowBusy.value) return
  rowBusy.value = true
  try {
    await renameThread(t.id, name)
    renamingRow.value = null
  } finally {
    rowBusy.value = false
  }
}

async function submitRowDelete(t: Thread) {
  if (rowBusy.value) return
  rowBusy.value = true
  try {
    await deleteThread(t.id)
  } finally {
    rowBusy.value = false
    deletingRow.value = null
  }
}

// --- rename / delete of the *open* thread (creator, or the server's staff) ---
const renaming = ref(false)
const renameName = ref('')
const savingName = ref(false)
const confirmDelete = ref(false)
const deleting = ref(false)

function startRename() {
  renameName.value = thread.value?.name ?? ''
  confirmDelete.value = false
  renaming.value = true
}

async function submitRename() {
  const name = renameName.value.trim()
  if (!name || !thread.value || savingName.value) return
  savingName.value = true
  try {
    const next = await renameThread(thread.value.id, name)
    // The header reads `thread`, which useThreadMessages owns; its `.ThreadUpdated`
    // handler will do this too, but not before the round trip we're already inside.
    thread.value = { ...thread.value, name: next.name }
    renaming.value = false
  } finally {
    savingName.value = false
  }
}

async function submitDelete() {
  if (!thread.value || deleting.value) return
  deleting.value = true
  try {
    await deleteThread(thread.value.id)
    close() // the panel is showing a thread that no longer exists
  } finally {
    deleting.value = false
    confirmDelete.value = false
  }
}

// Leaving a thread (or switching to another) drops any half-finished rename or confirmation:
// they're about *this* thread, and carrying them across would aim them at the next one.
watch(activeThreadId, () => { renaming.value = false; confirmDelete.value = false })

async function onSend(body: string, files: File[], gif?: GifResult, uploadIds: string[] = []) {
  if (!activeThreadId.value || sending.value) return
  sending.value = true
  try {
    await send(activeThreadId.value, body, replyingTo.value?.id ?? null, files, gif, uploadIds)
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
  () => [mode.value, activeThreadId.value, props.sideChatId] as const,
  async () => {
    teardown()
    replyingTo.value = null
    if (mode.value === 'list') {
      scoped.value ? await loadSideChatThreads(props.sideChatId!) : await loadThreads(props.channelId)
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

// The parent channel's roster, for the Participants disclosure. Cached per channel, so
// re-requesting as you open threads costs nothing.
watch(() => props.channelId, id => loadParticipants(id), { immediate: true })

// Thread was deleted (its parent message was removed) — close the panel.
watch(gone, (v) => { if (v) close() })

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid) scrollBottom()
})
onBeforeUnmount(teardown)
</script>

<template>
  <aside
    class="flex flex-col border-l bg-background"
    :class="narrow ? 'safe-inset fixed inset-0 z-50 w-full' : 'relative shrink-0'"
    :style="narrow ? undefined : { width: `${panelWidth}px` }"
  >
    <ResizeHandle v-if="!narrow" edge="left" @resize="startResize" />
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex min-w-0 items-center gap-2 font-semibold">
        <!-- Back to the list. Opening a thread replaces it in this one column, so without
             this the only way out is ✕ — which closes the column and loses your place. -->
        <button
          v-if="mode !== 'list'"
          class="-ml-1 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
          title="Back to all threads"
          aria-label="Back to all threads"
          @click="backToList"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>
        <MessagesSquare v-else class="h-4 w-4 text-muted-foreground" />
        <span v-if="mode === 'list'">{{ scoped ? 'Side chat threads' : 'Threads' }}</span>
        <span v-else-if="mode === 'create'">New thread</span>
        <span v-else class="truncate">{{ thread?.name ?? 'Thread' }}</span>
      </div>
      <div class="flex items-center gap-1">
        <!-- Rename / delete. Only drawn when the server said this person may: `can_manage`
             is the same rule the endpoint enforces, so there's no button here that 403s. -->
        <template v-if="mode === 'view' && thread?.can_manage">
          <button class="p-1 text-muted-foreground hover:text-foreground" aria-label="Rename thread" title="Rename thread" @click="startRename">
            <Pencil class="h-3.5 w-3.5" />
          </button>
          <button class="p-1 text-muted-foreground hover:text-destructive" aria-label="Delete thread" title="Delete thread" @click="confirmDelete = true">
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </template>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
          <X class="h-4 w-4" />
        </button>
      </div>
    </header>

    <!-- Rename: an inline row rather than a dialog. It's one field, and the title it edits
         is directly above it — a modal would cover the thing being renamed. -->
    <form v-if="renaming" class="flex shrink-0 items-center gap-2 border-b bg-muted/30 px-3 py-2" @submit.prevent="submitRename">
      <Input v-model="renameName" placeholder="Thread name" autofocus class="h-8" />
      <Button type="submit" size="sm" :disabled="!renameName.trim() || savingName">Save</Button>
      <Button type="button" size="sm" variant="ghost" @click="renaming = false">Cancel</Button>
    </form>

    <!-- Delete needs confirming: it takes every reply with it and there is no undo. -->
    <div v-if="confirmDelete" class="shrink-0 space-y-2 border-b bg-destructive/10 px-3 py-2 text-sm">
      <p>Delete this thread and all {{ thread?.replies_count ?? 0 }} replies? This can't be undone.</p>
      <div class="flex gap-2">
        <Button size="sm" variant="destructive" :disabled="deleting" @click="submitDelete">
          {{ deleting ? 'Deleting…' : 'Delete thread' }}
        </Button>
        <Button size="sm" variant="ghost" @click="confirmDelete = false">Cancel</Button>
      </div>
    </div>

    <!-- LIST -->
    <div v-if="mode === 'list'" class="flex-1 overflow-y-auto p-2">
      <!-- A row, not a <button>: it carries its own edit/delete controls, and nesting a
           button inside a button is invalid markup. The title is the clickable part. -->
      <div v-for="t in list" :key="t.id" class="group/row rounded p-2 hover:bg-muted">
        <div class="flex items-start gap-1">
          <button class="min-w-0 flex-1 truncate text-left text-sm font-medium" @click="openThread(t.id)">
            {{ t.name }}
          </button>
          <span
            v-if="t.can_manage"
            class="flex shrink-0 items-center gap-0.5"
            :class="coarse ? '' : 'opacity-0 group-hover/row:opacity-100'"
          >
            <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Rename thread" :aria-label="`Rename ${t.name}`" @click.stop="startRowRename(t)">
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <button class="rounded p-1 text-muted-foreground hover:text-destructive" title="Delete thread" :aria-label="`Delete ${t.name}`" @click.stop="deletingRow = t">
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </span>
        </div>

        <div class="text-xs text-muted-foreground">
          {{ t.replies_count ?? 0 }} {{ (t.replies_count ?? 0) === 1 ? 'reply' : 'replies' }}
          <template v-if="t.creator"> · started by {{ nameFor(t.creator) }}</template>
        </div>

        <!-- Rename inline: one field, and the name it edits is directly above it. -->
        <form v-if="renamingRow?.id === t.id" class="mt-1.5 flex items-center gap-1.5" @submit.prevent="submitRowRename(t)" @click.stop>
          <Input v-model="rowName" placeholder="Thread name" autofocus class="h-8" />
          <Button type="submit" size="sm" :disabled="!rowName.trim() || rowBusy">Save</Button>
          <Button type="button" size="sm" variant="ghost" @click="renamingRow = null">Cancel</Button>
        </form>

        <div v-if="deletingRow?.id === t.id" class="mt-1.5 rounded border border-destructive/40 bg-destructive/10 p-2 text-xs" @click.stop>
          <p>Delete “{{ t.name }}” and its {{ t.replies_count ?? 0 }} replies?</p>
          <div class="mt-1.5 flex gap-2">
            <Button size="sm" variant="destructive" :disabled="rowBusy" @click="submitRowDelete(t)">
              {{ rowBusy ? 'Deleting…' : 'Delete' }}
            </Button>
            <Button size="sm" variant="ghost" @click="deletingRow = null">Cancel</Button>
          </div>
        </div>
      </div>
      <p v-if="!list.length" class="p-3 text-sm text-muted-foreground">
        {{ scoped ? 'No threads in this side chat yet.' : 'No threads yet.' }}
      </p>
    </div>

    <!-- CREATE -->
    <form v-else-if="mode === 'create'" class="space-y-3 p-4" @submit.prevent="submitCreate">
      <p class="text-sm text-muted-foreground">
        <template v-if="scoped">{{ fromMessageId ? 'Start a thread off this side chat message.' : 'Start a new thread in this side chat.' }}</template>
        <template v-else>{{ fromMessageId ? 'Start a thread from this message.' : 'Start a new thread in this channel.' }}</template>
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
          <span class="font-medium">{{ nameFor(thread.parent_message.user) }}</span>
          <MarkdownBody v-if="thread.parent_message.body" :source="thread.parent_message.body" />
        </div>

        <!-- Participants: collapsed by default so it never crowds the reply list. -->
        <details class="group/participants m-3 mb-0 shrink-0 rounded-lg border">
          <summary class="flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 text-xs font-semibold uppercase text-muted-foreground">
            <Users class="h-3.5 w-3.5" /> Participants ({{ participants.length }})
            <ChevronDown class="ml-auto h-4 w-4 transition-transform group-open/participants:rotate-180" />
          </summary>
          <div class="max-h-56 overflow-y-auto border-t px-2 py-2">
            <ParticipantList :members="participants" />
          </div>
        </details>

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
                  :size-dependencies="[item.body, item.reply_to, item.edited, item.attachments, item.reactions, item.comments, item.link_previews, item.pinned, daySeparators.get(item.id)]"
                >
                  <!-- Day divider above the first reply of each calendar day, so a thread read
                       long after the fact still says when it happened. -->
                  <div v-if="daySeparators.get(item.id)" class="relative my-2 flex items-center justify-center">
                    <div class="absolute inset-x-2 top-1/2 h-px bg-border" />
                    <span class="relative rounded-full border bg-background px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                      {{ daySeparators.get(item.id) }}
                    </span>
                  </div>

                  <MessageItem
                    :message="item"
                    :current-user-id="user?.id ?? null"
                    forwardable
                    :highlighted="item.id === highlightedMessageId"
                    @reply="replyingTo = $event"
                    @save="edit"
                    @remove="remove"
                    @jump-to-reply="onJumpToReply"
                    @toggle-reaction="toggleReaction"
                    @toggle-pin="togglePin"
                    @forward="forwardTarget = $event"
                  />
                </DynamicScrollerItem>
              </template>
            </DynamicScroller>
          </ClientOnly>
        </div>
      </div>

      <div class="shrink-0 border-t">
        <div v-if="replyingTo" class="flex items-center justify-between bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground">
          <span class="truncate">Replying to <span class="font-medium">{{ nameFor(replyingTo.user) }}</span></span>
          <button class="hover:text-foreground" @click="replyingTo = null"><X class="h-3.5 w-3.5" /></button>
        </div>
        <TypingIndicator :label="typingLabel" />
        <MessageComposer placeholder="Reply…" :sending="sending" @submit="onSend" @typing="notifyTyping" />
      </div>
    </template>

    <!-- Forward a reply from this thread into another chat or channel. -->
    <ForwardDialog v-model:message="forwardTarget" />
  </aside>
</template>
