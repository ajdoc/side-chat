<script setup lang="ts">
/**
 * A roster of people, each as avatar · name · email — the shared presentation behind every
 * "who's here" list: the channel/DM/group Info panel, a thread's participants, a side chat's
 * members. Purely presentational; whoever mounts it owns the fetching and passes the members.
 */
interface Participant {
  id: number
  name: string
  email?: string | null
  avatar?: string | null
}

withDefaults(defineProps<{
  members: Participant[]
  /** Marks this member with a "creator" badge — the person who started a side chat, say. */
  creatorId?: number | null
  emptyText?: string
}>(), {
  creatorId: null,
  emptyText: 'No one here yet.',
})

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <ul v-if="members.length" class="space-y-1">
    <li v-for="m in members" :key="m.id" class="flex items-center gap-2.5 rounded-md px-1 py-1">
      <span class="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
        <img v-if="m.avatar" :src="m.avatar" :alt="m.name" class="h-full w-full object-cover">
        <span v-else>{{ initials(m.name) }}</span>
      </span>
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-1.5">
          <span class="truncate text-sm font-medium">{{ m.name }}</span>
          <span v-if="creatorId != null && m.id === creatorId" class="shrink-0 rounded bg-muted px-1 text-[10px] text-muted-foreground">creator</span>
        </div>
        <p v-if="m.email" class="truncate text-xs text-muted-foreground">{{ m.email }}</p>
      </div>
    </li>
  </ul>
  <p v-else class="py-6 text-center text-sm text-muted-foreground">{{ emptyText }}</p>
</template>
