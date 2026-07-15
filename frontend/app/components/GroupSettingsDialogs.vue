<script setup lang="ts">
import { Check, Loader2 } from 'lucide-vue-next'
import type { Conversation, User } from '~/types'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'
import { Input } from '~/components/ui/input'

/** The three things you can do to a group chat, kept out of the page they hang off. */
const props = defineProps<{ conversation: Conversation }>()

const addMembers = defineModel<boolean>('addMembers', { required: true })
const rename = defineModel<boolean>('rename', { required: true })
const leave = defineModel<boolean>('leave', { required: true })

const { user } = useAuth()
const { contacts, addMembers: add, renameGroup, leaveGroup } = useConversations()

const working = ref(false)
const error = ref('')

// --- add people ---
const candidates = ref<User[]>([])
const picked = ref<number[]>([])
const loadingPeople = ref(false)

/** Never offer to add somebody who is already in the group. */
const addable = computed(() => {
  const inside = new Set(props.conversation.members.map(m => m.id))

  return candidates.value.filter(p => !inside.has(p.id))
})

watch(addMembers, async (open) => {
  if (!open) return

  picked.value = []
  error.value = ''
  loadingPeople.value = true
  try {
    candidates.value = await contacts()
  } finally {
    loadingPeople.value = false
  }
})

function toggle(id: number) {
  picked.value = picked.value.includes(id)
    ? picked.value.filter(p => p !== id)
    : [...picked.value, id]
}

async function onAdd() {
  if (!picked.value.length || working.value) return

  working.value = true
  error.value = ''
  try {
    await add(props.conversation.id, picked.value)
    addMembers.value = false
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t add them.'
  } finally {
    working.value = false
  }
}

// --- rename ---
const nameDraft = ref('')

watch(rename, (open) => {
  if (!open) return
  nameDraft.value = props.conversation.name ?? ''
  error.value = ''
})

async function onRename() {
  const name = nameDraft.value.trim()
  if (!name || working.value) return

  working.value = true
  error.value = ''
  try {
    await renameGroup(props.conversation.id, name)
    rename.value = false
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t rename it.'
  } finally {
    working.value = false
  }
}

// --- leave ---
async function onLeave() {
  if (working.value) return

  working.value = true
  error.value = ''
  try {
    await leaveGroup(props.conversation.id)
    leave.value = false
    await navigateTo('/chats')
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t take you out of the group.'
  } finally {
    working.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="addMembers">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Add people to {{ conversation.name }}</DialogTitle>
        <DialogDescription>
          They'll be able to read everything said in here so far.
        </DialogDescription>
      </DialogHeader>

      <div class="max-h-64 overflow-y-auto rounded-md border">
        <div v-if="loadingPeople" class="grid h-24 place-items-center">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
        <p v-else-if="!addable.length" class="p-4 text-center text-xs text-muted-foreground">
          Everyone you share a server with is already in here.
        </p>
        <button
          v-for="person in addable"
          v-else
          :key="person.id"
          type="button"
          class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition hover:bg-muted"
          @click="toggle(person.id)"
        >
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-[10px] font-semibold text-secondary-foreground">
            <img v-if="person.avatar" :src="person.avatar" :alt="person.name" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initialsOf(person.name) }}</span>
          </span>
          <span class="min-w-0 flex-1 truncate font-medium">{{ person.name }}</span>
          <Check v-if="picked.includes(person.id)" class="h-4 w-4 shrink-0 text-primary" />
        </button>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

      <div class="flex justify-end gap-2">
        <Button variant="outline" :disabled="working" @click="addMembers = false">Cancel</Button>
        <Button :disabled="!picked.length || working" @click="onAdd">
          {{ working ? 'Adding…' : `Add ${picked.length || ''}`.trim() }}
        </Button>
      </div>
    </DialogContent>
  </Dialog>

  <Dialog v-model:open="rename">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Rename group</DialogTitle>
        <DialogDescription>Everyone in the group sees the new name.</DialogDescription>
      </DialogHeader>
      <form class="space-y-3" @submit.prevent="onRename">
        <Input v-model="nameDraft" placeholder="Group name" maxlength="100" autofocus />
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" :disabled="working" @click="rename = false">
            Cancel
          </Button>
          <Button type="submit" :disabled="working || !nameDraft.trim()">
            {{ working ? 'Saving…' : 'Save' }}
          </Button>
        </div>
      </form>
    </DialogContent>
  </Dialog>

  <AlertDialog v-model:open="leave">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Leave {{ conversation.name }}?</AlertDialogTitle>
        <AlertDialogDescription>
          You'll stop receiving messages from this group and need someone to add you back.
          What you've already said stays where it is.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      <AlertDialogFooter>
        <AlertDialogCancel :disabled="working">Cancel</AlertDialogCancel>
        <!-- A plain Button, not AlertDialogAction: that one closes the dialog on click,
             before the handler runs, leaving any error message nowhere to be read. -->
        <Button variant="destructive" :disabled="working" @click="onLeave">
          {{ working ? 'Leaving…' : 'Leave group' }}
        </Button>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
