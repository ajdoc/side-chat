<script setup lang="ts">
import { Check, Pencil, Trash2, X } from 'lucide-vue-next'
import type { SignupPerson } from '~/types'

/**
 * Every name that has signed up in this channel. Sheets suggest from this list, so fixing a
 * spelling here fixes it on every sheet the name appears on.
 */
const props = defineProps<{
  signups: ReturnType<typeof useSignups>
  canManage: boolean
}>()

const { roster } = props.signups
const loaded = ref(false)
const search = ref('')
const error = ref('')

onMounted(async () => {
  await props.signups.loadRoster()
  loaded.value = true
})

const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q ? roster.value.filter(p => p.name.toLowerCase().includes(q)) : roster.value
})

const editingId = ref<number | null>(null)
const draft = ref('')

function startRename(p: SignupPerson) {
  editingId.value = p.id
  draft.value = p.name
  error.value = ''
}

async function saveRename() {
  if (editingId.value == null || !draft.value.trim()) return
  try {
    await props.signups.renamePerson(editingId.value, draft.value.trim())
    editingId.value = null
  }
  catch (e: any) {
    error.value = e?.data?.errors?.name?.[0] ?? e?.data?.message ?? 'Could not rename.'
  }
}

async function remove(p: SignupPerson) {
  error.value = ''
  try {
    await props.signups.deletePerson(p.id)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not remove.'
  }
}

function when(iso: string | null) {
  return iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—'
}
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto p-4">
    <div class="mb-4 flex items-center gap-2">
      <input v-model="search" placeholder="Find a name" class="h-8 w-56 rounded-md border bg-background px-2 text-sm">
      <span class="text-xs text-muted-foreground">{{ roster.length }} {{ roster.length === 1 ? 'name' : 'names' }}</span>
    </div>

    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

    <p v-if="!loaded" class="text-sm text-muted-foreground">Loading…</p>
    <p v-else-if="!roster.length" class="rounded-xl border border-dashed px-3 py-10 text-center text-sm text-muted-foreground">
      No names yet. Names are added the first time someone signs up on a sheet.
    </p>

    <ul v-else class="divide-y rounded-lg border">
      <li v-for="p in shown" :key="p.id" class="flex items-center gap-3 px-3 py-2 text-sm">
        <form v-if="editingId === p.id" class="flex flex-1 items-center gap-2" @submit.prevent="saveRename">
          <input v-model="draft" class="h-8 flex-1 rounded-md border bg-background px-2 text-sm" aria-label="Name" @keydown.esc="editingId = null">
          <button type="submit" class="grid h-8 w-8 place-items-center rounded border hover:bg-muted" title="Save"><Check class="h-3.5 w-3.5" /></button>
          <button type="button" class="grid h-8 w-8 place-items-center rounded border hover:bg-muted" title="Cancel" @click="editingId = null"><X class="h-3.5 w-3.5" /></button>
        </form>
        <template v-else>
          <span class="min-w-0 flex-1 truncate font-medium">{{ p.name }}</span>
          <span class="w-24 text-right text-xs tabular-nums text-muted-foreground">{{ p.signups }} sign-up{{ p.signups === 1 ? '' : 's' }}</span>
          <span class="hidden w-28 text-right text-xs text-muted-foreground sm:inline">{{ when(p.last_signed_up_at) }}</span>
          <template v-if="canManage">
            <button type="button" class="grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground" title="Rename" @click="startRename(p)">
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <button
              type="button"
              class="grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-destructive disabled:invisible"
              :disabled="p.signups > 0"
              title="Remove from roster"
              @click="remove(p)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </template>
        </template>
      </li>
    </ul>
  </div>
</template>
