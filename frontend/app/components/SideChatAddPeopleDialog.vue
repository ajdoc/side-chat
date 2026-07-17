<script setup lang="ts">
import { Check, Loader2 } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * Add other channel members to a side chat's roster. Candidates are the channel's members
 * minus whoever's already in — you can only bring in people who can already be here, which
 * the backend also enforces.
 */
const props = defineProps<{
  sideChatId: number
  channelId: number
  /** Who's already on the roster — filtered out of the candidate list. */
  existingIds: number[]
}>()

const open = defineModel<boolean>('open', { default: false })

const { members, load } = useChannelMembers()
const { addParticipants } = useSideChats()

const query = ref('')
const selected = ref<Set<number>>(new Set())
const adding = ref(false)

const candidates = computed(() => {
  const existing = new Set(props.existingIds)
  const q = query.value.trim().toLowerCase()
  return members.value
    .filter(m => !existing.has(m.id))
    .filter(m => !q || m.name.toLowerCase().includes(q))
})

watch(open, (isOpen) => {
  if (isOpen) {
    query.value = ''
    selected.value = new Set()
    load(props.channelId)
  }
}, { immediate: true })

function toggle(id: number) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}

async function submit() {
  if (!selected.value.size || adding.value) return
  adding.value = true
  try {
    // The refreshed roster arrives over the stream (SideChatActivity); just close.
    await addParticipants(props.sideChatId, [...selected.value])
    open.value = false
  } finally {
    adding.value = false
  }
}

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Add people</DialogTitle>
        <DialogDescription>Bring other channel members into this side chat.</DialogDescription>
      </DialogHeader>

      <Input v-model="query" placeholder="Search members…" autofocus />

      <div class="max-h-72 space-y-0.5 overflow-y-auto">
        <p v-if="!candidates.length" class="py-4 text-center text-sm text-muted-foreground">
          {{ members.length ? 'Everyone here is already in.' : 'No members to add.' }}
        </p>
        <button
          v-for="m in candidates"
          :key="m.id"
          type="button"
          class="flex w-full items-center gap-2 rounded p-1.5 text-left hover:bg-muted"
          :aria-pressed="selected.has(m.id)"
          @click="toggle(m.id)"
        >
          <span class="grid h-7 w-7 shrink-0 place-items-center overflow-hidden rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
            <img v-if="m.avatar" :src="m.avatar" :alt="m.name" class="h-full w-full object-cover">
            <span v-else>{{ initials(m.name) }}</span>
          </span>
          <span class="min-w-0 flex-1 truncate text-sm">{{ m.name }}</span>
          <span
            class="grid h-5 w-5 shrink-0 place-items-center rounded-full border"
            :class="selected.has(m.id) ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'"
          >
            <Check v-if="selected.has(m.id)" class="h-3.5 w-3.5" />
          </span>
        </button>
      </div>

      <div class="flex items-center justify-between">
        <span class="text-xs text-muted-foreground">{{ selected.size }} selected</span>
        <Button size="sm" :disabled="!selected.size || adding" @click="submit">
          <Loader2 v-if="adding" class="mr-1 h-3.5 w-3.5 animate-spin" />
          Add {{ selected.size || '' }}
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
