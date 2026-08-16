<script setup lang="ts">
import { LoaderCircle, Lock, ScrollText, Search, Trash2, Users } from 'lucide-vue-next'
import type { AdminConversation } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * DMs and group chats.
 *
 * The narrowest screen in the panel, deliberately. A private conversation is not a room an
 * operator administers — there's no renaming somebody's DM here, no editing its membership,
 * and no joining it. What's offered is what an operator genuinely needs: see that a chat
 * exists and who's in it, find the ones a reported account is part of, read them through the
 * audit view when there's a reason to, and delete one outright when it has to go.
 *
 * Message *contents* are one deliberate click away rather than on this page, because "list
 * the chats" and "read this chat" should not be the same act.
 */
definePageMeta({ middleware: 'admin', layout: 'admin' })

const route = useRoute()
const { conversations, deleteConversation } = useAdmin()

const rows = ref<AdminConversation[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')
const search = ref('')
const type = ref<'' | 'dm' | 'group'>('')
// Set when you arrive from a user's row — "which chats is this account in?"
const userId = ref(route.query.user_id ? Number(route.query.user_id) : undefined)

const tabs: { key: '' | 'dm' | 'group', label: string }[] = [
  { key: '', label: 'All' },
  { key: 'dm', label: 'DMs' },
  { key: 'group', label: 'Groups' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await conversations({ q: search.value, type: type.value, user_id: userId.value, page: page.value })
    rows.value = res.data
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load chats.'
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
})
watch(type, () => { page.value = 1; load() })
watch(page, load)

/** A DM has no name of its own — it's whoever is in it. */
function title(row: AdminConversation) {
  if (row.name) return row.name
  const names = (row.members ?? []).map(m => m.name)
  return names.length ? names.join(' ↔ ') : `Chat #${row.id}`
}

const deleting = ref<AdminConversation | null>(null)
const deleteBusy = ref(false)
const deleteError = ref('')

async function confirmDelete() {
  if (!deleting.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await deleteConversation(deleting.value.id)
    rows.value = rows.value.filter(r => r.id !== deleting.value!.id)
    total.value = Math.max(0, total.value - 1)
    deleting.value = null
  }
  catch (e: any) {
    deleteError.value = e?.data?.message ?? 'Could not delete that chat.'
  }
  finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <AdminHeader
      title="DMs & groups"
      subtitle="Private conversations. Visible from the outside; readable only through the audit view."
    >
      <template #actions>
        <div class="relative">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="search" placeholder="Search by group name or member" class="h-9 w-72 pl-9" />
        </div>
      </template>
    </AdminHeader>

    <div class="mb-4 flex items-center gap-1">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="rounded-md px-3 py-1.5 text-sm transition-colors"
        :class="type === tab.key ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted'"
        @click="type = tab.key"
      >
        {{ tab.label }}
      </button>

      <button
        v-if="userId"
        class="ml-2 rounded-md bg-muted px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground"
        @click="userId = undefined; page = 1; load()"
      >
        Filtered to user #{{ userId }} — clear
      </button>
    </div>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="h-4 w-4 animate-spin" /> Loading…
    </div>

    <p v-else-if="!rows.length" class="text-sm text-muted-foreground">No chats match that.</p>

    <ul v-else class="divide-y rounded-lg border bg-card">
      <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-3 p-3">
        <span
          class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
          :class="row.type === 'group' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'"
        >
          {{ row.type === 'group' ? 'Group' : 'DM' }}
        </span>

        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium">{{ title(row) }}</p>
          <p class="truncate text-xs text-muted-foreground">
            {{ (row.members ?? []).map(m => m.email).join(', ') }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-4 text-xs text-muted-foreground tabular-nums">
          <span class="flex items-center gap-1"><Users class="h-3 w-3" /> {{ row.members_count ?? 0 }}</span>
          <span>{{ (row.channel?.messages_count ?? 0).toLocaleString() }} messages</span>
          <!-- An encrypted chat is listable but not readable: the server holds ciphertext
               and no key, and the audit view says so rather than showing an empty timeline. -->
          <Lock v-if="row.channel?.encrypted" class="h-3.5 w-3.5" aria-label="End-to-end encrypted" />
        </div>

        <div class="flex shrink-0 items-center gap-1.5">
          <NuxtLink :to="`/admin/audit?conversation_id=${row.id}`">
            <Button variant="ghost" size="sm"><ScrollText class="h-3.5 w-3.5" /> Messages</Button>
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
      title="Delete this chat?"
      :description="`${deleting ? title(deleting) : ''} and its ${(deleting?.channel?.messages_count ?? 0).toLocaleString()} messages will be permanently removed for everyone in it.`"
      confirm-label="Delete chat"
      busy-label="Deleting…"
      :busy="deleteBusy"
      :error="deleteError"
      @update:open="v => { if (!v) deleting = null }"
      @confirm="confirmDelete"
    />
  </div>
</template>
