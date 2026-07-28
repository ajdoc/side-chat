<script setup lang="ts">
import { Loader2, X } from 'lucide-vue-next'
import type { ChannelMember, Server, ServerRole } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * Who runs this server. Owner-only, and deliberately the smallest possible surface: one
 * toggle per member between "member" and "admin".
 *
 * An admin can do everything the owner can bar two things — delete the server, and open
 * this dialog. That means approving and declining join requests, adding, renaming and
 * restricting channels, handing out Side Space rooms, setting the call's entrance and exit
 * effects, muting in a call, and renaming people. The list under the toggles says as much,
 * because "admin" on its own doesn't tell anybody what they're handing over.
 *
 * The roster comes from the channel members endpoint (which reports each person's role) —
 * a server has no separate member list of its own, and one of its channels is always the
 * same set of people.
 */
const props = defineProps<{ server: Server, channelId: number | null }>()
const emit = defineEmits<{ close: [] }>()

const api = useApi()
const { members, load: loadMembers } = useChannelMembers()

const roles = ref<Record<number, ServerRole>>({})
const loading = ref(true)
const savingId = ref<number | null>(null)
const error = ref<string | null>(null)

onMounted(async () => {
  if (props.channelId == null) { loading.value = false; error.value = 'Open a channel in this server first.'; return }
  // Forced: this dialog is the thing that changes roles, so a cached roster would show
  // the last edit undone.
  await loadMembers(props.channelId, true)
  roles.value = Object.fromEntries(members.value.map(m => [m.id, m.role ?? 'member']))
  loading.value = false
})

/** The owner isn't a role you can set — their standing is the server's `owner_id`. */
const assignable = computed(() => members.value.filter(m => m.id !== props.server.owner_id))
const owner = computed(() => members.value.find(m => m.id === props.server.owner_id) ?? null)

async function setRole(member: ChannelMember, role: ServerRole) {
  if (savingId.value !== null) return
  savingId.value = member.id
  error.value = null
  const previous = roles.value[member.id] ?? 'member'
  roles.value = { ...roles.value, [member.id]: role } // optimistic: the toggle should move now
  try {
    await api(`/api/servers/${props.server.id}/members/${member.id}/role`, {
      method: 'PATCH',
      body: { role },
    })
  } catch {
    roles.value = { ...roles.value, [member.id]: previous }
    error.value = `Couldn't change ${member.name}'s role.`
  } finally {
    savingId.value = null
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[80vh] w-full max-w-md flex-col rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-1 flex items-center justify-between">
        <h2 class="font-semibold">Roles · {{ server.name }}</h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>
      <p class="mb-3 text-xs text-muted-foreground">
        Admins can do everything you can except delete the server and change these roles —
        approving join requests, managing channels and their access, Side Space rooms, call
        effects and nicknames.
      </p>

      <div v-if="loading" class="flex justify-center py-8">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <div v-else class="min-h-0 flex-1 overflow-y-auto rounded-lg border">
        <div v-if="owner" class="flex items-center gap-2 border-b bg-muted/40 px-3 py-2 text-sm">
          <span class="min-w-0 flex-1 truncate">{{ owner.name }}</span>
          <span class="rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-semibold uppercase text-primary">Owner</span>
        </div>

        <div
          v-for="m in assignable"
          :key="m.id"
          class="flex items-center gap-2 px-3 py-2 text-sm"
        >
          <span class="min-w-0 flex-1 truncate">{{ m.name }}</span>
          <span v-if="savingId === m.id"><Loader2 class="h-3.5 w-3.5 animate-spin text-muted-foreground" /></span>
          <Button
            size="sm"
            :variant="roles[m.id] === 'admin' ? 'secondary' : 'ghost'"
            :disabled="savingId !== null"
            @click="setRole(m, roles[m.id] === 'admin' ? 'member' : 'admin')"
          >
            {{ roles[m.id] === 'admin' ? 'Admin' : 'Member' }}
          </Button>
        </div>

        <p v-if="!assignable.length" class="px-3 py-3 text-sm text-muted-foreground">
          Nobody else has joined this server yet.
        </p>
      </div>

      <p v-if="error" class="mt-2 text-xs text-destructive">{{ error }}</p>

      <div class="mt-4 flex justify-end">
        <Button variant="ghost" @click="emit('close')">Done</Button>
      </div>
    </div>
  </div>
</template>
