<script setup lang="ts">
import { ChevronDown, CornerUpLeft, MessageSquarePlus } from 'lucide-vue-next'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * The two ways to reply, behind one control. A plain reply quotes the message into the
 * composer; a thread reply opens an attached thread. Reply is the common case, so it's the
 * one-click main button — the caret drops down to "Reply in thread" where threads apply.
 * Without threads (DMs, thread panel itself) there's only the one button, no caret.
 */
const props = defineProps<{
  /** Whether "Reply in thread" is available here (channel timeline / side chats). */
  threadActions?: boolean
}>()

const emit = defineEmits<{
  reply: []
  thread: []
}>()

// Bubbled up so the hover toolbar stays put while the caret menu is open.
const open = defineModel<boolean>('open', { default: false })
</script>

<template>
  <!-- No threads here: a lone Reply button, nothing to drop down to. -->
  <button
    v-if="!props.threadActions"
    type="button"
    class="rounded p-1 text-muted-foreground hover:text-foreground"
    title="Reply"
    @click="emit('reply')"
  >
    <CornerUpLeft class="h-4 w-4" />
  </button>

  <!-- Split control: Reply on the left, caret opens the thread option. -->
  <div v-else class="flex items-center">
    <button
      type="button"
      class="rounded-l p-1 text-muted-foreground hover:text-foreground"
      title="Reply"
      @click="emit('reply')"
    >
      <CornerUpLeft class="h-4 w-4" />
    </button>
    <DropdownMenu v-model:open="open">
      <DropdownMenuTrigger as-child>
        <button
          type="button"
          class="rounded-r py-1 pr-0.5 text-muted-foreground hover:text-foreground"
          title="Reply options"
          aria-label="Reply options"
        >
          <ChevronDown class="h-3 w-3" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-44">
        <DropdownMenuItem @select="emit('reply')">
          <CornerUpLeft class="h-4 w-4" /> Reply
        </DropdownMenuItem>
        <DropdownMenuItem @select="emit('thread')">
          <MessageSquarePlus class="h-4 w-4" /> Reply in thread
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
