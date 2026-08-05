<script setup lang="ts">
import { Smile } from 'lucide-vue-next'
import { EMOTES } from '~/lib/spaceEmotes'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

/**
 * Send an emote *as* a message.
 *
 * Deliberately not the emoji picker sitting next to it in the composer. That one puts a
 * character into the line you're writing — it decorates a sentence. This one sends, the way
 * {@link GifPicker} does: the emote **is** the message, in the same way a GIF is, and asking
 * somebody to pick a face and then press enter would make it a slower way to type a colon and
 * a bracket.
 *
 * Which is what makes it a second channel of conversation rather than a garnish on the first:
 * a line in the timeline that is one glyph, drawn big by {@link MessageItem}, and — if the
 * channel is a Side Space — popped over your avatar's head by the room, from the same twelve
 * faces the emote bar in there offers. One vocabulary, wherever you happen to be standing.
 */

const emit = defineEmits<{ select: [glyph: string] }>()

const open = defineModel<boolean>('open', { default: false })

function pick(glyph: string) {
  emit('select', glyph)
  open.value = false
}
</script>

<template>
  <DropdownMenu v-model:open="open">
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        tabindex="-1"
        class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Send an emote"
        aria-label="Send an emote"
      >
        <Smile class="h-3.5 w-3.5" />
      </button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="w-auto p-1">
      <p class="px-2 pb-1 pt-0.5 text-[11px] text-muted-foreground">Sends on its own</p>
      <div class="grid grid-cols-6 gap-0.5">
        <button
          v-for="e in EMOTES"
          :key="e.glyph"
          type="button"
          class="flex h-9 w-9 items-center justify-center rounded text-xl leading-none transition-colors hover:bg-muted"
          :title="e.label"
          :aria-label="e.label"
          @click="pick(e.glyph)"
        >
          {{ e.glyph }}
        </button>
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
