<script setup lang="ts">
import { CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, FolderTree, Info, LayoutPanelLeft, Loader2, MessageSquare, MessageSquareText, MessagesSquare, Pencil, Pin, Plus, Reply, Rocket, Smile, Tag, Trash2, UserPlus, Users, X } from 'lucide-vue-next'
import type { GifResult, Message, SideChat, SideDeskAppId } from '~/types'
// Aliased on import: this file already has a `deskApp` of its own (the active tab), and the
// auto-imported registry lookup of the same name would be shadowed by it.
import { deskApp as deskApp_ } from '~/composables/useDeskApps'
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
 *   - **Desk** — the shared, persistent, real-time workspace: whiteboard, notes, docs and
 *     a widget canvas, each a tab (see SideDesk).
 *
 * And because a side chat can own threads of its own, opening one leaves this panel in place
 * and adds a second column beside it — the ThreadPanel, scoped to this side chat. Which is
 * why the query helpers here *merge* rather than replace: the two columns share the URL.
 */
const props = defineProps<{ channelId: number }>()

// Draggable, remembered width (its left border carries the handle).
// Below `md` there is no room for a column beside the timeline: the panel takes the
// whole screen instead, and its own close button is the way back.
const { narrow } = useNavDrawer()
const { width: panelWidth, startResize } = useResizable('side-chat', 380, { min: 300, max: 720 })
// The URL on the page you're on, a docked pane's own state inside one. See useSurfaceRoute.
const surface = useSurfaceRoute()
const query = surface.query
const { user } = useAuth()

const { sideChats, tagCounts, loadSideChats, createSideChat, join, leave } = useSideChats()

// --- the forum index: one tag at a time ---------------------------------
// Single-select rather than multi: with two tags selected the obvious question is whether
// it means AND or OR, and neither answer is guessable from a row of chips. One tag is
// unambiguous, and it's what a forum's category strip does.
const activeTag = ref<string | null>(null)
const visibleSideChats = computed(() =>
  activeTag.value === null
    ? sideChats.value
    : sideChats.value.filter(s => s.tags?.includes(activeTag.value!)),
)
// A filter that outlives the tag it names would silently empty the list.
watch(tagCounts, (tags) => {
  if (activeTag.value && !tags.some(t => t.tag === activeTag.value)) activeTag.value = null
})

// How many tag chips the strip shows before folding the rest behind "+N more". Eight is
// about two rows in the panel's narrowest usable width — enough to be a category strip,
// not so many that the posts get pushed off the screen by their own filter.
const TAG_LIMIT = 8
const allTagsShown = ref(false)
const shownTags = computed(() => {
  const tags = tagCounts.value
  if (allTagsShown.value || tags.length <= TAG_LIMIT) return tags
  // Whatever is currently filtered on stays visible even if it ranks below the cut —
  // a filter you can see the effect of but not the control for is a trap.
  const head = tags.slice(0, TAG_LIMIT)
  const active = tags.find(t => t.tag === activeTag.value)
  return active && !head.includes(active) ? [...head, active] : head
})
const hiddenTagCount = computed(() => Math.max(0, tagCounts.value.length - shownTags.value.length))

// --- the forum index: groups ---------------------------------------------
/**
 * Groups and tags are the list's two axes and they compose rather than compete: the tag
 * filter narrows *which posts*, the groups decide *where each one sits*. So the grouping is
 * applied to the already-filtered list, and filtering by a tag leaves the headings in
 * place — you can still see that three of the four "bug" posts are in Triage.
 */
const {
  forums, canManageForums, loadForums, createForum, renameForum, removeForum, moveForum,
  isGroupOpen, toggleGroup, groupPosts,
} = useSideChatForums()

const groups = computed(() => groupPosts(visibleSideChats.value))

// --- managing the groups themselves --------------------------------------
// One inline editor rather than a dialog: creating a group is a name and nothing else, and
// a modal for one text field puts a door in front of a doormat.
const forumsOpen = ref(false)
const newForumName = ref('')
const forumBusy = ref(false)
const renamingForumId = ref<number | null>(null)
const renameDraft = ref('')

async function submitForum() {
  const name = newForumName.value.trim()
  if (!name || forumBusy.value) return
  forumBusy.value = true
  try {
    await createForum(props.channelId, name)
    newForumName.value = ''
  } finally {
    forumBusy.value = false
  }
}

async function submitRename(forumId: number) {
  const name = renameDraft.value.trim()
  if (!name || forumBusy.value) return
  forumBusy.value = true
  try {
    await renameForum(forumId, name)
    renamingForumId.value = null
  } finally {
    forumBusy.value = false
  }
}

/**
 * Delete a group. Unconfirmed on purpose, unlike deleting a post: nothing is lost. The
 * posts inside reappear under Uncategorised (the foreign key nulls rather than cascades),
 * so the worst case is retyping a name.
 */
async function dropForum(forumId: number) {
  if (forumBusy.value) return
  forumBusy.value = true
  try {
    await removeForum(forumId)
    // Any post that was in it has to be re-read to learn it's now ungrouped.
    await loadSideChats(props.channelId)
  } finally {
    forumBusy.value = false
  }
}

const reactionTotal = (s: SideChat) => (s.reactions ?? []).reduce((sum, r) => sum + r.count, 0)
const commentTotal = (s: SideChat) => (s.comments ?? []).reduce((sum, c) => sum + c.count, 0)

// --- managing a post from the *list*, without opening it -----------------
// Held as the row itself rather than an id: the edit dialog wants the whole post, and the
// delete confirmation wants its title to put in the question.
const editingRow = ref<SideChat | null>(null)
const deletingRow = ref<SideChat | null>(null)
const deletingRowBusy = ref(false)
// Touch has no hover to reveal the row controls with, so there they're simply always on.
const coarse = import.meta.client && window.matchMedia?.('(pointer: coarse)').matches

async function confirmDeleteRow(s: SideChat) {
  if (deletingRowBusy.value) return
  deletingRowBusy.value = true
  try {
    await removeSideChat(s.id)
  } finally {
    deletingRowBusy.value = false
    deletingRow.value = null
  }
}

const {
  sideChat, messages, highlights, gone, hasMore, loadingOlder,
  loadSideChat, loadOlder, ensureLoaded,
  send, edit, remove, toggleReaction, togglePin, toggleDecision,
  subscribe, unsubscribe,
} = useSideChatMessages()

// Which messages open a new calendar day, and the label to print above them.
const daySeparators = useDaySeparators(messages)
const {
  label: typingLabel,
  notifyTyping,
  stopTyping,
  subscribe: subscribeTyping,
  unsubscribe: unsubscribeTyping,
} = useTyping()

const mode = computed<'list' | 'create' | 'view' | null>(() => {
  if (query.value.sidechats === '1') return 'list'
  if (query.value.sidechat === 'new') return 'create'
  if (query.value.sidechat) return 'view'
  return null
})
const activeId = computed(() => (mode.value === 'view' ? Number(query.value.sidechat) : null))
const fromMessageId = computed(() => (query.value.from ? Number(query.value.from) : null))

// --- managing the open post (the OP's, or a server admin's) ---
const editing = ref(false)
const confirmDelete = ref(false)
const deleting = ref(false)
const { removeSideChat, react: reactToPost } = useSideChats()

async function onDelete() {
  if (!activeId.value || deleting.value) return
  deleting.value = true
  try {
    await removeSideChat(activeId.value)
    close() // the workspace is showing a room that no longer exists
  } finally {
    deleting.value = false
    confirmDelete.value = false
  }
}

const TABS = [
  { key: 'chat', label: 'Chat', icon: MessageSquare },
  { key: 'info', label: 'Info', icon: Info },
  { key: 'desk', label: 'Desk', icon: LayoutPanelLeft },
] as const
const sctab = computed<'chat' | 'info' | 'desk'>(() => {
  const t = query.value.sctab
  return t === 'info' || t === 'desk' ? t : 'chat'
})

// The Side Desk's active app rides in `desktab`; canvas is the default (kept out of the URL).
// Any registry id is accepted — the strip is open-ended now, so validating against the four old
// names would bounce every widget app back to the canvas. SideDesk handles an id this surface
// doesn't have on its strip, which is where the strip is actually known.
const deskApp = computed<SideDeskAppId>(() => {
  const s = query.value.desktab
  return typeof s === 'string' && deskApp_(s as SideDeskAppId) ? (s as SideDeskAppId) : 'canvas'
})
function setDeskApp(app: SideDeskAppId) {
  setQuery({ desktab: app === 'canvas' ? null : app })
}

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
// The post's own reply box, so "Reply" can put the caret straight in it.
const postReplyBox = ref<HTMLTextAreaElement | null>(null)
// The full comment list behind the post's chips.
const showPostComments = ref(false)
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
function goto(next: Record<string, string>) {
  surface.replace(next)
}
/** Merge into the current query — so a thread column can stay open alongside a tab switch. */
function setQuery(patch: Record<string, string | null>) {
  surface.patch(patch)
}
function close() {
  surface.replace({})
}

// --- replying to the post itself ----------------------------------------
// A reply to the post is still a message in this side chat's timeline — there is no second
// store — but it's *marked* as addressed at the title, so it renders with a chip naming the
// post rather than looking like any other line. The box opens under the title, where the
// thing being replied to is: bouncing the caret to the composer at the bottom is what this
// replaced, and it never read as "replying to the title".
const postReplyOpen = ref(false)
const postReply = ref('')
const postReplyBusy = ref(false)

function replyToPost() {
  postReplyOpen.value = true
  nextTick(() => postReplyBox.value?.focus())
}

/**
 * Arriving with `screply=1` means the timeline card's "Reply" sent us here, so open the
 * box on landing. The flag is dropped straight away: it describes how you got here, not
 * where you are, and leaving it in the URL would re-open the box on every tab switch.
 */
watch([activeId, () => query.value.screply], () => {
  if (activeId.value && query.value.screply === '1') {
    replyToPost()
    setQuery({ screply: null })
  }
}, { immediate: true })

async function submitPostReply() {
  const body = postReply.value.trim()
  if (!body || !activeId.value || postReplyBusy.value) return
  postReplyBusy.value = true
  try {
    // `true` is the marker; everything else is an ordinary send.
    await send(activeId.value, body, null, [], null, [], true)
    postReply.value = ''
    postReplyOpen.value = false
    // Land on the Chat tab so the reply that just went in is actually on screen.
    if (sctab.value !== 'chat') setQuery({ sctab: null })
    scrollBottom()
  } finally {
    postReplyBusy.value = false
  }
}

/**
 * Step back to the list of posts, leaving anything else on screen alone.
 *
 * A merge rather than a `goto`, deliberately: a side chat's thread column lives in the
 * same URL, and replacing the query wholesale would slam it shut as a side effect of
 * pressing Back on the panel next to it.
 */
function backToList() {
  setQuery({ sidechats: '1', sidechat: null, sctab: null, desktab: null, from: null })
}

// A side chat's threads live in a second column, under their own `sc…` query keys so they
// don't collide with a channel thread the main timeline may have open at the same time.
function openThreads() {
  setQuery({ scthreads: '1', scthread: null, scfrom: null })
}
function onCreateThread(messageId: number) {
  setQuery({ scthreads: null, scthread: 'new', scfrom: String(messageId) })
}
function onOpenThread(id: number) {
  setQuery({ scthreads: null, scthread: String(id), scfrom: null })
}

// --- tags on the create form ---
// Deliberately a plain list of strings and no picker: there's no tag catalogue to pick
// from, only whatever this channel has used before (offered as suggestions). The server
// lowercases and dedupes, so these chips are a preview of what it will store.
const newTags = ref<string[]>([])
const newTagDraft = ref('')
// Which group the new post is filed under. A string because that's what `<select>` binds;
// `''` is Uncategorised.
const newForumId = ref('')

function addNewTag(raw: string) {
  const tag = raw.trim().toLowerCase()
  if (!tag || newTags.value.includes(tag) || newTags.value.length >= 8) return
  newTags.value = [...newTags.value, tag]
}

function onNewTagKey(e: KeyboardEvent) {
  if (e.key !== 'Enter' && e.key !== ',') return
  e.preventDefault() // Enter would otherwise submit the form with a half-typed tag
  addNewTag(newTagDraft.value)
  newTagDraft.value = ''
}

async function submitCreate() {
  const name = newName.value.trim()
  if (!name || creating.value) return
  creating.value = true
  try {
    // Whatever is still in the input counts: nobody expects a typed tag to vanish because
    // they hit the button instead of Enter.
    if (newTagDraft.value.trim()) { addNewTag(newTagDraft.value); newTagDraft.value = '' }
    const s = await createSideChat(props.channelId, {
      name,
      message_id: fromMessageId.value ?? null,
      tags: newTags.value,
      side_chat_forum_id: newForumId.value ? Number(newForumId.value) : null,
    })
    newName.value = ''
    newTags.value = []
    newForumId.value = ''
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

async function onSend(body: string, files: File[], gif?: GifResult, uploadIds: string[] = []) {
  if (!activeId.value || sending.value) return
  sending.value = true
  try {
    await send(activeId.value, body, replyingTo.value?.id ?? null, files, gif, uploadIds)
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
    // Both are about the post we're leaving; carrying them across would aim an open edit
    // dialog — or a delete confirmation — at the next one.
    editing.value = false
    confirmDelete.value = false
    // The reply box is aimed at the post we're leaving; so is anything half-typed in it.
    postReplyOpen.value = false
    postReply.value = ''
    if (mode.value === 'list') {
      // Both together: the list is drawn *as* its groups, so posts arriving before the
      // headings would flash through Uncategorised on their way to the right place.
      await Promise.all([loadSideChats(props.channelId), loadForums(props.channelId)])
    } else if (mode.value === 'create') {
      // The create form offers the groups to file into, so it needs them too — and it can
      // be reached straight from a message without passing through the list.
      await loadForums(props.channelId)
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

// The post was deleted (by its OP, or by an admin) while we were standing in it.
watch(gone, (v) => { if (v) close() })

watch(() => messages.value.at(-1)?.id, (nid, oid) => {
  if (nid && oid && nid > oid) scrollBottom()
})
onBeforeUnmount(teardown)

const roster = computed(() => sideChat.value?.participants ?? [])
const hasHighlights = computed(() =>
  highlights.value.decisions.length > 0 || highlights.value.pinned.length > 0,
)

// Names follow whatever people are called in this server or chat — see useNicknames.
const { nameFor } = useNicknames()

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
  <aside
    class="flex flex-col border-l bg-background"
    :class="narrow ? 'safe-inset fixed inset-0 z-50 w-full' : 'relative shrink-0'"
    :style="narrow ? undefined : { width: `${panelWidth}px` }"
  >
    <ResizeHandle v-if="!narrow" edge="left" @resize="startResize" />
    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <div class="flex min-w-0 items-center gap-2 font-semibold">
        <!-- Back to the list. Opening a post replaces the list in this one column, so
             without this the only way out is ✕ — which closes the panel entirely and
             loses your place. Absent on the list itself, where there's nothing behind. -->
        <button
          v-if="mode !== 'list'"
          class="-ml-1 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
          title="Back to all side chats"
          aria-label="Back to all side chats"
          @click="backToList"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>
        <Rocket v-else class="h-4 w-4 text-muted-foreground" />
        <span v-if="mode === 'list'">Side Chats</span>
        <span v-else-if="mode === 'create'">New side chat</span>
        <span v-else class="truncate">{{ sideChat?.name ?? 'Side Chat' }}</span>
      </div>
      <div class="flex items-center gap-1">
        <template v-if="mode === 'view' && sideChat?.can_manage">
          <button class="p-1 text-muted-foreground hover:text-foreground" title="Edit title and tags" aria-label="Edit side chat" @click="editing = true">
            <Pencil class="h-3.5 w-3.5" />
          </button>
          <button class="p-1 text-muted-foreground hover:text-destructive" title="Delete side chat" aria-label="Delete side chat" @click="confirmDelete = true">
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </template>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
          <X class="h-4 w-4" />
        </button>
      </div>
    </header>

    <!-- Deleting a post takes its whole room with it — timeline, threads, board, notes —
         so it's confirmed in place, right under the title being deleted. -->
    <div v-if="confirmDelete" class="shrink-0 space-y-2 border-b bg-destructive/10 px-3 py-2 text-sm">
      <p>Delete “{{ sideChat?.name }}” and everything in it? This can't be undone.</p>
      <div class="flex gap-2">
        <Button size="sm" variant="destructive" :disabled="deleting" @click="onDelete">
          {{ deleting ? 'Deleting…' : 'Delete side chat' }}
        </Button>
        <Button size="sm" variant="ghost" @click="confirmDelete = false">Cancel</Button>
      </div>
    </div>

    <SideChatEditDialog v-if="editing && sideChat" :side-chat="sideChat" @close="editing = false" />
    <SideChatEditDialog v-if="editingRow" :side-chat="editingRow" @close="editingRow = null" />
    <CommentDialog
      v-if="showPostComments && sideChat"
      v-model:open="showPostComments"
      :subject="{ kind: 'sideChat', id: sideChat.id }"
    />

    <!-- LIST -->
    <div v-if="mode === 'list'" class="flex min-h-0 flex-1 flex-col">
      <!--
        The toolbar: what you can *do* to the list, held out of the list's own scroll area
        and fenced off by a border. The tag filter used to sit loose at the top of the
        scrolling rows, which read as though it belonged to the first post rather than to
        the list — and scrolled away the moment you moved.
      -->
      <div class="shrink-0 space-y-2 border-b p-2">
        <Button variant="outline" size="sm" class="w-full gap-1.5" @click="goto({ sidechat: 'new' })">
          <Plus class="h-4 w-4" /> New side chat
        </Button>

        <!-- Making a group is the staff's; filing a post into one is everyone's, and that
             lives on the post. Folded away by default so the ordinary reader's toolbar is
             still "new post, filter by tag" and nothing else. -->
        <div v-if="canManageForums">
          <button
            class="flex w-full items-center gap-1.5 rounded px-1 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground hover:bg-muted hover:text-foreground"
            @click="forumsOpen = !forumsOpen"
          >
            <FolderTree class="h-3 w-3" />
            <span>Groups</span>
            <ChevronDown v-if="forumsOpen" class="ml-auto h-3 w-3" />
            <ChevronRight v-else class="ml-auto h-3 w-3" />
          </button>

          <form v-if="forumsOpen" class="mt-1 flex gap-1" @submit.prevent="submitForum">
            <Input v-model="newForumName" placeholder="New group, e.g. Bugs" class="h-7 text-xs" />
            <Button type="submit" size="sm" class="h-7 shrink-0 px-2 text-xs" :disabled="!newForumName.trim() || forumBusy">Add</Button>
          </form>
        </div>

        <!-- Only drawn once something is actually tagged: an empty filter row is a
             control that does nothing. -->
        <div v-if="tagCounts.length">
          <div class="mb-1.5 flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
            <Tag class="h-3 w-3" />
            <span>Filter by tag</span>
            <!-- An explicit way out. Clicking the active chip again also clears it, but
                 that's a thing you have to already know; this says so. -->
            <button
              v-if="activeTag"
              class="ml-auto flex items-center gap-0.5 rounded px-1 py-0.5 normal-case tracking-normal text-primary hover:bg-muted"
              @click="activeTag = null"
            >
              <X class="h-3 w-3" /> Clear
            </button>
          </div>

          <div class="flex flex-wrap gap-1">
            <!-- "All" as a chip rather than an implied empty state: with no chip lit, a
                 row of unlit chips gives no clue that unfiltered is a state you're in. -->
            <button
              class="rounded-full px-2 py-1 text-xs font-medium transition"
              :class="activeTag === null
                ? 'bg-primary text-primary-foreground'
                : 'bg-muted text-foreground/80 hover:bg-muted/70'"
              :aria-pressed="activeTag === null"
              @click="activeTag = null"
            >
              All <span class="tabular-nums opacity-70">{{ sideChats.length }}</span>
            </button>

            <button
              v-for="t in shownTags"
              :key="t.tag"
              class="rounded-full px-2 py-1 text-xs transition"
              :class="activeTag === t.tag
                ? 'bg-primary font-medium text-primary-foreground'
                : 'bg-muted text-foreground/80 hover:bg-muted/70'"
              :aria-pressed="activeTag === t.tag"
              @click="activeTag = activeTag === t.tag ? null : t.tag"
            >
              {{ t.tag }} <span class="tabular-nums opacity-70">{{ t.count }}</span>
            </button>

            <!-- A long tail of tags would push the posts off the screen, which is the
                 wrong way round: the filter is here to reach the list, not to bury it. -->
            <button
              v-if="hiddenTagCount"
              class="rounded-full border border-dashed px-2 py-1 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
              @click="allTagsShown = !allTagsShown"
            >
              {{ allTagsShown ? 'Show fewer' : `+${hiddenTagCount} more` }}
            </button>
          </div>
        </div>
      </div>

      <!-- The posts themselves, scrolling under the toolbar, cut into their groups. -->
      <div class="min-h-0 flex-1 overflow-y-auto p-2">

      <div v-for="g in groups" :key="g.forum?.id ?? 'none'" class="mb-2">
        <!-- The group heading. Folds its own posts away; the count is what tells you what
             you folded. Uncategorised gets the same heading as any other group but none of
             the controls — it isn't a row anybody made, so it isn't a row anybody renames. -->
        <div class="group/forum flex items-center gap-1 rounded px-1 py-1 hover:bg-muted/60">
          <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-1 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
            :title="isGroupOpen(channelId, g.forum?.id ?? null) ? 'Collapse' : 'Expand'"
            @click="toggleGroup(channelId, g.forum?.id ?? null)"
          >
            <ChevronDown v-if="isGroupOpen(channelId, g.forum?.id ?? null)" class="h-3 w-3 shrink-0" />
            <ChevronRight v-else class="h-3 w-3 shrink-0" />
            <span class="truncate">{{ g.forum?.name ?? 'Uncategorised' }}</span>
            <span class="shrink-0 tabular-nums opacity-60">{{ g.posts.length }}</span>
          </button>

          <span
            v-if="g.forum && canManageForums"
            class="flex shrink-0 items-center gap-0.5"
            :class="coarse ? '' : 'opacity-0 group-hover/forum:opacity-100'"
          >
            <button class="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move up" :aria-label="`Move ${g.forum.name} up`" @click="moveForum(channelId, g.forum!.id, -1)">
              <ChevronUp class="h-3 w-3" />
            </button>
            <button class="rounded p-1 text-muted-foreground hover:text-foreground disabled:opacity-30" title="Move down" :aria-label="`Move ${g.forum.name} down`" @click="moveForum(channelId, g.forum!.id, 1)">
              <ChevronDown class="h-3 w-3" />
            </button>
            <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Rename group" :aria-label="`Rename ${g.forum.name}`" @click="renamingForumId = g.forum!.id; renameDraft = g.forum!.name">
              <Pencil class="h-3 w-3" />
            </button>
            <!-- No confirmation: the posts inside survive, so there is nothing to lose. -->
            <button class="rounded p-1 text-muted-foreground hover:text-destructive" title="Delete group (its posts move to Uncategorised)" :aria-label="`Delete ${g.forum.name}`" @click="dropForum(g.forum!.id)">
              <Trash2 class="h-3 w-3" />
            </button>
          </span>
        </div>

        <form v-if="g.forum && renamingForumId === g.forum.id" class="mb-1 flex gap-1 px-1" @submit.prevent="submitRename(g.forum.id)">
          <Input v-model="renameDraft" class="h-7 text-xs" autofocus @keydown.esc="renamingForumId = null" />
          <Button type="submit" size="sm" class="h-7 px-2 text-xs" :disabled="!renameDraft.trim() || forumBusy">Save</Button>
          <Button type="button" size="sm" variant="ghost" class="h-7 px-2 text-xs" @click="renamingForumId = null">Cancel</Button>
        </form>

        <template v-if="isGroupOpen(channelId, g.forum?.id ?? null)">
          <p v-if="!g.posts.length" class="px-3 py-1.5 text-xs text-muted-foreground">Nothing filed here yet.</p>

          <!-- A row, not a <button>: it carries its own edit/delete controls, and a button
               inside a button is invalid markup that browsers resolve by dropping one of them.
               The title is the clickable part; the controls sit beside it. -->
          <div
            v-for="s in g.posts"
            :key="s.id"
            class="group/row rounded p-2 hover:bg-muted"
          >
        <div class="flex items-start gap-1">
          <button class="min-w-0 flex-1 truncate text-left text-sm font-medium" @click="goto({ sidechat: String(s.id) })">
            {{ s.name }}
          </button>
          <!-- Hover-revealed on a mouse, always there on touch — the same treatment the
               sidebar's channel controls get, for the same reason. -->
          <span
            v-if="s.can_manage"
            class="flex shrink-0 items-center gap-0.5"
            :class="coarse ? '' : 'opacity-0 group-hover/row:opacity-100'"
          >
            <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Edit title and tags" :aria-label="`Edit ${s.name}`" @click.stop="editingRow = s">
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <button class="rounded p-1 text-muted-foreground hover:text-destructive" title="Delete side chat" :aria-label="`Delete ${s.name}`" @click.stop="deletingRow = s">
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </span>
        </div>

        <!-- Who started it, badged OP — the forum convention. -->
        <div v-if="s.creator" class="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
          <span class="truncate">{{ nameFor(s.creator) }}</span>
          <span class="rounded bg-primary/10 px-1 text-[9px] font-bold uppercase text-primary" title="Original poster">OP</span>
        </div>

        <!-- A row's tags double as filter shortcuts: seeing "design" on a post and wanting
             the rest of them is the same thought, so it shouldn't need a trip to the strip
             above. Safe to make clickable because only the *title* opens the post. -->
        <div v-if="s.tags?.length" class="mt-1 flex flex-wrap gap-1">
          <button
            v-for="tag in s.tags"
            :key="tag"
            class="rounded-full px-2 py-0.5 text-[10px] font-medium transition"
            :class="activeTag === tag
              ? 'bg-primary text-primary-foreground'
              : 'bg-primary/10 text-primary hover:bg-primary/20'"
            :title="`Show everything tagged ${tag}`"
            @click.stop="activeTag = activeTag === tag ? null : tag"
          >
            {{ tag }}
          </button>
        </div>

        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
          <span class="flex items-center gap-1"><Users class="h-3 w-3" /> {{ s.participants_count ?? 0 }}</span>
          <span>· {{ s.messages_count ?? 0 }} messages</span>
          <!-- Reactions on the post, summed: the list wants "how much did this land",
               not which emoji. The chips themselves live on the card and in the post. -->
          <span v-if="reactionTotal(s) > 0" class="flex items-center gap-1" title="Reactions to this post">
            · <Smile class="h-3 w-3" /> {{ reactionTotal(s) }}
          </span>
          <!-- Word-reactions on the post, summed. Separate from the message count above:
               one is feedback *about* the topic, the other is the conversation in it. -->
          <span v-if="commentTotal(s) > 0" class="flex items-center gap-1" title="Comments on this post">
            · <MessageSquareText class="h-3 w-3" /> {{ commentTotal(s) }}
          </span>
          <span v-if="(s.decisions_count ?? 0) > 0" class="flex items-center gap-1">
            · <CheckCircle2 class="h-3 w-3" /> {{ s.decisions_count }}
          </span>
          <span>· {{ relTime(s.last_active_at) }}</span>
        </div>

        <!-- Confirmed in the row itself: a modal would cover the list you're deleting from,
             and the title being deleted is right there to read. -->
        <div v-if="deletingRow?.id === s.id" class="mt-1.5 rounded border border-destructive/40 bg-destructive/10 p-2 text-xs">
          <p>Delete “{{ s.name }}” and everything in it?</p>
          <div class="mt-1.5 flex gap-2">
            <Button size="sm" variant="destructive" :disabled="deletingRowBusy" @click.stop="confirmDeleteRow(s)">
              {{ deletingRowBusy ? 'Deleting…' : 'Delete' }}
            </Button>
            <Button size="sm" variant="ghost" @click.stop="deletingRow = null">Cancel</Button>
          </div>
        </div>
          </div>
        </template>
      </div>

        <p v-if="!sideChats.length" class="p-3 text-sm text-muted-foreground">No side chats yet.</p>
        <div v-else-if="!visibleSideChats.length" class="p-3 text-sm text-muted-foreground">
          <p>Nothing tagged “{{ activeTag }}”.</p>
          <!-- The way out, offered where the dead end is rather than only up in the strip. -->
          <button class="mt-1 text-primary hover:underline" @click="activeTag = null">Show all side chats</button>
        </div>
      </div>
    </div>

    <!-- CREATE -->
    <form v-else-if="mode === 'create'" class="space-y-3 p-4" @submit.prevent="submitCreate">
      <p class="text-sm text-muted-foreground">
        {{ fromMessageId ? 'Spin a side chat off this message.' : 'Start a new side chat in this channel.' }}
      </p>
      <Input v-model="newName" placeholder="e.g. Dashboard Redesign" autofocus />

      <!-- Only offered where there is somewhere to file it. -->
      <div v-if="forums.length">
        <label for="new-side-chat-forum" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Group</label>
        <select
          id="new-side-chat-forum"
          v-model="newForumId"
          class="w-full rounded-lg border bg-background px-2 py-2 text-sm outline-none focus:ring-1 focus:ring-ring"
        >
          <option value="">Uncategorised</option>
          <option v-for="f in forums" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
        </select>
      </div>

      <!-- Tags at creation, not as an afterthought: a post filed the moment it's opened is
           a post the list can find. Same free-text entry as the edit dialog. -->
      <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Tags <span class="font-normal normal-case">(optional)</span>
        </label>
        <div class="flex flex-wrap items-center gap-1 rounded-lg border bg-background px-2 py-1.5">
          <span v-for="tag in newTags" :key="tag" class="flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
            {{ tag }}
            <button type="button" class="hover:text-foreground" :aria-label="`Remove ${tag}`" @click="newTags = newTags.filter(t => t !== tag)">
              <X class="h-3 w-3" />
            </button>
          </span>
          <input
            v-model="newTagDraft"
            :disabled="newTags.length >= 8"
            placeholder="Add a tag…"
            class="min-w-24 flex-1 bg-transparent py-0.5 text-sm outline-none placeholder:text-muted-foreground"
            @keydown="onNewTagKey"
          >
        </div>
        <div v-if="tagCounts.length" class="mt-1.5 flex flex-wrap gap-1">
          <button
            v-for="t in tagCounts.filter(t => !newTags.includes(t.tag)).slice(0, 12)"
            :key="t.tag"
            type="button"
            class="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground"
            @click="addNewTag(t.tag)"
          >
            {{ t.tag }}
          </button>
        </div>
      </div>

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

      <!-- The post's own strip: who started it, what it's tagged, how it landed. Above the
           tabs' content rather than inside the Chat tab, because it describes the *post*
           and stays true whichever tab you're on. -->
      <div v-if="sideChat" class="shrink-0 border-b px-4 py-2">
        <div class="flex items-center gap-1 text-xs text-muted-foreground">
          <template v-if="sideChat.creator">
            <span class="truncate">{{ nameFor(sideChat.creator) }}</span>
            <span class="rounded bg-primary/10 px-1 text-[9px] font-bold uppercase text-primary" title="Original poster — started this side chat">OP</span>
          </template>
          <!-- Replying to a post *is* posting into its timeline; there is no separate
               reply store. So this drops you on the Chat tab with the caret in the box,
               which is exactly what the button promises. -->
          <button
            class="ml-auto flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
            title="Reply to this side chat"
            @click="replyToPost"
          >
            <Reply class="h-3.5 w-3.5" /> Reply
          </button>
        </div>

        <div v-if="sideChat.tags?.length" class="mt-1 flex flex-wrap gap-1">
          <span v-for="tag in sideChat.tags" :key="tag" class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">{{ tag }}</span>
        </div>

        <ReactionBar
          :reactions="sideChat.reactions ?? []"
          :current-user-id="user?.id ?? null"
          always-show
          @toggle="reactToPost(sideChat.id, $event)"
        />
        <!-- Word-reactions on the *post*: short co-signable feedback about the topic, as
             opposed to a reply, which is a message in the timeline below. -->
        <CommentBar
          :subject="{ kind: 'sideChat', id: sideChat.id }"
          :comments="sideChat.comments ?? []"
          :current-user-id="user?.id ?? null"
          always-show
          @open="showPostComments = true"
        />

        <!-- The reply box, right under the title it's aimed at. Only for people on the
             roster: posting here is posting in the side chat, and that's what joining is
             for — so non-members are told rather than given a box that would 403. -->
        <form v-if="postReplyOpen" class="mt-2" @submit.prevent="submitPostReply">
          <template v-if="joined">
            <textarea
              ref="postReplyBox"
              v-model="postReply"
              rows="2"
              placeholder="Write a reply…"
              class="w-full resize-none rounded-lg border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring"
              @keydown.enter.exact.prevent="submitPostReply"
            />
            <div class="mt-1 flex items-center justify-end gap-2">
              <Button type="button" size="sm" variant="ghost" @click="postReplyOpen = false">Cancel</Button>
              <Button type="submit" size="sm" :disabled="!postReply.trim() || postReplyBusy">
                {{ postReplyBusy ? 'Posting…' : 'Reply' }}
              </Button>
            </div>
          </template>
          <p v-else class="rounded-lg border bg-muted/40 px-2 py-1.5 text-xs text-muted-foreground">
            Join this side chat to reply.
          </p>
        </form>
      </div>

      <!-- CHAT (kept mounted so its scroll position and subscription survive tab switches) -->
      <div v-show="sctab === 'chat'" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-2">
          <div class="flex items-center -space-x-1.5">
            <span
              v-for="m in roster.slice(0, 5)"
              :key="m.id"
              class="grid h-6 w-6 place-items-center overflow-hidden rounded-full border-2 border-background bg-primary text-[9px] font-semibold text-primary-foreground"
              :title="nameFor(m)"
            >
              <img v-if="m.avatar" :src="m.avatar" :alt="nameFor(m)" class="h-full w-full object-cover">
              <span v-else>{{ initials(nameFor(m)) }}</span>
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
              <span class="font-medium">{{ nameFor(d.user) }}:</span> {{ excerpt(d.body) }}
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
              <span class="font-medium">{{ nameFor(p.user) }}:</span> {{ excerpt(p.body) }}
            </button>
          </div>
        </div>

        <div v-if="sideChat?.parent_message" class="m-3 mb-0 shrink-0 rounded-lg border bg-muted/40 p-3 text-sm">
          <div class="mb-1 text-xs font-semibold uppercase text-muted-foreground">Started from</div>
          <span class="font-medium">{{ nameFor(sideChat.parent_message.user) }}</span>
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
                  :size-dependencies="[item.body, item.reply_to, item.started_thread, item.edited, item.attachments, item.reactions, item.comments, item.link_previews, item.pinned, item.decided, daySeparators.get(item.id)]"
                >
                  <!-- Day divider above the first message of each calendar day, matching the
                       main timeline so a side chat read later keeps its bearings. -->
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
                    side-chat-actions
                    forwardable
                    :joined="joined"
                    :post-title="sideChat?.name"
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

      <!-- SIDE DESK — board, notes, docs and the widget canvas for this side chat. -->
      <SideDesk
        v-if="sctab === 'desk' && activeId"
        :key="activeId"
        :base-path="`/api/side-chats/${activeId}`"
        :stream-name="`sidechat.${activeId}`"
        :channel-id="props.channelId"
        :can-edit="joined"
        :active-app="deskApp"
        readonly-hint="Join this side chat to edit"
        @update:active-app="setDeskApp"
        @jump="onJumpToReply"
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
