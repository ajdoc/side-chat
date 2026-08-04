<script setup lang="ts">
import { Check, Loader2, MessageSquare, ShieldOff, UserMinus, UserPlus, Users, X } from 'lucide-vue-next'
import type { Friendship, User } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * The friend list.
 *
 * Four tabs over one table — friends, what's waiting on you, what you've sent, and who you
 * put a wall in front of. They're tabs rather than four sections down a page because only
 * one of them is ever urgent, and it's the one with the badge on it.
 *
 * Adding is by exact name on purpose. There is no directory here, and no fuzzy search: the
 * point of a friend list is the people you already know, and anything looser would be a way
 * to page through every account on the instance. (StoreFriendRequest, server-side.)
 */
definePageMeta({ middleware: 'auth', layout: 'app' })

const {
  friends, blocked, incoming, outgoing, loading,
  load, add, accept, decline, remove, removeUser, block, unblock,
} = useFriends()
const { openDirect } = useConversations()

type Tab = 'friends' | 'incoming' | 'outgoing' | 'blocked'
const tab = ref<Tab>('friends')

const name = ref('')
const adding = ref(false)
const error = ref('')
const notice = ref('')

const tabs = computed(() => [
  { key: 'friends' as const, label: 'Friends', count: friends.value.length },
  { key: 'incoming' as const, label: 'Requests', count: incoming.value.length },
  { key: 'outgoing' as const, label: 'Sent', count: outgoing.value.length },
  { key: 'blocked' as const, label: 'Blocked', count: blocked.value.length },
])

async function submitAdd() {
  const wanted = name.value.trim()
  if (!wanted || adding.value) return

  adding.value = true
  error.value = ''
  notice.value = ''
  try {
    const friendship = await add({ name: wanted })
    name.value = ''
    // They'd already asked you: the server read the two crossing requests as a yes.
    notice.value = friendship.status === 'accepted'
      ? `You and ${friendship.user.name} are now friends.`
      : `Request sent to ${friendship.user.name}.`
  } catch (e: any) {
    error.value = e?.data?.message ?? 'We couldn’t send that request.'
  } finally {
    adding.value = false
  }
}

/** Wrap the row buttons so a failed one says so rather than silently doing nothing. */
async function run(action: () => Promise<unknown>) {
  error.value = ''
  notice.value = ''
  try {
    await action()
  } catch (e: any) {
    error.value = e?.data?.message ?? 'That didn’t work.'
  }
}

async function message(person: User) {
  const conversation = await openDirect(person.id)
  await navigateTo(`/chats/${conversation.id}`)
}

function personOf(friendship: Friendship) {
  return friendship.user
}

onMounted(() => load())

useHead({ title: 'Friends' })
</script>

<template>
  <div class="flex flex-1 flex-col overflow-hidden">
    <header class="flex flex-col gap-3 border-b p-4">
      <div class="flex items-center gap-2">
        <Users class="h-5 w-5 text-muted-foreground" />
        <h1 class="text-lg font-semibold">
          Friends
        </h1>
      </div>

      <!-- Add by name. Exact match — see the page comment. -->
      <form class="flex gap-2" @submit.prevent="submitAdd">
        <Input v-model="name" placeholder="Add a friend by their exact name" class="max-w-sm" />
        <Button type="submit" class="gap-2" :disabled="!name.trim() || adding">
          <Loader2 v-if="adding" class="h-4 w-4 animate-spin" />
          <UserPlus v-else class="h-4 w-4" /> Add
        </Button>
      </form>

      <p v-if="error" class="text-sm text-destructive">
        {{ error }}
      </p>
      <p v-else-if="notice" class="text-sm text-muted-foreground">
        {{ notice }}
      </p>

      <nav class="flex gap-1">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
          :class="tab === t.key ? 'bg-secondary text-secondary-foreground' : 'text-muted-foreground hover:bg-muted'"
          @click="tab = t.key"
        >
          {{ t.label }}
          <span
            v-if="t.count"
            class="rounded-full px-1.5 text-xs"
            :class="t.key === 'incoming' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
          >{{ t.count }}</span>
        </button>
      </nav>
    </header>

    <div class="flex-1 overflow-y-auto p-4">
      <div v-if="loading" class="grid h-24 place-items-center">
        <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
      </div>

      <!-- Friends: people, not rows — so this list shows faces and a Message button. -->
      <ul v-else-if="tab === 'friends'" class="space-y-1">
        <li
          v-for="person in friends"
          :key="person.id"
          class="flex items-center gap-3 rounded-md p-2 hover:bg-muted"
        >
          <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">
            <img v-if="person.avatar" :src="person.avatar" :alt="person.name" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initialsOf(person.name) }}</span>
            <PresenceDot :user-id="person.id" class="absolute bottom-0 right-0 h-2.5 w-2.5" />
          </span>

          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{ person.name }}</span>
            <span class="block truncate text-xs text-muted-foreground">{{ person.email }}</span>
          </span>

          <Button variant="ghost" size="sm" class="gap-1.5" @click="message(person)">
            <MessageSquare class="h-4 w-4" /> Message
          </Button>
          <Button variant="ghost" size="sm" title="Remove friend" @click="run(() => removeUser(person.id))">
            <UserMinus class="h-4 w-4" />
          </Button>
          <Button variant="ghost" size="sm" title="Block" @click="run(() => block(person.id))">
            <ShieldOff class="h-4 w-4" />
          </Button>
        </li>
        <li v-if="!friends.length" class="p-6 text-center text-sm text-muted-foreground">
          No friends yet. Add someone by their exact name above.
        </li>
      </ul>

      <!-- Waiting on you: the only tab with a yes/no on it. -->
      <ul v-else-if="tab === 'incoming'" class="space-y-1">
        <li
          v-for="friendship in incoming"
          :key="friendship.id"
          class="flex items-center gap-3 rounded-md p-2 hover:bg-muted"
        >
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">
            <img v-if="personOf(friendship).avatar" :src="personOf(friendship).avatar!" :alt="personOf(friendship).name" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initialsOf(personOf(friendship).name) }}</span>
          </span>
          <span class="min-w-0 flex-1 truncate text-sm">
            <strong class="font-medium">{{ personOf(friendship).name }}</strong> wants to be friends
          </span>
          <Button size="sm" class="gap-1.5" @click="run(() => accept(friendship))">
            <Check class="h-4 w-4" /> Accept
          </Button>
          <Button variant="ghost" size="sm" title="Decline" @click="run(() => decline(friendship))">
            <X class="h-4 w-4" />
          </Button>
        </li>
        <li v-if="!incoming.length" class="p-6 text-center text-sm text-muted-foreground">
          Nothing waiting on you.
        </li>
      </ul>

      <!-- Sent: nothing to accept here, only to take back. -->
      <ul v-else-if="tab === 'outgoing'" class="space-y-1">
        <li
          v-for="friendship in outgoing"
          :key="friendship.id"
          class="flex items-center gap-3 rounded-md p-2 hover:bg-muted"
        >
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">
            <img v-if="personOf(friendship).avatar" :src="personOf(friendship).avatar!" :alt="personOf(friendship).name" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initialsOf(personOf(friendship).name) }}</span>
          </span>
          <span class="min-w-0 flex-1 truncate text-sm">
            Waiting on <strong class="font-medium">{{ personOf(friendship).name }}</strong>
          </span>
          <Button variant="ghost" size="sm" class="gap-1.5" @click="run(() => remove(friendship))">
            <X class="h-4 w-4" /> Cancel
          </Button>
        </li>
        <li v-if="!outgoing.length" class="p-6 text-center text-sm text-muted-foreground">
          You haven’t sent any requests.
        </li>
      </ul>

      <ul v-else class="space-y-1">
        <li
          v-for="friendship in blocked"
          :key="friendship.id"
          class="flex items-center gap-3 rounded-md p-2 hover:bg-muted"
        >
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-semibold text-secondary-foreground">
            {{ initialsOf(personOf(friendship).name) }}
          </span>
          <span class="min-w-0 flex-1 truncate text-sm">{{ personOf(friendship).name }}</span>
          <Button variant="ghost" size="sm" @click="run(() => unblock(personOf(friendship).id))">
            Unblock
          </Button>
        </li>
        <li v-if="!blocked.length" class="p-6 text-center text-sm text-muted-foreground">
          You haven’t blocked anyone.
        </li>
      </ul>
    </div>
  </div>
</template>
