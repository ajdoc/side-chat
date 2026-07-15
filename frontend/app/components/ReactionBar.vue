<script setup lang="ts">
import type { Reaction } from '~/types'

const props = defineProps<{
  reactions: Reaction[]
  currentUserId: number | null
}>()

const emit = defineEmits<{ toggle: [emoji: string] }>()

/** The API ships the reactors, not an "is this mine" flag — one broadcast, many viewers. */
function isMine(reaction: Reaction) {
  return props.currentUserId != null && reaction.users.some(u => u.id === props.currentUserId)
}

function tooltip(reaction: Reaction) {
  const names = reaction.users.map(u => (u.id === props.currentUserId ? 'You' : u.name))
  const shown = names.length <= 3 ? names : [...names.slice(0, 3), `${names.length - 3} more`]

  return `${listToText(shown)} reacted with ${reaction.emoji}`
}

function listToText(names: string[]) {
  if (names.length <= 1) return names[0] ?? ''
  return `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`
}
</script>

<template>
  <div v-if="reactions.length" class="mt-1 flex flex-wrap items-center gap-1">
    <button
      v-for="reaction in reactions"
      :key="reaction.emoji"
      type="button"
      class="flex h-6 items-center gap-1 rounded-full border px-2 text-xs transition"
      :class="isMine(reaction)
        ? 'border-primary bg-primary/10 font-medium text-foreground'
        : 'bg-muted/40 text-muted-foreground hover:bg-muted'"
      :title="tooltip(reaction)"
      :aria-pressed="isMine(reaction)"
      @click="emit('toggle', reaction.emoji)"
    >
      <span class="text-sm leading-none">{{ reaction.emoji }}</span>
      <span class="tabular-nums">{{ reaction.count }}</span>
    </button>

    <EmojiPicker compact @select="emit('toggle', $event)" />
  </div>
</template>
