<script setup lang="ts">
import type { User } from '~/types'
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
 * Renaming somebody in one server or chat — the UI half of useNicknames.
 *
 * Which of the two namings you're editing isn't a toggle the user is asked about; it
 * follows from who they picked, because only one of them is ever theirs to set:
 *
 *  - **Yourself** → your public nickname here. Everyone in the place sees it.
 *  - **Someone else, and you own the server** → *their* public nickname. Same deal, and
 *    the copy says so, because renaming somebody in front of everyone is not a private act.
 *  - **Someone else, anywhere else** → a private alias. Yours alone; they never learn of it.
 *
 * The one thing worth spelling out on screen is which of those is happening, so the
 * description line is doing real work rather than decorating.
 */
const props = defineProps<{
  open: boolean
  /** Who is being renamed. */
  member: Pick<User, 'id' | 'name'> | null
  /** The viewer, so "am I renaming myself" is answerable without another composable. */
  currentUserId: number | null
  /** Whether the viewer owns the server this is happening in — chats never pass true. */
  canRenameOthers?: boolean
}>()

const emit = defineEmits<{ 'update:open': [value: boolean] }>()

const { nicknameAt, setNickname } = useNicknames()

const draft = ref('')
const working = ref(false)
const error = ref('')

const isSelf = computed(() => props.member?.id === props.currentUserId)
/** Public whenever it's a name the whole place will see — mine, or an owner setting one. */
const scope = computed<'public' | 'private'>(() =>
  isSelf.value || props.canRenameOthers ? 'public' : 'private',
)

const description = computed(() => {
  if (isSelf.value) return 'Everyone in here will see this instead of your name. Leave it empty to go back to your name.'
  if (props.canRenameOthers) return `Everyone in here will see this instead of ${props.member?.name}'s name. Leave it empty to put their name back.`

  return `Only you will see this. ${props.member?.name} won't be told, and nobody else's view changes. Leave it empty to remove it.`
})

// Reopening on a different person must not offer the last one's nickname — refill from
// whatever is actually stored at the scope we're about to write to.
watch(() => [props.open, props.member?.id], () => {
  if (!props.open || !props.member) return
  draft.value = nicknameAt(props.member.id, scope.value)
  error.value = ''
})

async function save() {
  if (!props.member || working.value) return

  working.value = true
  error.value = ''

  try {
    await setNickname(props.member.id, draft.value.trim() || null, scope.value)
    emit('update:open', false)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save that nickname.'
  } finally {
    working.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent v-if="member">
      <DialogHeader>
        <DialogTitle>
          {{ isSelf ? 'Your nickname here' : `Nickname for ${member.name}` }}
        </DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>
      <form class="space-y-3" @submit.prevent="save">
        <Input v-model="draft" :placeholder="member.name" maxlength="50" autofocus />
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" :disabled="working" @click="emit('update:open', false)">
            Cancel
          </Button>
          <Button type="submit" :disabled="working">
            {{ working ? 'Saving…' : 'Save' }}
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>
</template>
