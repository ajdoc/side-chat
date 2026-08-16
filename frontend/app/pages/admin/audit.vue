<script setup lang="ts">
import { Bot, Lock, Search, Trash2, LoaderCircle, X } from 'lucide-vue-next'
import type { AdminMessage } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

/**
 * The audit view: read any timeline on the instance, and take a message down.
 *
 * This is where the other three screens lead. A report names a person or a room, and the
 * questions that follow are always the same — what was said, and where — so the filters are
 * author, room, text and a date range, and they compose. Every other screen deep-links in
 * here with one of them pre-filled.
 *
 * Two things it deliberately doesn't do:
 *
 * - **It never opens on everything.** The list is paginated and newest-first, and it's meant
 *   to answer a question you already have rather than to be read from the top.
 * - **It doesn't decrypt.** A message from an end-to-end encrypted timeline appears in the
 *   list, flagged, with no body — the server holds ciphertext and no key. That's the feature
 *   working. Saying "encrypted" is the honest answer; an empty bubble would not be.
 */
definePageMeta({ middleware: 'admin', layout: 'admin' })

const route = useRoute()
const { messages, deleteMessage } = useAdmin()

const rows = ref<AdminMessage[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')

/** Seeded from the query string — every deep link into this page arrives that way. */
const filters = reactive({
  q: (route.query.q as string) ?? '',
  user_id: route.query.user_id ? Number(route.query.user_id) : undefined,
  channel_id: route.query.channel_id ? Number(route.query.channel_id) : undefined,
  conversation_id: route.query.conversation_id ? Number(route.query.conversation_id) : undefined,
  server_id: route.query.server_id ? Number(route.query.server_id) : undefined,
  from: (route.query.from as string) ?? '',
  to: (route.query.to as string) ?? '',
})

/** The narrowing currently in force, as removable chips. */
const activeChips = computed(() => [
  { key: 'user_id' as const, label: `Author #${filters.user_id}`, on: !!filters.user_id },
  { key: 'channel_id' as const, label: `Channel #${filters.channel_id}`, on: !!filters.channel_id },
  { key: 'conversation_id' as const, label: `Chat #${filters.conversation_id}`, on: !!filters.conversation_id },
  { key: 'server_id' as const, label: `Server #${filters.server_id}`, on: !!filters.server_id },
].filter(chip => chip.on))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await messages({ ...filters, page: page.value })
    rows.value = res.data
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load messages.'
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

function search() {
  page.value = 1
  load()
}

function clearChip(key: 'user_id' | 'channel_id' | 'conversation_id' | 'server_id') {
  filters[key] = undefined
  search()
}

watch(page, load)

/** Where a message was said, in one readable line. */
function location(row: AdminMessage) {
  const channel = row.channel
  if (!channel) return 'Deleted channel'
  if (channel.conversation_id) return `Private chat #${channel.conversation_id}`
  return channel.server_name ? `#${channel.name} · ${channel.server_name}` : `#${channel.name}`
}

const stamp = (iso: string) => new Date(iso).toLocaleString()

const deleting = ref<AdminMessage | null>(null)
const deleteBusy = ref(false)
const deleteError = ref('')

async function confirmDelete() {
  if (!deleting.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await deleteMessage(deleting.value.id)
    rows.value = rows.value.filter(r => r.id !== deleting.value!.id)
    total.value = Math.max(0, total.value - 1)
    deleting.value = null
  }
  catch (e: any) {
    deleteError.value = e?.data?.message ?? 'Could not delete that message.'
  }
  finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <AdminHeader
      title="Message audit"
      subtitle="Search any timeline on the instance. Filters compose — narrow by author, room and date together."
    />

    <form class="mb-4 grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="search">
      <div class="space-y-1.5 sm:col-span-2">
        <Label for="audit-q">Text</Label>
        <div class="relative">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input id="audit-q" v-model="filters.q" placeholder="Words in the message" class="h-9 pl-9" />
        </div>
      </div>
      <div class="space-y-1.5">
        <Label for="audit-user">Author ID</Label>
        <Input id="audit-user" v-model.number="filters.user_id" inputmode="numeric" class="h-9" />
      </div>
      <div class="space-y-1.5">
        <Label for="audit-from">From</Label>
        <Input id="audit-from" v-model="filters.from" type="date" class="h-9" />
      </div>
      <div class="space-y-1.5">
        <Label for="audit-to">To</Label>
        <Input id="audit-to" v-model="filters.to" type="date" class="h-9" />
      </div>

      <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-5">
        <Button type="submit" size="sm" :disabled="loading">{{ loading ? 'Searching…' : 'Search' }}</Button>

        <button
          v-for="chip in activeChips"
          :key="chip.key"
          type="button"
          class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
          @click="clearChip(chip.key)"
        >
          {{ chip.label }} <X class="h-3 w-3" />
        </button>
      </div>
    </form>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="h-4 w-4 animate-spin" /> Loading…
    </div>

    <p v-else-if="!rows.length" class="text-sm text-muted-foreground">
      Nothing matches those filters.
    </p>

    <ul v-else class="divide-y rounded-lg border bg-card">
      <li v-for="row in rows" :key="row.id" class="flex gap-3 p-3">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <span class="font-medium text-foreground">{{ row.author?.name ?? 'Deleted account' }}</span>
            <Bot v-if="row.author?.is_bot" class="h-3 w-3" aria-label="Bot" />
            <span>·</span>
            <NuxtLink
              v-if="row.channel"
              :to="`/admin/audit?channel_id=${row.channel.id}`"
              class="hover:text-foreground hover:underline"
            >
              {{ location(row) }}
            </NuxtLink>
            <span v-else>{{ location(row) }}</span>
            <span>·</span>
            <time :datetime="row.created_at">{{ stamp(row.created_at) }}</time>
            <span v-if="row.edited_at" class="italic">edited</span>
          </div>

          <p v-if="row.encrypted" class="mt-1 flex items-center gap-1.5 text-sm italic text-muted-foreground">
            <Lock class="h-3.5 w-3.5" />
            End-to-end encrypted — the server holds no key for this message.
          </p>
          <p v-else-if="row.body" class="mt-1 whitespace-pre-wrap break-words text-sm">{{ row.body }}</p>
          <p v-else class="mt-1 text-sm italic text-muted-foreground">
            No text{{ row.attachments_count ? ` · ${row.attachments_count} attachment(s)` : '' }}
          </p>
        </div>

        <div class="flex shrink-0 items-start gap-1.5">
          <NuxtLink :to="`/admin/audit?user_id=${row.author?.id}`" class="text-xs text-primary hover:underline">
            <Button v-if="row.author" variant="ghost" size="sm">Author's messages</Button>
          </NuxtLink>
          <Button variant="ghost" size="sm" class="text-destructive" @click="deleting = row">
            <Trash2 class="h-3.5 w-3.5" />
          </Button>
        </div>
      </li>
    </ul>

    <AdminPager v-model:page="page" :last-page="lastPage" :total="total" :busy="loading" />

    <ConfirmDialog
      :open="!!deleting"
      title="Delete this message?"
      description="It disappears from the timeline for everyone, along with any files attached to it. This cannot be undone."
      confirm-label="Delete message"
      busy-label="Deleting…"
      :busy="deleteBusy"
      :error="deleteError"
      @update:open="v => { if (!v) deleting = null }"
      @confirm="confirmDelete"
    />
  </div>
</template>
