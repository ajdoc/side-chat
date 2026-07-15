<script setup lang="ts">
import { Check, Loader2, UserPlus, X } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'

definePageMeta({ middleware: 'auth', layout: 'app' })

const route = useRoute()
const { server } = useServer()
// Loaded and kept live (Reverb) by openServer() as soon as the server is opened.
const { requests, loading, approve, decline } = useJoinRequests()

const serverId = computed(() => Number(route.params.serverId))
const selected = ref<number[]>([])
const working = ref(false)

const allSelected = computed(() => requests.value.length > 0 && selected.value.length === requests.value.length)

function toggleAll(checked: boolean) {
  selected.value = checked ? requests.value.map(r => r.id) : []
}
function toggle(id: number, checked: boolean) {
  selected.value = checked ? [...selected.value, id] : selected.value.filter(x => x !== id)
}
function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

async function run(action: 'approve' | 'decline', ids: number[]) {
  if (!ids.length || working.value) return
  working.value = true
  try {
    if (action === 'approve') await approve(serverId.value, ids)
    else await decline(serverId.value, ids)
    selected.value = selected.value.filter(id => !ids.includes(id))
  } finally {
    working.value = false
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-4 font-semibold">
      <UserPlus class="h-5 w-5 text-muted-foreground" />
      Pending requests
      <span v-if="requests.length" class="rounded-full bg-primary px-2 py-0.5 text-xs text-primary-foreground">
        {{ requests.length }}
      </span>
    </header>

    <!-- Bulk actions -->
    <div v-if="requests.length" class="flex shrink-0 items-center gap-3 border-b px-4 py-2">
      <Checkbox :model-value="allSelected" @update:model-value="toggleAll" />
      <span class="text-sm text-muted-foreground">
        {{ selected.length ? `${selected.length} selected` : 'Select all' }}
      </span>
      <div class="ml-auto flex gap-2">
        <Button size="sm" :disabled="!selected.length || working" @click="run('approve', selected)">
          <Check class="mr-1 h-4 w-4" /> Approve
        </Button>
        <Button
          size="sm"
          variant="outline"
          class="text-destructive hover:text-destructive"
          :disabled="!selected.length || working"
          @click="run('decline', selected)"
        >
          <X class="mr-1 h-4 w-4" /> Decline
        </Button>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto p-4">
      <div v-if="loading && !requests.length" class="flex justify-center py-10">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <p v-else-if="!requests.length" class="py-10 text-center text-sm text-muted-foreground">
        No one is waiting to join <span class="font-medium">{{ server?.name }}</span> right now.
      </p>

      <ul v-else class="space-y-2">
        <li
          v-for="r in requests"
          :key="r.id"
          class="flex items-center gap-3 rounded-lg border p-3"
        >
          <Checkbox :model-value="selected.includes(r.id)" @update:model-value="(v: boolean) => toggle(r.id, v)" />

          <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
            {{ initials(r.user.name) }}
          </div>

          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ r.user.name }}</p>
            <p class="truncate text-xs text-muted-foreground">
              {{ r.user.email }} · requested {{ formatDate(r.created_at) }}
            </p>
          </div>

          <Button size="sm" :disabled="working" @click="run('approve', [r.id])">
            <Check class="mr-1 h-4 w-4" /> Approve
          </Button>
          <Button
            size="sm"
            variant="outline"
            class="text-destructive hover:text-destructive"
            :disabled="working"
            @click="run('decline', [r.id])"
          >
            <X class="mr-1 h-4 w-4" /> Decline
          </Button>
        </li>
      </ul>
    </div>
  </div>
</template>
