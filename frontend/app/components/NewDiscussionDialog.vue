<script setup lang="ts">
import type { Channel } from '~/types'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'

/**
 * Starting a new conversation inside a channel.
 *
 * Its own component because there are two doors to it — the sidebar's channel row and the
 * header's discussion picker — and a dialog written twice is a dialog that diverges. Both hand
 * it the container; everything else it works out.
 */
const props = defineProps<{ parent: Channel | null }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ created: [Channel] }>()

const { create } = useDiscussions()

const name = ref('')
const copyFrom = ref<number | null>(null)
const working = ref(false)
const error = ref('')

/**
 * A Side Space discussion is a room, and a room has to be built. Rather than drop people into
 * an empty grid, a new one starts as a copy of a sibling — General unless they pick another.
 * Only offered when there's a choice to make; with one sibling there is nothing to ask.
 */
const isSpace = computed(() => props.parent?.type === 'space')
const siblings = computed(() => props.parent?.discussions ?? [])
const offerCopy = computed(() => isSpace.value && siblings.value.length > 1)

watch(open, (isOpen) => {
  if (!isOpen) return
  name.value = ''
  copyFrom.value = siblings.value[0]?.id ?? null
  error.value = ''
})

async function submit() {
  const trimmed = name.value.trim()
  if (!trimmed || !props.parent || working.value) return

  working.value = true
  error.value = ''
  try {
    const created = await create(props.parent, trimmed, isSpace.value ? copyFrom.value : null)
    open.value = false
    emit('created', created)
  } catch (e: any) {
    // Stays open so the message has somewhere to be read — "only staff may add discussions
    // here" is the whole reason this isn't fire-and-forget.
    error.value = e?.data?.message ?? 'Could not start the discussion.'
  } finally {
    working.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New discussion</DialogTitle>
        <DialogDescription>
          A separate conversation in {{ parent?.name }}, with its own messages, side chats,
          threads and Side Desk.
        </DialogDescription>
      </DialogHeader>
      <form class="space-y-3" @submit.prevent="submit">
        <Input v-model="name" placeholder="Discussion name" maxlength="100" autofocus />

        <label v-if="offerCopy" class="block space-y-1">
          <span class="text-sm text-muted-foreground">Start from the room in</span>
          <select
            v-model="copyFrom"
            class="h-9 w-full rounded-md border bg-background px-2 text-sm"
          >
            <option v-for="s in siblings" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" :disabled="working" @click="open = false">
            Cancel
          </Button>
          <Button type="submit" :disabled="working || !name.trim()">
            {{ working ? 'Starting…' : 'Start discussion' }}
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>
</template>
