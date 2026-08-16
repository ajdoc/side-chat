<script setup lang="ts">
import { Ban, Bot, CircleCheck, LoaderCircle, Pencil, Search, ShieldCheck, Trash2 } from 'lucide-vue-next'
import type { AdminUser, SiteRole } from '~/types'
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
 * Accounts.
 *
 * Four things you can do to a row, and they're kept visibly distinct because they are not
 * degrees of the same thing:
 *
 * - **Edit** — name, address, a new password for somebody locked out of theirs.
 * - **Role** — Super Admin, or nothing. This is where site roles are managed; a screen of
 *   its own would be a list of two values and the same table of people, reached one click
 *   further away.
 * - **Block** — the moderation tool. Reversible, and it *requires* a reason, because that
 *   reason is the entire message the person reads when they try to sign in. That's the whole
 *   point of the field: "you are blocked" with no sentence after it is every support ticket.
 * - **Delete** — not moderation. It cascades through everything they own, including servers
 *   full of other people's messages, so the confirmation says so in those words.
 *
 * The last two are refused by the server for your own account and for other admins. The UI
 * hides the buttons in those cases rather than letting you find out by being told no.
 */
definePageMeta({ middleware: 'admin', layout: 'admin' })

const route = useRoute()
const router = useRouter()
const { users, updateUser, setRole, banUser, unbanUser, deleteUser } = useAdmin()
const { user: me } = useAuth()

type Filter = '' | 'banned' | 'admins' | 'bots'

const rows = ref<AdminUser[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')

// Seeded from the URL so the overview's tiles can deep-link into a filtered list.
const search = ref(typeof route.query.q === 'string' ? route.query.q : '')
const filter = ref<Filter>((route.query.filter as Filter) ?? '')

const tabs: { key: Filter, label: string }[] = [
  { key: '', label: 'Everyone' },
  { key: 'admins', label: 'Admins' },
  { key: 'banned', label: 'Blocked' },
  { key: 'bots', label: 'Bots' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await users({ q: search.value, filter: filter.value, page: page.value })
    rows.value = res.data
    lastPage.value = res.meta.last_page
    total.value = res.meta.total
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load users.'
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

// Typing shouldn't fire a request per keystroke, and a new search always restarts at page 1.
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    syncUrl()
    load()
  }, 300)
})

watch(filter, () => {
  page.value = 1
  syncUrl()
  load()
})

watch(page, load)

function syncUrl() {
  router.replace({ query: { ...(search.value ? { q: search.value } : {}), ...(filter.value ? { filter: filter.value } : {}) } })
}

/** Replace one row in place — every mutation returns the updated account. */
function patchRow(updated: AdminUser) {
  const index = rows.value.findIndex(row => row.id === updated.id)
  if (index !== -1) rows.value[index] = updated
}

/** Neither of the destructive actions is offered on yourself or on a fellow admin. */
const isProtected = (row: AdminUser) => row.id === me.value?.id || row.role === 'super_admin'

// ── Edit ─────────────────────────────────────────────────────────────────────────────────

const editing = ref<AdminUser | null>(null)
const editForm = reactive({ name: '', email: '', password: '' })
const editBusy = ref(false)
const editError = ref('')

function openEdit(row: AdminUser) {
  editing.value = row
  editForm.name = row.name
  editForm.email = row.email
  editForm.password = ''
  editError.value = ''
}

async function saveEdit() {
  if (!editing.value) return
  editBusy.value = true
  editError.value = ''
  try {
    const body: Record<string, string> = { name: editForm.name, email: editForm.email }
    // Only sent when actually typed — an empty box means "leave their password alone".
    if (editForm.password) body.password = editForm.password
    patchRow(await updateUser(editing.value.id, body))
    editing.value = null
  }
  catch (e: any) {
    editError.value = e?.data?.message ?? 'Could not save those changes.'
  }
  finally {
    editBusy.value = false
  }
}

// ── Block ────────────────────────────────────────────────────────────────────────────────

const banning = ref<AdminUser | null>(null)
const banReason = ref('')
const banBusy = ref(false)
const banError = ref('')

function openBan(row: AdminUser) {
  banning.value = row
  banReason.value = ''
  banError.value = ''
}

async function confirmBan() {
  if (!banning.value) return
  banBusy.value = true
  banError.value = ''
  try {
    patchRow(await banUser(banning.value.id, banReason.value))
    banning.value = null
  }
  catch (e: any) {
    banError.value = e?.data?.message ?? 'Could not block this account.'
  }
  finally {
    banBusy.value = false
  }
}

const busyRow = ref<number | null>(null)

async function lift(row: AdminUser) {
  busyRow.value = row.id
  try {
    patchRow(await unbanUser(row.id))
  }
  finally {
    busyRow.value = null
  }
}

async function changeRole(row: AdminUser, role: SiteRole | null) {
  busyRow.value = row.id
  error.value = ''
  try {
    patchRow(await setRole(row.id, role))
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not change that role.'
  }
  finally {
    busyRow.value = null
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────────────────

const deleting = ref<AdminUser | null>(null)
const deleteBusy = ref(false)
const deleteError = ref('')

async function confirmDelete() {
  if (!deleting.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await deleteUser(deleting.value.id)
    rows.value = rows.value.filter(row => row.id !== deleting.value!.id)
    total.value = Math.max(0, total.value - 1)
    deleting.value = null
  }
  catch (e: any) {
    deleteError.value = e?.data?.message ?? 'Could not delete this account.'
  }
  finally {
    deleteBusy.value = false
  }
}

const deleteWarning = computed(() => {
  const owned = deleting.value?.owned_servers_count ?? 0
  const base = `${deleting.value?.name} and everything they posted will be removed. This cannot be undone.`
  return owned > 0
    ? `${base} They own ${owned} server${owned === 1 ? '' : 's'}, which will be deleted too — including everyone else's messages in them.`
    : base
})
</script>

<template>
  <div class="p-6">
    <AdminHeader title="Users" subtitle="Edit accounts, hand out roles, block people from the site, or remove them.">
      <template #actions>
        <div class="relative">
          <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="search" placeholder="Search name or email" class="h-9 w-64 pl-9" />
        </div>
      </template>
    </AdminHeader>

    <div class="mb-4 flex gap-1">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="rounded-md px-3 py-1.5 text-sm transition-colors"
        :class="filter === tab.key ? 'bg-primary/10 font-medium text-primary' : 'text-muted-foreground hover:bg-muted'"
        @click="filter = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="h-4 w-4 animate-spin" /> Loading…
    </div>

    <p v-else-if="!rows.length" class="text-sm text-muted-foreground">No accounts match that.</p>

    <div v-else class="overflow-x-auto rounded-lg border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th class="p-3 font-medium">Account</th>
            <th class="p-3 font-medium">Standing</th>
            <th class="p-3 font-medium">Servers</th>
            <th class="p-3 font-medium">Messages</th>
            <th class="p-3 text-right font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="row in rows" :key="row.id" class="align-middle" :class="row.banned ? 'bg-destructive/5' : ''">
            <td class="p-3">
              <div class="flex items-center gap-2">
                <span class="font-medium">{{ row.name }}</span>
                <Bot v-if="row.is_bot" class="h-3.5 w-3.5 text-muted-foreground" aria-label="Bot" />
                <ShieldCheck v-if="row.role === 'super_admin'" class="h-3.5 w-3.5 text-primary" aria-label="Super Admin" />
              </div>
              <p class="text-xs text-muted-foreground">{{ row.email }}</p>
            </td>

            <td class="p-3">
              <div v-if="row.banned">
                <span class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                  <Ban class="h-3 w-3" /> Blocked
                </span>
                <!-- The reason is the message they read at the login screen, so it's shown
                     here verbatim rather than summarised. -->
                <p class="mt-1 max-w-xs text-xs text-muted-foreground" :title="row.ban_reason ?? ''">
                  “{{ row.ban_reason }}”
                </p>
              </div>
              <span v-else class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <CircleCheck class="h-3 w-3" /> Active
              </span>
            </td>

            <td class="p-3 tabular-nums text-muted-foreground">
              {{ row.servers_count ?? 0 }}
              <span v-if="row.owned_servers_count" class="text-xs">({{ row.owned_servers_count }} owned)</span>
            </td>
            <td class="p-3 tabular-nums text-muted-foreground">{{ (row.messages_count ?? 0).toLocaleString() }}</td>

            <td class="p-3">
              <div class="flex flex-wrap items-center justify-end gap-1.5">
                <LoaderCircle v-if="busyRow === row.id" class="h-4 w-4 animate-spin text-muted-foreground" />

                <Button variant="ghost" size="sm" @click="openEdit(row)">
                  <Pencil class="h-3.5 w-3.5" /> Edit
                </Button>

                <!-- Roles live on this row rather than on a screen of their own: the list of
                     roles is one long, and the people are already here. -->
                <Button
                  v-if="!row.is_bot && row.id !== me?.id"
                  variant="ghost"
                  size="sm"
                  :disabled="busyRow === row.id"
                  @click="changeRole(row, row.role === 'super_admin' ? null : 'super_admin')"
                >
                  <ShieldCheck class="h-3.5 w-3.5" />
                  {{ row.role === 'super_admin' ? 'Revoke admin' : 'Make admin' }}
                </Button>

                <Button v-if="row.banned" variant="outline" size="sm" :disabled="busyRow === row.id" @click="lift(row)">
                  Unblock
                </Button>
                <Button v-else-if="!isProtected(row)" variant="outline" size="sm" @click="openBan(row)">
                  <Ban class="h-3.5 w-3.5" /> Block
                </Button>

                <Button v-if="!isProtected(row)" variant="ghost" size="sm" class="text-destructive" @click="deleting = row">
                  <Trash2 class="h-3.5 w-3.5" />
                </Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AdminPager v-model:page="page" :last-page="lastPage" :total="total" :busy="loading" />

    <!-- Edit -->
    <Dialog :open="!!editing" @update:open="v => { if (!v) editing = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit {{ editing?.name }}</DialogTitle>
          <DialogDescription>Changes take effect immediately.</DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <div class="space-y-2">
            <Label for="edit-name">Display name</Label>
            <Input id="edit-name" v-model="editForm.name" />
          </div>
          <div class="space-y-2">
            <Label for="edit-email">Email</Label>
            <Input id="edit-email" v-model="editForm.email" type="email" />
          </div>
          <div class="space-y-2">
            <Label for="edit-password">New password</Label>
            <Input id="edit-password" v-model="editForm.password" type="password" placeholder="Leave blank to keep the current one" />
            <p class="text-xs text-muted-foreground">
              For somebody locked out of the address on their account. They aren't told this happened.
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

    <!-- Block, with the message they'll be shown -->
    <Dialog :open="!!banning" @update:open="v => { if (!v) banning = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Block {{ banning?.name }}</DialogTitle>
          <DialogDescription>
            They'll be signed out immediately and won't be able to sign in again until you unblock them.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-2">
          <Label for="ban-reason">Why they're blocked</Label>
          <textarea
            id="ban-reason"
            v-model="banReason"
            rows="3"
            maxlength="500"
            class="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            placeholder="e.g. Repeated harassment in #general. Contact support@example.com to appeal."
          />
          <p class="text-xs text-muted-foreground">
            This exact text is shown to them on the login screen — write it for them to read, not as an internal note.
          </p>
          <p v-if="banError" class="text-sm text-destructive">{{ banError }}</p>
        </div>

        <DialogFooter>
          <Button variant="ghost" :disabled="banBusy" @click="banning = null">Cancel</Button>
          <Button variant="destructive" :disabled="banBusy || banReason.trim().length < 4" @click="confirmBan">
            {{ banBusy ? 'Blocking…' : 'Block account' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <ConfirmDialog
      :open="!!deleting"
      title="Delete this account?"
      :description="deleteWarning"
      confirm-label="Delete account"
      busy-label="Deleting…"
      :busy="deleteBusy"
      :error="deleteError"
      @update:open="v => { if (!v) deleting = null }"
      @confirm="confirmDelete"
    />
  </div>
</template>
