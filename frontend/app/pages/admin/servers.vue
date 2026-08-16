<script setup lang="ts">
import {
  ChevronDown, ChevronRight, Hash, LoaderCircle, Lock, Map as MapIcon,
  LayoutList, Pencil, ScrollText, Search, Trash2, Volume2,
} from 'lucide-vue-next'
import type { AdminChannel, AdminServer, ChannelType } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * Servers, and the channels inside them.
 *
 * A list that expands rather than a master/detail split: the question an operator arrives
 * with is almost always about one server, and expanding it in place keeps the others on
 * screen while you compare. Channels are only fetched when a row is opened — every server on
 * the instance eagerly loading its channels is a lot of rows nobody asked for.
 *
 * The edit dialog can reassign ownership, which is the one thing here the app itself cannot
 * do: an owner has no way to hand their server over from inside Side Chat, so a server whose
 * owner has left has no route back to a live one that doesn't come through this screen.
 */
definePageMeta({ middleware: 'admin', layout: 'admin' })

const { servers, server: fetchServer, updateServer, deleteServer, updateChannel, deleteChannel } = useAdmin()

const rows = ref<AdminServer[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')
const search = ref('')

/** Which servers are open, and the channels we've fetched for them. */
const expanded = ref<Set<number>>(new Set())
const channels = ref<Record<number, AdminChannel[]>>({})
const loadingChannels = ref<Set<number>>(new Set())

const channelIcon = (type: ChannelType) =>
  ({ text: Hash, voice: Volume2, space: MapIcon, app: LayoutList })[type] ?? Hash

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await servers({ q: search.value, page: page.value })
    rows.value = res.data
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
    // A new page is a new set of rows; keeping old expansions would open unrelated servers.
    expanded.value = new Set()
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load servers.'
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
watch(page, load)

async function toggle(row: AdminServer) {
  const open = new Set(expanded.value)

  if (open.has(row.id)) {
    open.delete(row.id)
    expanded.value = open
    return
  }

  open.add(row.id)
  expanded.value = open

  // Cached from a previous expand — channels don't change often enough to refetch on
  // every open, and the delete/rename handlers keep the cache honest.
  if (channels.value[row.id]) return

  loadingChannels.value = new Set(loadingChannels.value).add(row.id)
  try {
    const detail = await fetchServer(row.id)
    channels.value = { ...channels.value, [row.id]: detail.channels ?? [] }
  }
  finally {
    const busy = new Set(loadingChannels.value)
    busy.delete(row.id)
    loadingChannels.value = busy
  }
}

// ── Edit a server ────────────────────────────────────────────────────────────────────────

const editing = ref<AdminServer | null>(null)
const editForm = reactive({ name: '', owner_id: '' })
const editBusy = ref(false)
const editError = ref('')

function openEdit(row: AdminServer) {
  editing.value = row
  editForm.name = row.name
  editForm.owner_id = String(row.owner?.id ?? '')
  editError.value = ''
}

async function saveEdit() {
  if (!editing.value) return
  editBusy.value = true
  editError.value = ''
  try {
    const body: Record<string, unknown> = { name: editForm.name }
    const ownerId = Number(editForm.owner_id)
    if (ownerId && ownerId !== editing.value.owner?.id) body.owner_id = ownerId

    const updated = await updateServer(editing.value.id, body)
    const index = rows.value.findIndex(r => r.id === updated.id)
    if (index !== -1) rows.value[index] = { ...rows.value[index], ...updated }
    editing.value = null
  }
  catch (e: any) {
    editError.value = e?.data?.message ?? 'Could not save that server.'
  }
  finally {
    editBusy.value = false
  }
}

// ── Rename a channel ─────────────────────────────────────────────────────────────────────

const renaming = ref<{ channel: AdminChannel, serverId: number } | null>(null)
const renameTo = ref('')
const renameBusy = ref(false)

async function saveRename() {
  if (!renaming.value) return
  renameBusy.value = true
  try {
    const { channel, serverId } = renaming.value
    await updateChannel(channel.id, { name: renameTo.value })
    const list = channels.value[serverId] ?? []
    channels.value = {
      ...channels.value,
      [serverId]: list.map(c => c.id === channel.id ? { ...c, name: renameTo.value } : c),
    }
    renaming.value = null
  }
  finally {
    renameBusy.value = false
  }
}

// ── Deletes ──────────────────────────────────────────────────────────────────────────────

const deletingServer = ref<AdminServer | null>(null)
const deletingChannel = ref<{ channel: AdminChannel, serverId: number } | null>(null)
const deleteBusy = ref(false)
const deleteError = ref('')

async function confirmDeleteServer() {
  if (!deletingServer.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await deleteServer(deletingServer.value.id)
    rows.value = rows.value.filter(r => r.id !== deletingServer.value!.id)
    total.value = Math.max(0, total.value - 1)
    deletingServer.value = null
  }
  catch (e: any) {
    deleteError.value = e?.data?.message ?? 'Could not delete that server.'
  }
  finally {
    deleteBusy.value = false
  }
}

async function confirmDeleteChannel() {
  if (!deletingChannel.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    const { channel, serverId } = deletingChannel.value
    await deleteChannel(channel.id)
    channels.value = {
      ...channels.value,
      [serverId]: (channels.value[serverId] ?? []).filter(c => c.id !== channel.id),
    }
    const row = rows.value.find(r => r.id === serverId)
    if (row?.channels_count) row.channels_count -= 1
    deletingChannel.value = null
  }
  catch (e: any) {
    deleteError.value = e?.data?.message ?? 'Could not delete that channel.'
  }
  finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="p-6">
    <AdminHeader title="Servers" subtitle="Every server on the instance. Expand one to manage its channels.">
      <template #actions>
        <div class="relative">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="search" placeholder="Search servers" class="h-9 w-64 pl-9" />
        </div>
      </template>
    </AdminHeader>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="h-4 w-4 animate-spin" /> Loading…
    </div>

    <p v-else-if="!rows.length" class="text-sm text-muted-foreground">No servers match that.</p>

    <ul v-else class="divide-y rounded-lg border bg-card">
      <li v-for="row in rows" :key="row.id">
        <div class="flex flex-wrap items-center gap-3 p-3">
          <button class="text-muted-foreground hover:text-foreground" :aria-label="expanded.has(row.id) ? 'Collapse' : 'Expand'" @click="toggle(row)">
            <component :is="expanded.has(row.id) ? ChevronDown : ChevronRight" class="h-4 w-4" />
          </button>

          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ row.name }}</p>
            <p class="truncate text-xs text-muted-foreground">
              Owner: {{ row.owner?.name ?? 'nobody' }}
              <span v-if="row.owner"> · {{ row.owner.email }}</span>
            </p>
          </div>

          <div class="flex shrink-0 gap-4 text-xs text-muted-foreground tabular-nums">
            <span>{{ row.members_count ?? 0 }} members</span>
            <span>{{ row.channels_count ?? 0 }} channels</span>
          </div>

          <div class="flex shrink-0 items-center gap-1.5">
            <NuxtLink :to="`/admin/audit?server_id=${row.id}`">
              <Button variant="ghost" size="sm"><ScrollText class="h-3.5 w-3.5" /> Messages</Button>
            </NuxtLink>
            <Button variant="ghost" size="sm" @click="openEdit(row)"><Pencil class="h-3.5 w-3.5" /> Edit</Button>
            <Button variant="ghost" size="sm" class="text-destructive" @click="deletingServer = row">
              <Trash2 class="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>

        <!-- Channels -->
        <div v-if="expanded.has(row.id)" class="border-t bg-muted/20 px-3 py-2 pl-12">
          <div v-if="loadingChannels.has(row.id)" class="flex items-center gap-2 py-2 text-xs text-muted-foreground">
            <LoaderCircle class="h-3.5 w-3.5 animate-spin" /> Loading channels…
          </div>

          <p v-else-if="!channels[row.id]?.length" class="py-2 text-xs text-muted-foreground">
            This server has no channels.
          </p>

          <ul v-else class="divide-y divide-border/50">
            <li
              v-for="channel in channels[row.id]"
              :key="channel.id"
              class="flex flex-wrap items-center gap-2 py-2"
              :class="channel.parent_id ? 'pl-6' : ''"
            >
              <component :is="channelIcon(channel.type)" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              <span class="text-sm">{{ channel.name }}</span>
              <!-- A discussion is a channel with a parent, so it's indented rather than
                   listed separately — see DISCUSSIONS.md. -->
              <span v-if="channel.parent_id" class="text-[11px] text-muted-foreground">discussion</span>
              <Lock v-if="channel.is_private" class="h-3 w-3 text-muted-foreground" aria-label="Private" />
              <span v-if="channel.encrypted" class="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">E2EE</span>

              <span class="ml-auto text-xs tabular-nums text-muted-foreground">
                {{ (channel.messages_count ?? 0).toLocaleString() }} messages
              </span>

              <NuxtLink :to="`/admin/audit?channel_id=${channel.id}`">
                <Button variant="ghost" size="sm"><ScrollText class="h-3.5 w-3.5" /></Button>
              </NuxtLink>
              <Button variant="ghost" size="sm" @click="renaming = { channel, serverId: row.id }; renameTo = channel.name">
                <Pencil class="h-3.5 w-3.5" />
              </Button>
              <Button variant="ghost" size="sm" class="text-destructive" @click="deletingChannel = { channel, serverId: row.id }">
                <Trash2 class="h-3.5 w-3.5" />
              </Button>
            </li>
          </ul>
        </div>
      </li>
    </ul>

    <AdminPager v-model:page="page" :last-page="lastPage" :total="total" :busy="loading" />

    <!-- Edit server -->
    <Dialog :open="!!editing" @update:open="v => { if (!v) editing = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit {{ editing?.name }}</DialogTitle>
          <DialogDescription>Rename the server, or hand it to a different owner.</DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <div class="space-y-2">
            <Label for="server-name">Name</Label>
            <Input id="server-name" v-model="editForm.name" />
          </div>
          <div class="space-y-2">
            <Label for="server-owner">Owner (user ID)</Label>
            <Input id="server-owner" v-model="editForm.owner_id" inputmode="numeric" />
            <p class="text-xs text-muted-foreground">
              The only place ownership can move — nobody can hand a server over from inside the app.
            </p>
          </div>
          <p v-if="editError" class="text-sm text-destructive">{{ editError }}</p>
        </div>

        <DialogFooter>
          <Button variant="ghost" :disabled="editBusy" @click="editing = null">Cancel</Button>
          <Button :disabled="editBusy" @click="saveEdit">{{ editBusy ? 'Saving…' : 'Save changes' }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Rename channel -->
    <Dialog :open="!!renaming" @update:open="v => { if (!v) renaming = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Rename channel</DialogTitle>
        </DialogHeader>
        <Input v-model="renameTo" />
        <DialogFooter>
          <Button variant="ghost" :disabled="renameBusy" @click="renaming = null">Cancel</Button>
          <Button :disabled="renameBusy || !renameTo.trim()" @click="saveRename">
            {{ renameBusy ? 'Saving…' : 'Rename' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="!!deletingServer"
      title="Delete this server?"
      :description="`${deletingServer?.name} and all ${deletingServer?.channels_count ?? 0} of its channels — every message, file and thread inside — will be permanently removed for all ${deletingServer?.members_count ?? 0} members.`"
      confirm-label="Delete server"
      busy-label="Deleting…"
      :busy="deleteBusy"
      :error="deleteError"
      @update:open="v => { if (!v) deletingServer = null }"
      @confirm="confirmDeleteServer"
    />

    <ConfirmDialog
      :open="!!deletingChannel"
      title="Delete this channel?"
      :description="`#${deletingChannel?.channel.name} and its ${(deletingChannel?.channel.messages_count ?? 0).toLocaleString()} messages will be permanently removed.`"
      confirm-label="Delete channel"
      busy-label="Deleting…"
      :busy="deleteBusy"
      :error="deleteError"
      @update:open="v => { if (!v) deletingChannel = null }"
      @confirm="confirmDeleteChannel"
    />
  </div>
</template>
