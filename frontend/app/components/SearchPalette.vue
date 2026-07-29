<script setup lang="ts">
import { onKeyStroke } from '@vueuse/core'
import { FolderOpen, Hash, Loader2, Map as MapIcon, MessageSquare, MessagesSquare, Search, Server as ServerIcon, Sparkles, Users, Volume2, X } from 'lucide-vue-next'
import type { Channel, Conversation, SearchMessage, SearchSurface, Server } from '~/types'
import { Input } from '~/components/ui/input'

/**
 * ⌘K — go anywhere.
 *
 * The palette exists because navigation and search are the same act here: with enough
 * servers, channels and chats, "find the place" stops being something a sidebar can answer
 * by being read and becomes something you have to search. So places come first and messages
 * come last, and the whole thing is driven from the keyboard — arrows move, Enter goes,
 * Escape leaves, and your hands never touch the mouse.
 *
 * On a phone it is not a floating dialog but the whole screen. A centred modal on a 390px
 * viewport is a list five rows tall with a keyboard covering three of them; full-bleed gets
 * the same list ten rows tall, and the ✕ replaces the Escape key that isn't there.
 */
const open = ref(false)
const raw = ref('')
const input = ref<any>(null)
const listEl = ref<HTMLElement | null>(null)
const active = ref(0)

const { user } = useAuth()
const { narrow } = useNavDrawer()
const { results, loading, palette, debounced, reset, MIN_TERM } = useSearch()

const term = computed(() => parseSearchQuery(raw.value).term)

/**
 * The four groups flattened into one keyboard-navigable list.
 *
 * Flattened for the *arrows*, grouped for the *eye*: the headers are drawn from the group
 * each row carries, so a single index can walk the whole thing without the four lists
 * having to know about each other. Same reason the sidebar flattens into one scroller.
 */
type Row =
  | { kind: 'conversation', group: string, item: Conversation }
  | { kind: 'channel', group: string, item: Channel }
  | { kind: 'surface', group: string, item: SearchSurface }
  | { kind: 'server', group: string, item: Server }
  | { kind: 'message', group: string, item: SearchMessage }

/**
 * Smallest thing you might have meant, to largest, with messages last.
 *
 * Side chats and threads sit between the channels and the servers because that is where
 * they sit in the app: they are places *inside* a channel. And they come before messages
 * for the same reason the whole palette does — a title is something somebody wrote to be
 * found again, so a match on one is a stronger signal of intent than a match on a passing
 * mention in a sentence.
 */
const rows = computed<Row[]>(() => [
  ...results.value.conversations.map(item => ({ kind: 'conversation' as const, group: 'Chats', item })),
  ...results.value.channels.map(item => ({ kind: 'channel' as const, group: 'Channels', item })),
  ...results.value.side_chats.map(item => ({ kind: 'surface' as const, group: 'Side chats', item })),
  ...results.value.threads.map(item => ({ kind: 'surface' as const, group: 'Threads', item })),
  ...results.value.side_chat_groups.map(item => ({ kind: 'surface' as const, group: 'Side chat groups', item })),
  ...results.value.servers.map(item => ({ kind: 'server' as const, group: 'Servers', item })),
  ...results.value.messages.map(item => ({ kind: 'message' as const, group: 'Messages', item })),
])

/** The row that starts each group, so a header is drawn above it and nowhere else. */
function isGroupStart(index: number) {
  return index === 0 || rows.value[index - 1]!.group !== rows.value[index]!.group
}

watch(raw, () => {
  active.value = 0
  debounced(() => palette(term.value))
})

// A shrinking result set must never leave the cursor pointing past the end — Enter on a
// row that no longer exists would navigate nowhere, or somewhere surprising.
watch(rows, () => { if (active.value >= rows.value.length) active.value = Math.max(0, rows.value.length - 1) })

// ⌘K / Ctrl+K. A raw listener rather than useMagicKeys because the default has to be
// prevented: in several browsers ⌘K focuses the address bar's search, and losing the
// shortcut to the chrome is worse than not having it.
onKeyStroke('k', (e) => {
  if (!e.metaKey && !e.ctrlKey) return
  e.preventDefault()
  toggle()
})

function toggle() {
  open.value ? close() : show()
}

function show() {
  open.value = true
  raw.value = ''
  active.value = 0
  reset()
  // `Input` is a single-root wrapper around a native input, so the ref is the component
  // and the element is its `$el`.
  nextTick(() => (input.value?.$el ?? input.value)?.focus?.())
}

function close() {
  open.value = false
  reset()
}

function move(delta: number) {
  if (!rows.value.length) return
  // Wraps, because a list you can only fall off the end of makes you look at it.
  active.value = (active.value + delta + rows.value.length) % rows.value.length
  nextTick(() => listEl.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }))
}

function labelFor(row: Row) {
  switch (row.kind) {
    case 'conversation': return conversationTitle(row.item, user.value)
    case 'channel': return row.item.name
    case 'surface': return row.item.name
    case 'server': return row.item.name
    case 'message': return row.item.body || 'Attachment'
  }
}

function subtitleFor(row: Row) {
  switch (row.kind) {
    case 'conversation':
      return row.item.type === 'group'
        ? `${row.item.members.length} members`
        : 'Direct message'
    case 'channel': return row.item.server_id ? 'Channel' : 'Chat'
    case 'surface': return surfaceSubtitle(row.item)
    case 'server': return 'Server'
    case 'message': {
      const c = row.item.context
      const place = c.conversation_id ? conversationPlace(row.item) : `#${c.channel_name}`
      const branch = c.thread_name ?? c.side_chat_name
      return branch ? `${place} › ${branch}` : place
    }
  }
}

/**
 * Where a side chat, thread or group lives, in one line.
 *
 * The group a post is filed under earns its place here: "Deploy plan — #releases › Triage"
 * is how people actually remember a post, and it's the difference between two similarly
 * titled posts in different parts of the same channel.
 */
function surfaceSubtitle(surface: SearchSurface) {
  const place = surface.conversation_id ? surfacePlace(surface) : `#${surface.channel_name}`

  if (surface.kind === 'side_chat_group') return `${place} › group`
  // A side chat's thread is reached through the post, so name the post rather than pretend
  // the thread hangs off the channel.
  if (surface.kind === 'thread') return surface.side_chat_name ? `${place} › ${surface.side_chat_name}` : place

  return surface.group_name ? `${place} › ${surface.group_name}` : place
}

/** A DM has no name of its own — title it from its members, like everything else here. */
function surfacePlace(surface: SearchSurface) {
  const other = (surface.conversation_members ?? []).find(m => m.id !== user.value?.id)
  return other?.name ?? 'Chat'
}

/** A DM result has no name to print, so title it from its members like everything else. */
function conversationPlace(message: SearchMessage) {
  const members = message.context.conversation_members ?? []
  const other = members.find(m => m.id !== user.value?.id)
  return other?.name ?? 'Chat'
}

function iconFor(row: Row) {
  if (row.kind === 'server') return ServerIcon
  if (row.kind === 'conversation') return row.item.type === 'group' ? Users : MessageSquare
  if (row.kind === 'channel') return row.item.type === 'voice' ? Volume2 : row.item.type === 'space' ? MapIcon : Hash
  if (row.kind === 'surface') {
    if (row.item.kind === 'side_chat_group') return FolderOpen
    return row.item.kind === 'thread' ? MessagesSquare : Sparkles
  }
  return MessageSquare
}

/**
 * Where a row goes.
 *
 * A message carries `?jump=` rather than being navigated to and then scrolled: the
 * destination page mounts its own timeline, so the only way to tell it where to land is in
 * the URL it mounts with. ChannelView reads it and re-anchors the timeline there.
 */
function pathFor(row: Row) {
  switch (row.kind) {
    case 'conversation': return `/chats/${row.item.id}`
    case 'channel': return `/servers/${row.item.server_id}/channels/${row.item.id}`
    case 'surface': return surfacePath(row.item)
    case 'server': return `/servers/${row.item.id}`
    case 'message': {
      const c = row.item.context
      const base = c.conversation_id ? `/chats/${c.conversation_id}` : `/servers/${c.server_id}/channels/${c.channel_id}`
      // A hit inside a thread or a side chat opens that surface; only the main timeline can
      // scroll to a message. Same rule as the search panel's row click.
      if (row.item.side_chat_id) return `${base}?sidechat=${row.item.side_chat_id}`
      if (row.item.thread_id) return `${base}?thread=${row.item.thread_id}`
      return `${base}?jump=${row.item.id}`
    }
  }
}

/**
 * Opening a named place inside a channel.
 *
 * All three are addressed by query keys on the channel's own URL, which is how the rest of
 * the app opens them too (see ChannelView.patchQuery). The only awkward one is a side
 * chat's thread: it lives *inside* the post, so both have to be opened, and the side chat's
 * own thread column has its own `sc`-prefixed keys precisely so the two can stand together.
 */
function surfacePath(surface: SearchSurface) {
  const base = surface.conversation_id
    ? `/chats/${surface.conversation_id}`
    : `/servers/${surface.server_id}/channels/${surface.channel_id}`

  if (surface.kind === 'side_chat') return `${base}?sidechat=${surface.id}`

  if (surface.kind === 'thread') {
    return surface.side_chat_id
      ? `${base}?sidechat=${surface.side_chat_id}&scthread=${surface.id}`
      : `${base}?thread=${surface.id}`
  }

  // A group is a heading in the side chat list, so open the list with it unfolded —
  // `scforum` is read by SideChatPanel, which owns the fold state.
  return `${base}?sidechats=1&scforum=${surface.id}`
}

function go(row?: Row) {
  const target = row ?? rows.value[active.value]
  if (!target) return
  navigateTo(pathFor(target))
  close()
}

// Escape closes even when focus has wandered out of the input (a tapped row, a scrollbar).
onKeyStroke('Escape', () => { if (open.value) close() })

defineExpose({ show, toggle })
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-100"
      leave-active-class="transition duration-75"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-[80] bg-black/40"
        :class="narrow ? '' : 'flex items-start justify-center p-4 pt-[10vh]'"
        @click.self="close"
      >
        <div
          class="flex flex-col overflow-hidden bg-background shadow-xl"
          :class="narrow
            ? 'safe-inset h-full w-full'
            : 'max-h-[70vh] w-full max-w-xl rounded-xl border'"
          @click.stop
        >
          <div class="flex shrink-0 items-center gap-2 border-b px-3 py-2">
            <Search class="h-4 w-4 shrink-0 text-muted-foreground" />
            <Input
              ref="input"
              v-model="raw"
              placeholder="Search chats, channels, servers and messages…"
              class="h-9 border-0 shadow-none focus-visible:ring-0"
              autocomplete="off"
              @keydown.down.prevent="move(1)"
              @keydown.up.prevent="move(-1)"
              @keydown.enter.prevent="go()"
              @keydown.esc="close"
            />
            <Loader2 v-if="loading" class="h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
            <!-- A phone has no Escape key, and the backdrop it would tap is behind a
                 full-screen sheet. -->
            <button
              v-if="narrow"
              type="button"
              class="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-muted"
              aria-label="Close search"
              @click="close"
            >
              <X class="h-4 w-4" />
            </button>
          </div>

          <div ref="listEl" class="min-h-0 flex-1 overflow-y-auto p-2">
            <p v-if="term.length < MIN_TERM" class="px-2 py-6 text-center text-sm text-muted-foreground">
              Type to search everything you can see.
            </p>

            <p v-else-if="!rows.length && !loading" class="px-2 py-6 text-center text-sm text-muted-foreground">
              Nothing found.
            </p>

            <template v-for="(row, index) in rows" :key="`${row.kind}-${row.item.id}`">
              <p
                v-if="isGroupStart(index)"
                class="px-2 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground first:pt-1"
              >
                {{ row.group }}
              </p>

              <button
                type="button"
                :data-active="index === active"
                class="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left transition"
                :class="index === active ? 'bg-muted' : 'hover:bg-muted/60'"
                @mousemove="active = index"
                @click="go(row)"
              >
                <component :is="iconFor(row)" class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm">{{ labelFor(row) }}</span>
                  <span class="block truncate text-xs text-muted-foreground">{{ subtitleFor(row) }}</span>
                </span>
              </button>
            </template>
          </div>

          <!-- Keyboard legend, for the audience that opened this with a keystroke. Hidden on
               a phone, where none of these keys exist and the row is pure decoration. -->
          <div v-if="!narrow" class="flex shrink-0 items-center gap-3 border-t px-3 py-1.5 text-[11px] text-muted-foreground">
            <span><kbd class="rounded border px-1">↑</kbd><kbd class="ml-0.5 rounded border px-1">↓</kbd> navigate</span>
            <span><kbd class="rounded border px-1">↵</kbd> open</span>
            <span><kbd class="rounded border px-1">esc</kbd> close</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
