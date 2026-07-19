<script setup lang="ts">
import { Check, ChevronDown, ChevronRight, Hash, Loader2, Search, Send, Users, Volume2 } from 'lucide-vue-next'
import type { Channel, Message, User } from '~/types'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'

/**
 * Forward a message somewhere else.
 *
 * The destination is always, underneath, a *channel id* — a DM and a group chat each own
 * one, and a server channel is one — which is exactly what the forward endpoint takes. So
 * this picker's whole job is to offer every place you could post and hand back the channel
 * behind it: your chats up top (already in memory from the sidebar), then people you share
 * a server with (a DM you may not have opened yet — picking one *makes* the channel on
 * send), then each server, unfolded on demand into its channels because loading every
 * channel of every server up front would be a lot of requests for a list you'll pick one
 * row from.
 */
const message = defineModel<Message | null>('message', { required: true })

const api = useApi()
const { conversations, fetchConversations, contacts, openDirect } = useConversations()
const { servers, fetchServers } = useServers()
const { user } = useAuth()

const open = computed({
  get: () => message.value !== null,
  set: (v: boolean) => { if (!v) message.value = null },
})

const query = ref('')
const working = ref(false)
const error = ref('')
// The place we just forwarded to — shown for a beat before the dialog closes itself.
const sentTo = ref('')

/**
 * The chosen destination. A `channel` already exists (a chat, a group, a server channel);
 * a `person` might not have a DM yet — that channel is resolved on send, not on click.
 */
type Target =
  | { kind: 'channel', channelId: number, label: string }
  | { kind: 'person', userId: number, label: string }
const target = ref<Target | null>(null)

function isChannelPicked(channelId: number) {
  return target.value?.kind === 'channel' && target.value.channelId === channelId
}
function isPersonPicked(userId: number) {
  return target.value?.kind === 'person' && target.value.userId === userId
}

// Channels per server, fetched the first time a server is unfolded. `null` = loading.
const serverChannels = ref<Record<number, Channel[] | null>>({})
const expanded = ref<Set<number>>(new Set())

// People you share a server with — fetched from the server (its own search), so the box
// filters chats/channels on the client but people on the server, like the New chat dialog.
const people = ref<User[]>([])
const loadingPeople = ref(false)
let peopleTimer: ReturnType<typeof setTimeout> | undefined

const q = computed(() => query.value.trim().toLowerCase())

const matchingChats = computed(() => {
  if (!q.value) return conversations.value
  return conversations.value.filter(c =>
    conversationTitle(c, user.value).toLowerCase().includes(q.value),
  )
})

// Anyone you already have a one-to-one DM with is offered as a chat above, so drop them
// from the people list — the same person twice, once as "open DM" and once as "new DM",
// would only be a choice between two identical outcomes.
const dmUserIds = computed(() => {
  const ids = new Set<number>()
  for (const c of conversations.value) {
    if (c.type === 'dm') {
      for (const m of otherMembers(c, user.value)) ids.add(m.id)
    }
  }
  return ids
})

const visiblePeople = computed(() => people.value.filter(p => !dmUserIds.value.has(p.id)))

async function loadPeople() {
  loadingPeople.value = true
  try {
    people.value = await contacts(query.value.trim())
  } catch {
    people.value = []
  } finally {
    loadingPeople.value = false
  }
}

// Debounced: this fires on every keystroke and the answer is a database query.
watch(query, () => {
  clearTimeout(peopleTimer)
  peopleTimer = setTimeout(loadPeople, 200)
})

function chatLabel(channelId: number, fallback: string) {
  const c = conversations.value.find(x => x.channel_id === channelId)
  return c ? conversationTitle(c, user.value) : fallback
}

async function toggleServer(serverId: number) {
  if (expanded.value.has(serverId)) {
    expanded.value.delete(serverId)
    expanded.value = new Set(expanded.value)
    return
  }
  expanded.value = new Set(expanded.value).add(serverId)

  if (!(serverId in serverChannels.value)) {
    serverChannels.value[serverId] = null
    try {
      // Every channel, voice included: a voice channel carries a text timeline too (the
      // text-in-voice chat), so it's just as valid a place to forward a message into.
      const res = await api<{ data: Channel[] }>(`/api/servers/${serverId}/channels?page=1`)
      serverChannels.value[serverId] = res.data
    } catch {
      serverChannels.value[serverId] = []
    }
  }
}

/** Channels of a server that match the search box (name match), once they're loaded. */
function visibleChannels(serverId: number): Channel[] {
  const list = serverChannels.value[serverId]
  if (!list) return []
  if (!q.value) return list
  return list.filter(c => c.name.toLowerCase().includes(q.value))
}

function pickChannel(channelId: number, label: string) {
  error.value = ''
  target.value = { kind: 'channel', channelId, label }
}
function pickPerson(person: User) {
  error.value = ''
  target.value = { kind: 'person', userId: person.id, label: person.name }
}

async function submit() {
  const t = target.value
  if (!t || !message.value || working.value) return
  working.value = true
  error.value = ''
  try {
    // A person has no channel until there's a DM with them — openDirect makes or finds it
    // (it's idempotent), and hands back the channel the forward actually lands in.
    const channelId = t.kind === 'person'
      ? (await openDirect(t.userId)).channel_id
      : t.channelId
    await api(`/api/messages/${message.value.id}/forward`, {
      method: 'POST',
      body: { channel_id: channelId },
    })
    sentTo.value = t.label
    // Hold the confirmation briefly so the forward doesn't feel like it vanished, then close.
    setTimeout(() => { open.value = false }, 900)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t forward that message.'
    working.value = false
  }
}

// Fresh each time it opens; make sure the two lists it draws from are actually loaded
// (they usually are, from the sidebar, but the dialog can't assume it).
watch(open, (isOpen) => {
  if (!isOpen) return
  query.value = ''
  target.value = null
  error.value = ''
  sentTo.value = ''
  working.value = false
  expanded.value = new Set()
  people.value = []
  fetchConversations()
  fetchServers()
  loadPeople()
})

onBeforeUnmount(() => clearTimeout(peopleTimer))
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Forward message</DialogTitle>
        <DialogDescription>
          Send it to a person, a group, or a channel you’re in.
        </DialogDescription>
      </DialogHeader>

      <!-- Confirmation: shown for a beat after a successful forward, before the dialog closes. -->
      <div v-if="sentTo" class="flex items-center gap-2 rounded-md border border-green-600/30 bg-green-600/10 px-3 py-2.5 text-sm text-green-700 dark:text-green-400">
        <Check class="h-4 w-4 shrink-0" /> Forwarded to <span class="font-medium">{{ sentTo }}</span>
      </div>

      <div v-else class="space-y-3">
        <div class="relative">
          <Search class="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="query" placeholder="Search people, chats and channels" class="pl-8" />
        </div>

        <div class="max-h-72 min-h-24 space-y-2 overflow-y-auto rounded-md border p-1">
          <!-- Chats: DMs and groups -->
          <div>
            <p class="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              Chats
            </p>
            <p v-if="!matchingChats.length" class="px-2 py-1 text-xs text-muted-foreground">
              {{ query ? 'No chats match.' : 'No chats yet.' }}
            </p>
            <button
              v-for="c in matchingChats"
              :key="`chat-${c.id}`"
              type="button"
              class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition hover:bg-muted"
              :class="isChannelPicked(c.channel_id) ? 'bg-muted' : ''"
              @click="pickChannel(c.channel_id, chatLabel(c.channel_id, ''))"
            >
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                <Users v-if="c.type === 'group'" class="h-3.5 w-3.5" />
                <img
                  v-else-if="conversationAvatar(c, user)"
                  :src="conversationAvatar(c, user)!"
                  :alt="conversationTitle(c, user)"
                  class="h-full w-full rounded-full object-cover"
                >
                <span v-else>{{ initialsOf(conversationTitle(c, user)) }}</span>
              </span>
              <span class="min-w-0 flex-1 truncate">{{ conversationTitle(c, user) }}</span>
              <Check v-if="isChannelPicked(c.channel_id)" class="h-4 w-4 shrink-0 text-primary" />
            </button>
          </div>

          <!-- People: anyone you share a server with. Picking one forwards into your DM with
               them, opening that DM if it doesn't exist yet. -->
          <div>
            <p class="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              People
            </p>
            <div v-if="loadingPeople && !visiblePeople.length" class="flex items-center gap-2 px-2 py-1.5 text-xs text-muted-foreground">
              <Loader2 class="h-3.5 w-3.5 animate-spin" /> Searching…
            </div>
            <p v-else-if="!visiblePeople.length" class="px-2 py-1 text-xs text-muted-foreground">
              {{ query ? 'No people match.' : 'Nobody new to message — you share no servers, or already chat with everyone.' }}
            </p>
            <button
              v-for="p in visiblePeople"
              :key="`person-${p.id}`"
              type="button"
              class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm transition hover:bg-muted"
              :class="isPersonPicked(p.id) ? 'bg-muted' : ''"
              @click="pickPerson(p)"
            >
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-secondary text-[9px] font-semibold text-secondary-foreground">
                <img v-if="p.avatar" :src="p.avatar" :alt="p.name" class="h-full w-full rounded-full object-cover">
                <span v-else>{{ initialsOf(p.name) }}</span>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate">{{ p.name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ p.email }}</span>
              </span>
              <Check v-if="isPersonPicked(p.id)" class="h-4 w-4 shrink-0 text-primary" />
            </button>
          </div>

          <!-- Servers: each unfolds into its text channels on demand -->
          <div>
            <p class="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              Servers
            </p>
            <p v-if="!servers.length" class="px-2 py-1 text-xs text-muted-foreground">
              You’re not in any servers.
            </p>
            <template v-for="s in servers" :key="`server-${s.id}`">
              <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-muted-foreground transition hover:bg-muted hover:text-foreground"
                @click="toggleServer(s.id)"
              >
                <ChevronDown v-if="expanded.has(s.id)" class="h-3.5 w-3.5 shrink-0" />
                <ChevronRight v-else class="h-3.5 w-3.5 shrink-0" />
                <span class="grid h-5 w-5 shrink-0 place-items-center rounded bg-secondary text-[9px] font-semibold text-secondary-foreground">
                  {{ initialsOf(s.name) }}
                </span>
                <span class="min-w-0 flex-1 truncate">{{ s.name }}</span>
              </button>

              <template v-if="expanded.has(s.id)">
                <div v-if="serverChannels[s.id] === null" class="flex items-center gap-2 py-1.5 pl-9 text-xs text-muted-foreground">
                  <Loader2 class="h-3.5 w-3.5 animate-spin" /> Loading channels…
                </div>
                <p
                  v-else-if="!visibleChannels(s.id).length"
                  class="py-1 pl-9 pr-2 text-xs text-muted-foreground"
                >
                  {{ query ? 'No channels match.' : 'No channels.' }}
                </p>
                <button
                  v-for="ch in visibleChannels(s.id)"
                  :key="`ch-${ch.id}`"
                  type="button"
                  class="flex w-full items-center gap-2 rounded py-1.5 pl-9 pr-2 text-left text-sm transition hover:bg-muted"
                  :class="isChannelPicked(ch.id) ? 'bg-muted' : ''"
                  @click="pickChannel(ch.id, `${s.name} · ${ch.type === 'voice' ? '' : '#'}${ch.name}`)"
                >
                  <Volume2 v-if="ch.type === 'voice'" class="h-4 w-4 shrink-0" />
                  <Hash v-else class="h-4 w-4 shrink-0" />
                  <span class="min-w-0 flex-1 truncate">{{ ch.name }}</span>
                  <Check v-if="isChannelPicked(ch.id)" class="h-4 w-4 shrink-0 text-primary" />
                </button>
              </template>
            </template>
          </div>
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div class="flex items-center justify-between gap-2">
          <span class="min-w-0 truncate text-xs text-muted-foreground">
            <template v-if="target">To <span class="font-medium text-foreground">{{ target.label }}</span></template>
          </span>
          <div class="flex shrink-0 gap-2">
            <Button variant="outline" :disabled="working" @click="open = false">Cancel</Button>
            <Button class="gap-1.5" :disabled="!target || working" @click="submit">
              <Send class="h-3.5 w-3.5" /> {{ working ? 'Forwarding…' : 'Forward' }}
            </Button>
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
