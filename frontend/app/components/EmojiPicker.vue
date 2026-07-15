<script setup lang="ts">
import { SmilePlus } from 'lucide-vue-next'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

defineProps<{
  /** Render as a small inline "+" pill next to existing reactions, rather than a hover button. */
  compact?: boolean
}>()

const emit = defineEmits<{ select: [emoji: string] }>()

// Exposed so the message's hover toolbar can stay put while the picker is open —
// otherwise moving the mouse into the menu un-hovers the message and the toolbar
// (the button you just clicked) vanishes out from under it.
const open = defineModel<boolean>('open', { default: false })

// A hand-picked set rather than the full Unicode table: reactions are a quick "yes /
// ha / thanks", and a searchable 1,800-emoji grid is a worse tool for that than a
// dozen good ones. Same emoji the backend's validator accepts, so nothing here bounces.
const groups: { label: string, emoji: string[] }[] = [
  { label: 'Reactions', emoji: ['👍', '👎', '❤️', '🎉', '😂', '😮', '😢', '🔥'] },
  { label: 'Work', emoji: ['✅', '❌', '👀', '🙏', '💯', '🚀', '🐛', '🤔'] },
  { label: 'Fun', emoji: ['😅', '🙌', '👏', '💀', '🤝', '☕', '🍕', '🧠'] },
]

function pick(emoji: string) {
  emit('select', emoji)
  open.value = false
}
</script>

<template>
  <DropdownMenu v-model:open="open">
    <DropdownMenuTrigger as-child>
      <button
        v-if="compact"
        type="button"
        class="flex h-6 items-center rounded-full border border-dashed px-2 text-muted-foreground transition hover:border-solid hover:bg-muted hover:text-foreground"
        title="Add a reaction"
        aria-label="Add a reaction"
      >
        <SmilePlus class="h-3.5 w-3.5" />
      </button>
      <button
        v-else
        type="button"
        class="rounded p-1 text-muted-foreground hover:text-foreground"
        title="Add a reaction"
        aria-label="Add a reaction"
      >
        <SmilePlus class="h-4 w-4" />
      </button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="w-auto p-2">
      <div v-for="group in groups" :key="group.label" class="mb-1 last:mb-0">
        <p class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          {{ group.label }}
        </p>
        <div class="grid grid-cols-8 gap-0.5">
          <button
            v-for="emoji in group.emoji"
            :key="emoji"
            type="button"
            class="grid h-8 w-8 place-items-center rounded text-lg transition hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            :aria-label="`React with ${emoji}`"
            @click="pick(emoji)"
          >
            {{ emoji }}
          </button>
        </div>
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
