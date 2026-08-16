<script setup lang="ts">
import { Ban, Bot, Hash, LoaderCircle, MessagesSquare, ScrollText, ShieldCheck, Users } from 'lucide-vue-next'
import type { AdminOverview } from '~/types'

/**
 * The panel's front page.
 *
 * Counts and one list. The counts say how big the instance is; the list says who is
 * currently blocked, which is the only thing on this screen somebody is waiting on an
 * answer about. Everything else here is a door to one of the other four sections.
 */
definePageMeta({ middleware: 'admin', layout: 'admin' })

const { overview } = useAdmin()

const data = ref<AdminOverview | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    data.value = await overview()
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load the overview.'
  }
  finally {
    loading.value = false
  }
})

const tiles = computed(() => {
  const c = data.value?.counts
  if (!c) return []
  return [
    { label: 'People', value: c.users, hint: `${c.new_users_this_week} joined this week`, icon: Users, to: '/admin/users' },
    { label: 'Bots', value: c.bots, hint: 'Automated accounts', icon: Bot, to: '/admin/users?filter=bots' },
    { label: 'Admins', value: c.admins, hint: 'Hold a site role', icon: ShieldCheck, to: '/admin/users?filter=admins' },
    { label: 'Blocked', value: c.banned, hint: 'Cannot sign in', icon: Ban, to: '/admin/users?filter=banned' },
    { label: 'Servers', value: c.servers, hint: `${c.channels} channels`, icon: Hash, to: '/admin/servers' },
    { label: 'Chats', value: c.dms + c.groups, hint: `${c.dms} DMs · ${c.groups} groups`, icon: MessagesSquare, to: '/admin/chats' },
    { label: 'Messages', value: c.messages, hint: 'Across every timeline', icon: ScrollText, to: '/admin/audit' },
  ]
})
</script>

<template>
  <div class="p-6">
    <AdminHeader title="Overview" subtitle="What this instance is made of, and what needs attention." />

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="h-4 w-4 animate-spin" /> Loading…
    </div>

    <p v-else-if="error" class="text-sm text-destructive">{{ error }}</p>

    <template v-else>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <NuxtLink
          v-for="tile in tiles"
          :key="tile.label"
          :to="tile.to"
          class="rounded-lg border bg-card p-4 transition-colors hover:border-primary/40 hover:bg-muted/40"
        >
          <div class="flex items-center justify-between">
            <span class="text-xs uppercase tracking-wide text-muted-foreground">{{ tile.label }}</span>
            <component :is="tile.icon" class="h-4 w-4 text-muted-foreground" />
          </div>
          <p class="mt-2 text-2xl font-semibold tabular-nums">{{ tile.value.toLocaleString() }}</p>
          <p class="mt-0.5 text-xs text-muted-foreground">{{ tile.hint }}</p>
        </NuxtLink>
      </div>

      <section class="mt-8">
        <h2 class="mb-3 text-sm font-semibold">Currently blocked</h2>

        <p v-if="!data?.banned_users.length" class="text-sm text-muted-foreground">
          Nobody is blocked right now.
        </p>

        <ul v-else class="divide-y rounded-lg border bg-card">
          <li v-for="person in data.banned_users" :key="person.id" class="flex flex-wrap items-center gap-3 p-3">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ person.name }}</p>
              <p class="truncate text-xs text-muted-foreground">{{ person.email }}</p>
            </div>
            <p class="min-w-0 flex-1 truncate text-sm text-muted-foreground" :title="person.ban_reason ?? ''">
              “{{ person.ban_reason }}”
            </p>
            <NuxtLink :to="`/admin/users?q=${encodeURIComponent(person.email)}`" class="text-xs text-primary hover:underline">
              Manage
            </NuxtLink>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
