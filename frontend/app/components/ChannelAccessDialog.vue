<script setup lang="ts">
import { Loader2, X } from 'lucide-vue-next'
import type { Channel, ChannelMember } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * Who may be in a channel — the server staff's setting.
 *
 * Two states, not a permission matrix. **Open** is the default and means what it has always
 * meant: everyone in the server is in this channel. **Restricted** means an allow-list, and
 * anyone not on it doesn't merely lose the door — the channel stops appearing in their
 * sidebar at all (a locked door still tells you the room exists, which rather defeats it).
 *
 * The owner and admins are never listed: they're allowed in by rule, so storing them would
 * leave a list that silently goes wrong the moment somebody is promoted. The note in the
 * footer says so, because an admin missing from a list they can plainly still read looks
 * like a bug otherwise.
 */
const props = defineProps<{ channel: Channel }>()
const emit = defineEmits<{ close: [], saved: [Channel] }>()

const api = useApi()
const { patchChannel } = useServer()
const { members, load: loadMembers } = useChannelMembers()

const isPrivate = ref(false)
const selected = ref<number[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)

// Staff are in by rule, so they're shown as such rather than as checkboxes you could
// uncheck to no effect.
const staff = computed(() => members.value.filter(m => m.role === 'owner' || m.role === 'admin'))
const selectable = computed(() => members.value.filter(m => m.role !== 'owner' && m.role !== 'admin'))

onMounted(async () => {
  try {
    // The allow-list is staff-only to read, so it comes from its own endpoint rather than
    // riding along on the sidebar's channel list where every member would see it.
    const [access] = await Promise.all([
      api<{ data: Channel }>(`/api/channels/${props.channel.id}/access`),
      loadMembers(props.channel.id),
    ])
    isPrivate.value = !!access.data.is_private
    selected.value = [...(access.data.member_ids ?? [])]
  } catch {
    error.value = "Couldn't load this channel's access settings."
  } finally {
    loading.value = false
  }
})

function toggle(member: ChannelMember) {
  selected.value = selected.value.includes(member.id)
    ? selected.value.filter(id => id !== member.id)
    : [...selected.value, member.id]
}

async function save() {
  if (saving.value) return
  saving.value = true
  error.value = null
  try {
    const res = await api<{ data: Channel }>(`/api/channels/${props.channel.id}/access`, {
      method: 'PUT',
      body: { is_private: isPrivate.value, member_ids: isPrivate.value ? selected.value : [] },
    })
    // The broadcast tells everyone else to refetch; patch our own sidebar row now so the
    // lock icon doesn't wait a round trip.
    patchChannel(props.channel.id, { is_private: res.data.is_private })
    emit('saved', res.data)
    emit('close')
  } catch {
    error.value = "Couldn't save those changes."
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[80vh] w-full max-w-md flex-col rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-semibold">Access · #{{ channel.name }}</h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <div v-if="loading" class="flex justify-center py-8">
        <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
      </div>

      <template v-else>
        <div class="space-y-2">
          <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5" :class="!isPrivate && 'border-primary bg-primary/5'">
            <input type="radio" :checked="!isPrivate" class="mt-1 accent-primary" @change="isPrivate = false">
            <span class="text-sm">
              <span class="font-medium">Open to everyone</span>
              <span class="block text-xs text-muted-foreground">Every member of the server can see and use this channel.</span>
            </span>
          </label>
          <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5" :class="isPrivate && 'border-primary bg-primary/5'">
            <input type="radio" :checked="isPrivate" class="mt-1 accent-primary" @change="isPrivate = true">
            <span class="text-sm">
              <span class="font-medium">Only chosen members</span>
              <span class="block text-xs text-muted-foreground">Everyone else stops seeing this channel entirely.</span>
            </span>
          </label>
        </div>

        <div v-if="isPrivate" class="mt-3 min-h-0 flex-1 overflow-y-auto rounded-lg border">
          <p v-if="staff.length" class="border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            Always in:
            {{ staff.map(m => m.name).join(', ') }}
          </p>
          <label
            v-for="m in selectable"
            :key="m.id"
            class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-muted"
          >
            <input type="checkbox" class="accent-primary" :checked="selected.includes(m.id)" @change="toggle(m)">
            <span class="truncate">{{ m.name }}</span>
          </label>
          <p v-if="!selectable.length" class="px-3 py-3 text-sm text-muted-foreground">
            Everybody else in this server already runs it.
          </p>
        </div>

        <p v-if="error" class="mt-2 text-xs text-destructive">{{ error }}</p>

        <div class="mt-4 flex justify-end gap-2">
          <Button variant="ghost" @click="emit('close')">Cancel</Button>
          <Button :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
        </div>
      </template>
    </div>
  </div>
</template>
