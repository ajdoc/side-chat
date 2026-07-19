<script setup lang="ts">
import { MessageSquareText, SmilePlus } from 'lucide-vue-next'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * React *or* comment on a message from one popover. Emoji reactions and word-comments are
 * two flavours of the same "react without typing a full reply" gesture, so they share a
 * single entry in the toolbar instead of two near-identical buttons. The emoji grid mirrors
 * EmojiPicker; the one-line composer posts a comment the same way CommentBar's chips do.
 */
const props = defineProps<{
  messageId: number
}>()

const emit = defineEmits<{
  react: [emoji: string]
  /** Open the full comment list / composer (the "view all" affordance). */
  'open-all': []
}>()

// Exposed so the message's hover toolbar can stay put while the popover is open — otherwise
// moving the mouse into the menu un-hovers the message and the toolbar vanishes.
const open = defineModel<boolean>('open', { default: false })

// Same hand-picked set (and grouping) as EmojiPicker — the backend validator accepts these.
const groups: { label: string, emoji: string[] }[] = [
  { label: 'Reactions', emoji: ['👍', '👎', '❤️', '🎉', '😂', '😮', '😢', '🔥'] },
  { label: 'Work', emoji: ['✅', '❌', '👀', '🙏', '💯', '🚀', '🐛', '🤔'] },
  { label: 'Fun', emoji: ['😅', '🙌', '👏', '💀', '🤝', '☕', '🍕', '🧠'] },
]

const { toggle } = useComments()

const MAX = 120
const comment = ref('')
const posting = ref(false)

function pickEmoji(emoji: string) {
  emit('react', emoji)
  open.value = false
}

async function submitComment() {
  const text = comment.value.trim()
  if (!text || text.length > MAX || posting.value) return
  posting.value = true
  try {
    // Fire it through the same write path as the chips; the refreshed summary lands over
    // the stream. Toggling the same phrase again takes it back, exactly like a chip.
    await toggle(props.messageId, text, null)
    comment.value = ''
    open.value = false
  } finally {
    posting.value = false
  }
}
</script>

<template>
  <DropdownMenu v-model:open="open">
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        class="rounded p-1 text-muted-foreground hover:text-foreground"
        title="React or comment"
        aria-label="React or comment"
      >
        <SmilePlus class="h-4 w-4" />
      </button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="w-64 p-2">
      <div v-for="group in groups" :key="group.label" class="mb-1">
        <p class="px-1 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          {{ group.label }}
        </p>
        <div class="grid grid-cols-8 gap-0.5">
          <button
            v-for="emoji in group.emoji"
            :key="emoji"
            type="button"
            class="grid h-7 w-7 place-items-center rounded text-lg transition hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
            :aria-label="`React with ${emoji}`"
            @click="pickEmoji(emoji)"
          >
            {{ emoji }}
          </button>
        </div>
      </div>

      <!-- Quick word-comment — the same action as a CommentBar chip, one line. -->
      <div class="mt-1 border-t pt-2">
        <form class="flex items-center gap-1.5" @submit.prevent="submitComment">
          <input
            v-model="comment"
            type="text"
            :maxlength="MAX"
            placeholder="Add a comment…"
            class="min-w-0 flex-1 rounded-md border bg-transparent px-2 py-1 text-sm outline-none focus:ring-1 focus:ring-ring"
            @keydown.stop
          >
          <button
            type="submit"
            class="shrink-0 rounded-md bg-primary px-2 py-1 text-xs font-medium text-primary-foreground transition hover:bg-primary/90 disabled:opacity-50"
            :disabled="!comment.trim() || posting"
          >
            Post
          </button>
        </form>
        <button
          type="button"
          class="mt-1.5 flex items-center gap-1 px-1 text-xs text-muted-foreground transition hover:text-foreground"
          @click="emit('open-all'); open = false"
        >
          <MessageSquareText class="h-3.5 w-3.5" /> View all comments
        </button>
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
