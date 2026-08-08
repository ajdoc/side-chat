<script setup lang="ts">
import { ArrowUpDown, Hash, Lock, Map as MapIcon, Menu, MessagesSquare, Pin, Plus, Search, Volume2 } from 'lucide-vue-next'
import type { Channel } from '~/types'
import type { DiscussionSort } from '~/composables/useDiscussions'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

definePageMeta({ middleware: 'auth', layout: 'app' })

/**
 * Every discussion in a channel, as a directory.
 *
 * The header's picker answers "where else can I go" in one click, which is the right shape for
 * three discussions and the wrong shape for thirty. This is the other half: a list you can
 * search and sort, showing what the sidebar has never had room to say — how much has been said
 * in each conversation, and when anybody last did.
 *
 * Its own route rather than a panel over the timeline, because it is a place you navigate *to*:
 * you arrive from the channel name in the breadcrumb, and Back returns you to whatever you were
 * reading.
 */
const route = useRoute()
const { findChannel } = useServer()
const { list, canCreate } = useDiscussions()
// Below `md` the sidebar is a drawer, and this page draws its own header — so it has to carry
// the handle back to it, exactly as ChannelView does. Without this the directory is a screen a
// phone can reach and not leave.
const { narrow, toggle: toggleDrawer } = useNavDrawer()

const channelId = computed(() => Number(route.params.channelId))
const channel = computed(() => findChannel(channelId.value))

const term = ref('')
const sort = ref<DiscussionSort>('active')
const rows = ref<Channel[]>([])
const loading = ref(true)
const showNew = ref(false)

const SORTS: { value: DiscussionSort, label: string }[] = [
  { value: 'active', label: 'Recently active' },
  { value: 'created', label: 'Newest' },
  { value: 'busiest', label: 'Most messages' },
  { value: 'name', label: 'Name' },
]

/**
 * Refetched rather than filtered in place, because the counts and the ordering are the server's
 * answer and a client-side filter would quietly disagree with them. Debounced so typing a word
 * is one request rather than one per keystroke.
 */
let timer: ReturnType<typeof setTimeout> | undefined
watch([channelId, term, sort], () => {
  clearTimeout(timer)
  timer = setTimeout(load, term.value ? 250 : 0)
}, { immediate: true })

onBeforeUnmount(() => clearTimeout(timer))

async function load() {
  if (!channelId.value) return
  loading.value = true
  try {
    rows.value = await list(channelId.value, { q: term.value, sort: sort.value })
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

const Icon = computed(() => channel.value?.type === 'space'
  ? MapIcon
  : channel.value?.type === 'voice' ? Volume2 : Hash)

function pathTo(discussion: Channel) {
  return `/servers/${route.params.serverId}/channels/${discussion.id}`
}

/** Short and absolute. "3 days ago" is friendlier; a date is what people actually scan a list by. */
function lastActivity(discussion: Channel) {
  if (!discussion.last_message_at) return 'No messages yet'

  const date = new Date(discussion.last_message_at)
  const today = date.toDateString() === new Date().toDateString()

  return today
    ? `Today at ${date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
    : date.toLocaleDateString([], { month: 'short', day: 'numeric', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' })
}

function onCreated(discussion: Channel) {
  navigateTo(pathTo(discussion))
}
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-4">
      <button
        v-if="narrow"
        type="button"
        class="-ml-1 shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        title="Channels and chats"
        @click="toggleDrawer"
      >
        <Menu class="h-5 w-5" />
      </button>
      <component :is="Icon" class="h-5 w-5 shrink-0 text-muted-foreground" />
      <div class="min-w-0">
        <p class="truncate font-semibold leading-tight">{{ channel?.name ?? 'Discussions' }}</p>
        <p class="truncate text-xs leading-tight text-muted-foreground">
          {{ rows.length }} {{ rows.length === 1 ? 'discussion' : 'discussions' }}
        </p>
      </div>
      <!-- Icon-only on a phone: the label is the part you can do without when the title needs
           the width more. -->
      <Button
        v-if="canCreate"
        size="sm"
        class="ml-auto shrink-0 gap-2"
        :class="narrow && 'px-2'"
        title="New discussion"
        @click="showNew = true"
      >
        <Plus class="h-4 w-4" /> <span v-if="!narrow">New discussion</span>
      </Button>
    </header>

    <div class="flex shrink-0 flex-wrap items-center gap-2 border-b px-4 py-2">
      <div class="relative min-w-48 flex-1">
        <Search class="pointer-events-none absolute left-2 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="term" placeholder="Search discussions…" class="pl-8" />
      </div>
      <label class="flex shrink-0 items-center gap-1.5 text-sm text-muted-foreground">
        <ArrowUpDown class="h-4 w-4" />
        <select v-model="sort" class="h-9 rounded-md border bg-background px-2 text-sm text-foreground">
          <option v-for="s in SORTS" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </label>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
      <p v-if="loading && !rows.length" class="p-6 text-center text-sm text-muted-foreground">
        Loading discussions…
      </p>
      <p v-else-if="!rows.length" class="p-6 text-center text-sm text-muted-foreground">
        {{ term ? `Nothing here matches “${term}”.` : 'No discussions yet.' }}
      </p>

      <NuxtLink
        v-for="d in rows"
        :key="d.id"
        :to="pathTo(d)"
        class="flex items-center gap-3 border-b px-4 py-3 transition hover:bg-muted active:bg-muted"
      >
        <MessagesSquare class="h-4 w-4 shrink-0 text-muted-foreground" />
        <div class="min-w-0 flex-1">
          <p class="flex items-center gap-1.5 truncate font-medium">
            <span class="truncate">{{ d.name }}</span>
            <Lock v-if="d.is_private" class="h-3 w-3 shrink-0 text-muted-foreground" title="Only chosen members" />
            <!-- Where this channel opens for you. Worth a mark in the one list that shows
                 every discussion side by side. -->
            <Pin
              v-if="channel?.default_child_id === d.id"
              class="h-3 w-3 shrink-0 text-muted-foreground"
              title="You open the channel here"
            />
          </p>
          <p class="truncate text-xs text-muted-foreground">
            {{ d.message_count }} {{ d.message_count === 1 ? 'message' : 'messages' }} · {{ lastActivity(d) }}
          </p>
        </div>
        <span
          v-if="d.unread_count"
          class="shrink-0 rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-primary-foreground"
        >{{ d.unread_count > 99 ? '99+' : d.unread_count }}</span>
      </NuxtLink>
    </div>

    <NewDiscussionDialog v-model:open="showNew" :parent="channel" @created="onCreated" />
  </div>
</template>
