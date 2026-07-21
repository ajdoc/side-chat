<script setup lang="ts">
import type { User } from '~/types'

const props = defineProps<{ readers: User[] }>()

/** Past a handful, the avatars stop being faces and start being a wall. */
const MAX_AVATARS = 5

const shown = computed(() => props.readers.slice(0, MAX_AVATARS))
const overflow = computed(() => Math.max(0, props.readers.length - MAX_AVATARS))

// Whatever they're called in this server or chat — see useNicknames.
const { nameFor } = useNicknames()

const label = computed(() => {
  const names = props.readers.map(r => nameFor(r))
  if (names.length <= 3) return `Seen by ${names.join(', ')}`

  return `Seen by ${names.slice(0, 3).join(', ')} and ${names.length - 3} more`
})

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <div v-if="readers.length" class="mt-1 flex items-center gap-1" :title="label">
    <span class="text-[10px] uppercase tracking-wide text-muted-foreground">Seen</span>
    <div class="flex -space-x-1.5">
      <span
        v-for="reader in shown"
        :key="reader.id"
        class="grid h-4 w-4 place-items-center rounded-full bg-secondary text-[8px] font-semibold text-secondary-foreground ring-1 ring-background"
        :title="nameFor(reader)"
      >
        {{ initials(nameFor(reader)) }}
      </span>
    </div>
    <span v-if="overflow" class="text-[10px] text-muted-foreground">+{{ overflow }}</span>
  </div>
</template>
