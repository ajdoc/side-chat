<script setup lang="ts">
import { CornerUpLeft, Filter, FolderOpen, Hash, Image as ImageIcon, Link2, Loader2, LockKeyhole, MessagesSquare, Paperclip, Search, Sparkles, X } from 'lucide-vue-next'
import type { SearchFilters, SearchHas, SearchMessage, SearchSurface } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * Searching *this* conversation — the right-hand column, beside the timeline.
 *
 * Scoped to the channel by default and widened by a chip, rather than the other way round.
 * That's not a guess: you open this while reading something, looking for a thing you know
 * was said here, and a panel that answered with the whole app would bury it. The palette
 * (⌘K) is the everywhere-search, and it's one keystroke away.
 *
 * Filters are typed tokens *and* chips, both writing the same input box — see
 * parseSearchQuery for why one source of truth beats a row of dropdowns beside a text field.
 */
const props = defineProps<{ channelId: number }>()

const emit = defineEmits<{ jump: [messageId: number, channelId: number] }>()

// Below `md` there's no room for a column beside the timeline: the panel takes the whole
// screen, exactly as the Info and Thread panels do, and its close button is the way back.
const { narrow } = useNavDrawer()
const { width: panelWidth, startResize } = useResizable('channel-search', 380, { min: 300, max: 640 })
const surface = useSurfaceRoute()
const { nameFor } = useNicknames()
const { members, load: loadMembers } = useChannelMembers()
const { server } = useServer()

const { messages, surfaces, encryptedSkipped, loading, hasMore, searchMessages, searchSurfaces, debounced, reset, MIN_TERM } = useSearch()

const raw = ref('')
// The `Input` component's ref is the component; the element it wraps is its `$el`.
const input = ref<any>(null)
const focusInput = () => (input.value?.$el ?? input.value)?.focus?.()

/** Where the search reaches. Narrowest first — see the note at the top. */
type Scope = 'channel' | 'server' | 'everywhere'
const scope = ref<Scope>('channel')

const parsed = computed(() => parseSearchQuery(raw.value))

/**
 * `from:ana` as an id.
 *
 * Matched against the channel's roster rather than searched for on the server: the people
 * worth naming in a channel search are the people in the channel, and resolving it here
 * means an unmatched name narrows to nobody instead of being silently ignored.
 */
const fromUser = computed(() => {
  const name = parsed.value.fromName?.toLowerCase()
  if (!name) return null
  return members.value.find(m => nameFor(m).toLowerCase().includes(name) || m.name.toLowerCase().includes(name)) ?? null
})

const filters = computed<SearchFilters>(() => {
  const base: SearchFilters = {}
  if (scope.value === 'channel') base.channel_id = props.channelId
  // Only meaningful in a server — a DM has no wider place to widen to, which is why the
  // Server chip isn't offered there at all.
  else if (scope.value === 'server' && server.value) base.server_id = server.value.id

  if (fromUser.value) base.from = fromUser.value.id
  if (parsed.value.has) base.has = parsed.value.has
  if (parsed.value.after) base.after = parsed.value.after
  if (parsed.value.before) base.before = parsed.value.before

  return base
})

const term = computed(() => parsed.value.term)
const tooShort = computed(() => term.value.length > 0 && term.value.length < MIN_TERM)
// An unresolved `from:` is worth saying out loud: silently returning nothing looks like
// "nobody said that" rather than "I don't know who you mean".
const unknownPerson = computed(() => parsed.value.fromName !== null && fromUser.value === null)

function run() {
  if (term.value.length < MIN_TERM) {
    reset()
    return
  }
  searchMessages(term.value, filters.value)
  // The places matching the same words, for the strip above the results. A side chat titled
  // "Deploy plan" is very often what somebody typing "deploy" in here was actually after,
  // and it would otherwise be buried under every message that mentions deploying.
  searchSurfaces(term.value, filters.value)
}

watch([raw, scope], () => debounced(run))

/** Append a token to the box, or drop it if it's already there. Chips write the syntax. */
function toggleToken(token: string) {
  const pattern = new RegExp(`(^|\\s)${token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(\\s|$)`, 'i')
  raw.value = pattern.test(raw.value)
    ? raw.value.replace(pattern, ' ').replace(/\s+/g, ' ').trim()
    : `${raw.value.trim()} ${token}`.trim()
  focusInput()
}

function hasToken(token: string) {
  return new RegExp(`(^|\\s)${token}(\\s|$)`, 'i').test(raw.value)
}

const attachmentChips: { has: SearchHas, label: string, icon: any }[] = [
  { has: 'link', label: 'Links', icon: Link2 },
  { has: 'image', label: 'Images', icon: ImageIcon },
  { has: 'file', label: 'Files', icon: Paperclip },
]

/**
 * Clicking a result.
 *
 * Only the main timeline can scroll to a message, so a hit inside a thread or a side chat
 * opens that surface instead of pretending it can jump. Both are addressed by the URL,
 * which is also how the rest of this app opens them — see ChannelView.patchQuery.
 */
function open(message: SearchMessage) {
  const context = message.context

  if (message.side_chat_id) {
    surface.patch({ sidechat: String(message.side_chat_id), sidechats: null, info: null, desk: null })
    return
  }
  if (message.thread_id) {
    surface.patch({ thread: String(message.thread_id), threads: null, from: null, info: null, desk: null })
    return
  }

  emit('jump', message.id, context.channel_id)
  // On a phone the panel is the whole screen, so leaving it open would hide the very
  // message we just jumped to.
  if (narrow.value) close()
}

function surfaceIcon(surface: SearchSurface) {
  if (surface.kind === 'side_chat_group') return FolderOpen
  return surface.kind === 'thread' ? MessagesSquare : Sparkles
}

/** The one-line "where and what" under a place's name. */
function surfaceSubtitle(surface: SearchSurface) {
  if (surface.kind === 'side_chat_group') return 'Side chat group'
  if (surface.kind === 'thread') return surface.side_chat_name ? `Thread in ${surface.side_chat_name}` : 'Thread'
  return surface.group_name ? `Side chat in ${surface.group_name}` : 'Side chat'
}

/**
 * Opening a place from the strip.
 *
 * Same query keys the palette uses, but patched onto the surface we're already on rather
 * than navigated to — this panel only ever lists places in scope, and for the common case
 * (this channel) there is nowhere to navigate.
 */
function openSurface(place: SearchSurface) {
  // Widened past this channel, and the hit is somewhere else: that's a navigation, and the
  // destination reads these same keys off the URL it mounts with.
  if (place.channel_id !== props.channelId) {
    navigateTo(pathToSurface(place))
    return
  }

  if (place.kind === 'side_chat') openColumn({ sidechat: String(place.id), sidechats: null })
  else if (place.kind === 'side_chat_group') openColumn({ sidechats: '1', sidechat: null, scforum: String(place.id) })
  // A side chat's thread needs the post opened around it — the `sc`-prefixed keys are what
  // let the two columns stand together.
  else if (place.side_chat_id) openColumn({ sidechat: String(place.side_chat_id), scthread: String(place.id), sidechats: null })
  else openColumn({ thread: String(place.id), threads: null, from: null })

  if (narrow.value) close()
}

/**
 * Swap this panel for the column that was picked.
 *
 * Search closes itself on the way: a thread and this panel both want the whole side column,
 * so leaving it open would mean opening a place and not being shown it.
 */
function openColumn(patch: Record<string, string | null>) {
  surface.patch({ info: null, desk: null, search: null, ...patch })
}

/** The same routing as the palette's, for the rare hit that lives in another channel. */
function pathToSurface(place: SearchSurface) {
  const base = place.conversation_id
    ? `/chats/${place.conversation_id}`
    : `/servers/${place.server_id}/channels/${place.channel_id}`

  if (place.kind === 'side_chat') return `${base}?sidechat=${place.id}`
  if (place.kind === 'side_chat_group') return `${base}?sidechats=1&scforum=${place.id}`
  return place.side_chat_id ? `${base}?sidechat=${place.side_chat_id}&scthread=${place.id}` : `${base}?thread=${place.id}`
}

/** A result in another channel can't be jumped to from here — it's a navigation. */
function isElsewhere(message: SearchMessage) {
  return message.context.channel_id !== props.channelId
}

function pathTo(message: SearchMessage) {
  const c = message.context
  return c.conversation_id
    ? `/chats/${c.conversation_id}`
    : `/servers/${c.server_id}/channels/${c.channel_id}`
}

function close() {
  surface.patch({ search: null })
}

/** Excerpt for a result row: the body, or a word for whatever the message is instead. */
function preview(message: SearchMessage) {
  if (message.body) return message.body
  if (message.attachments?.length) return `${message.attachments.length} attachment${message.attachments.length > 1 ? 's' : ''}`
  return 'No text'
}

/**
 * The matched words, wrapped for highlighting.
 *
 * Done here rather than server-side (`ts_headline` was the obvious alternative) so that the
 * highlight is identical on both search drivers and so the row still shows the *whole*
 * message rather than a fragment the database chose. Every term is escaped before it goes
 * near the regex, and the body is escaped before the marks go in — this string is rendered
 * as HTML.
 */
function highlight(text: string) {
  const escaped = text.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[ch]!))
  const words = term.value.split(/\s+/).filter(w => w.length >= MIN_TERM)
  if (!words.length) return escaped

  const pattern = new RegExp(`(${words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'gi')
  return escaped.replace(pattern, '<mark class="rounded bg-primary/20 text-foreground">$1</mark>')
}

function formatWhen(iso: string) {
  const date = new Date(iso)
  const sameYear = date.getFullYear() === new Date().getFullYear()
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', ...(sameYear ? {} : { year: 'numeric' }) })
}

onMounted(() => {
  loadMembers(props.channelId)
  // The panel is opened by someone who intends to type. Not on a phone, though: the
  // keyboard springing up would cover the scope chips before they've been seen.
  if (!narrow.value) nextTick(focusInput)
})

// Re-run when the channel changes underneath us (the split view docks another one).
watch(() => props.channelId, () => {
  loadMembers(props.channelId)
  if (term.value.length >= MIN_TERM) run()
})

onBeforeUnmount(reset)
</script>

<template>
  <aside
    class="flex flex-col border-l bg-background"
    :class="narrow ? 'safe-inset fixed inset-0 z-50 w-full' : 'relative shrink-0'"
    :style="narrow ? undefined : { width: `${panelWidth}px` }"
  >
    <ResizeHandle v-if="!narrow" edge="left" @resize="startResize" />

    <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
      <span class="flex items-center gap-2 font-semibold">
        <Search class="h-4 w-4" /> Search
      </span>
      <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="close">
        <X class="h-4 w-4" />
      </button>
    </header>

    <div class="shrink-0 space-y-2 border-b p-3">
      <Input
        ref="input"
        v-model="raw"
        placeholder="Search messages…"
        class="h-9"
        autocomplete="off"
        @keydown.esc="close"
      />

      <!-- Scope. Narrowest first, and the one you're in is the default. -->
      <div class="scroll-strip flex items-center gap-1 [&>*]:shrink-0">
        <button
          v-for="s in (server ? ['channel', 'server', 'everywhere'] : ['channel', 'everywhere'])"
          :key="s"
          type="button"
          class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
          :class="scope === s ? 'border-primary bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted'"
          @click="scope = s as Scope"
        >
          {{ s === 'channel' ? 'This channel' : s === 'server' ? 'This server' : 'Everywhere' }}
        </button>
      </div>

      <!-- Filter chips. Each writes the token it stands for into the box above, so the two
           ways of asking are the same way of asking. -->
      <div class="scroll-strip flex items-center gap-1 [&>*]:shrink-0">
        <Filter class="h-3.5 w-3.5 text-muted-foreground" />
        <button
          v-for="chip in attachmentChips"
          :key="chip.has"
          type="button"
          class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium transition"
          :class="hasToken(`has:${chip.has}`) ? 'border-primary bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted'"
          @click="toggleToken(`has:${chip.has}`)"
        >
          <component :is="chip.icon" class="h-3 w-3" /> {{ chip.label }}
        </button>
      </div>

      <p v-if="unknownPerson" class="text-xs text-amber-600 dark:text-amber-500">
        No one here matches <span class="font-medium">from:{{ parsed.fromName }}</span>
      </p>
      <p v-else-if="tooShort" class="text-xs text-muted-foreground">
        Keep typing — at least {{ MIN_TERM }} characters.
      </p>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-3">
      <div v-if="loading && !messages.length" class="flex justify-center py-6">
        <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
      </div>

      <p v-else-if="!term" class="py-6 text-center text-sm text-muted-foreground">
        Search this channel. Try <span class="font-mono text-xs">from:someone</span>,
        <span class="font-mono text-xs">has:link</span> or
        <span class="font-mono text-xs">before:2026-01-01</span>.
      </p>

      <p v-else-if="!messages.length && !surfaces.length && !tooShort" class="py-6 text-center text-sm text-muted-foreground">
        Nothing found.
      </p>

      <!--
        The blind spot, stated.

        Encrypted messages can't be searched by the server and never will be — it has no keys.
        Leaving this out would make "your search missed it" indistinguishable from "you never
        said it", which is the wrong thing to let somebody conclude about their own history.
      -->
      <p
        v-if="encryptedSkipped > 0 && !tooShort"
        class="mb-2 flex items-start gap-1.5 rounded-md border bg-muted/40 px-2.5 py-2 text-xs text-muted-foreground"
      >
        <LockKeyhole class="mt-0.5 size-3 shrink-0" />
        <span>
          {{ encryptedSkipped }} encrypted
          {{ encryptedSkipped === 1 ? 'message was' : 'messages were' }} not searched.
          Encrypted messages can only be read on your own devices.
        </span>
      </p>

      <!-- Places matching the same words, above the messages. Titles are what people
           deliberately wrote to be found again, so a match on one usually beats a passing
           mention in a sentence — and there are never many, so it costs a few rows. -->
      <div v-if="surfaces.length" class="mb-3 space-y-1">
        <p class="px-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          Places
        </p>
        <button
          v-for="place in surfaces"
          :key="`${place.kind}-${place.id}`"
          type="button"
          class="flex w-full items-center gap-2 rounded-md border bg-muted/20 px-2 py-1.5 text-left transition hover:bg-muted/60"
          @click="openSurface(place)"
        >
          <component :is="surfaceIcon(place)" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm">{{ place.name }}</span>
            <span class="block truncate text-xs text-muted-foreground">{{ surfaceSubtitle(place) }}</span>
          </span>
        </button>
      </div>

      <div v-if="messages.length" class="space-y-2">
        <component
          :is="isElsewhere(message) ? 'NuxtLink' : 'button'"
          v-for="message in messages"
          :key="message.id"
          :to="isElsewhere(message) ? pathTo(message) : undefined"
          class="group/hit block w-full rounded-md border bg-muted/30 p-2 text-left transition hover:bg-muted/60"
          @click="isElsewhere(message) ? undefined : open(message)"
        >
          <div class="flex items-baseline gap-2">
            <span class="truncate text-sm font-medium">{{ nameFor(message.user) }}</span>
            <span class="shrink-0 text-xs text-muted-foreground">{{ formatWhen(message.created_at) }}</span>
            <CornerUpLeft class="ml-auto h-3 w-3 shrink-0 text-muted-foreground opacity-0 transition group-hover/hit:opacity-100" />
          </div>

          <!-- eslint-disable-next-line vue/no-v-html -- escaped in highlight(), which is
               the only thing that puts markup in this string. -->
          <p class="line-clamp-3 whitespace-pre-wrap break-words text-sm" v-html="highlight(preview(message))" />

          <!-- Where it was said. Always shown when the result isn't from the channel you're
               looking at, and shown for a branch even when it is — "in this channel" and
               "in a thread in this channel" are different places to land. -->
          <p
            v-if="isElsewhere(message) || message.thread_id || message.side_chat_id"
            class="mt-1 flex items-center gap-1 truncate text-xs text-muted-foreground"
          >
            <Hash class="h-3 w-3 shrink-0" />
            <span class="truncate">
              {{ message.context.channel_name }}
              <template v-if="message.context.thread_name"> › {{ message.context.thread_name }}</template>
              <template v-else-if="message.context.side_chat_name"> › {{ message.context.side_chat_name }}</template>
            </span>
          </p>
        </component>

        <Button
          v-if="hasMore"
          variant="outline"
          size="sm"
          class="mt-2 w-full"
          :disabled="loading"
          @click="searchMessages(term, filters, true)"
        >
          {{ loading ? 'Loading…' : 'Load more' }}
        </Button>
      </div>
    </div>
  </aside>
</template>
