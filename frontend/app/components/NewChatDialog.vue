<script setup lang="ts">
import { Check, Loader2, Search, Users } from 'lucide-vue-next'
import type { User } from '~/types'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'

/**
 * Start a chat.
 *
 * One dialog rather than two, because "DM Ana" and "make a group with Ana and Ben" are the
 * same gesture with a different number of people in it: pick one and you get a DM, pick
 * more and it asks you to name the group. Making the user choose the *shape* of the
 * conversation before choosing who's in it gets that backwards.
 *
 * The list is deliberately not a directory of everyone on the instance — it's the people
 * you share a server with, which is the server's rule (see ConversationService) and the
 * only thing standing between a DM and unsolicited mail from strangers.
 */
const open = defineModel<boolean>('open', { required: true })

const { openDirect, createGroup, contacts } = useConversations()

const query = ref('')
const people = ref<User[]>([])
const selected = ref<User[]>([])
const groupName = ref('')
const loading = ref(false)
const working = ref(false)
const error = ref('')

const isGroup = computed(() => selected.value.length > 1)
const canSubmit = computed(() =>
  selected.value.length > 0 && (!isGroup.value || groupName.value.trim().length > 0),
)

function isSelected(person: User) {
  return selected.value.some(p => p.id === person.id)
}

function toggle(person: User) {
  error.value = ''
  selected.value = isSelected(person)
    ? selected.value.filter(p => p.id !== person.id)
    : [...selected.value, person]
}

let searchTimer: ReturnType<typeof setTimeout> | undefined

async function search() {
  loading.value = true
  try {
    people.value = await contacts(query.value.trim())
  } finally {
    loading.value = false
  }
}

// Debounced: this fires on every keystroke, and the answer is a database query.
watch(query, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(search, 200)
})

async function submit() {
  if (!canSubmit.value || working.value) return

  working.value = true
  error.value = ''
  try {
    const conversation = isGroup.value
      ? await createGroup(groupName.value.trim(), selected.value.map(p => p.id))
      : await openDirect(selected.value[0]!.id)

    open.value = false
    await navigateTo(`/chats/${conversation.id}`)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t start that chat.'
  } finally {
    working.value = false
  }
}

// Fresh every time it opens — a half-built group left over from last time is a trap.
watch(open, (isOpen) => {
  if (!isOpen) return

  query.value = ''
  selected.value = []
  groupName.value = ''
  error.value = ''
  search()
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>New chat</DialogTitle>
        <DialogDescription>
          Pick someone to message. Choose more than one and it becomes a group.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3">
        <div class="relative">
          <Search class="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input v-model="query" placeholder="Search people you share a server with" class="pl-8" />
        </div>

        <!-- Who you've picked so far. Clicking one takes them back out. -->
        <div v-if="selected.length" class="flex flex-wrap gap-1.5">
          <button
            v-for="person in selected"
            :key="person.id"
            type="button"
            class="flex items-center gap-1.5 rounded-full bg-secondary py-1 pl-1 pr-2.5 text-xs font-medium text-secondary-foreground transition hover:bg-secondary/70"
            :title="`Remove ${person.name}`"
            @click="toggle(person)"
          >
            <span class="grid h-5 w-5 place-items-center rounded-full bg-background text-[9px]">
              <img v-if="person.avatar" :src="person.avatar" :alt="person.name" class="h-full w-full rounded-full object-cover">
              <span v-else>{{ initialsOf(person.name) }}</span>
            </span>
            {{ person.name }}
          </button>
        </div>

        <div class="max-h-56 min-h-24 overflow-y-auto rounded-md border">
          <div v-if="loading" class="grid h-24 place-items-center">
            <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
          </div>

          <p v-else-if="!people.length" class="p-4 text-center text-xs text-muted-foreground">
            {{ query
              ? 'Nobody by that name in the servers you’re in.'
              : 'You can message anyone you share a server with — join one to get started.' }}
          </p>

          <button
            v-for="person in people"
            v-else
            :key="person.id"
            type="button"
            class="flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm transition hover:bg-muted"
            @click="toggle(person)"
          >
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-[10px] font-semibold text-secondary-foreground">
              <img v-if="person.avatar" :src="person.avatar" :alt="person.name" class="h-full w-full rounded-full object-cover">
              <span v-else>{{ initialsOf(person.name) }}</span>
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ person.name }}</span>
              <span class="block truncate text-xs text-muted-foreground">{{ person.email }}</span>
            </span>
            <Check v-if="isSelected(person)" class="h-4 w-4 shrink-0 text-primary" />
          </button>
        </div>

        <!-- Only once it's actually a group. Asking for a name up front would be asking
             about a thing they haven't decided to make yet. -->
        <div v-if="isGroup" class="space-y-1.5">
          <label class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
            <Users class="h-3.5 w-3.5" /> Group name
          </label>
          <Input v-model="groupName" placeholder="Design crew" maxlength="100" />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

        <div class="flex justify-end gap-2">
          <Button variant="outline" :disabled="working" @click="open = false">Cancel</Button>
          <Button :disabled="!canSubmit || working" @click="submit">
            {{ working ? 'Starting…' : isGroup ? 'Create group' : 'Message' }}
          </Button>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
