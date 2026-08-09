<script setup lang="ts">
import { ScreenShare, Video } from 'lucide-vue-next'
import type { Watchable } from '~/composables/useCallStage'
import { MAX_WATCHING } from '~/composables/useCallStage'

/**
 * The row of things you could put on the stage, and which of them are on it.
 *
 * A picker of one-at-a-time radio buttons became a set of toggles when the stage became a grid:
 * each chip adds its screen or face beside what's already up, and pressing a lit one takes that
 * one down. Shared by the voice channel and the Side Space rail, which had a chip row each and
 * now need the same rather harder-to-read state — lit, and unavailable-because-full.
 */
defineProps<{
  watchables: Watchable[]
  /** Keys currently on the stage. */
  watching: string[]
  /** No room for another — the chips that aren't already up go dim rather than dead-clickable. */
  full?: boolean
  compact?: boolean
}>()

const emit = defineEmits<{ toggle: [Watchable] }>()
</script>

<template>
  <div class="flex flex-wrap gap-1">
    <button
      v-for="w in watchables"
      :key="w.key"
      type="button"
      class="flex items-center gap-1 rounded transition"
      :class="[
        compact ? 'px-1.5 py-0.5 text-[11px]' : 'px-2 py-0.5 text-xs',
        watching.includes(w.key)
          ? 'bg-primary text-primary-foreground'
          : full
            ? 'cursor-not-allowed bg-muted/50 text-muted-foreground/50'
            : 'bg-muted text-muted-foreground hover:bg-muted/70',
      ]"
      :disabled="full && !watching.includes(w.key)"
      :title="full && !watching.includes(w.key)
        ? `You're already watching ${MAX_WATCHING} — close one to make room`
        : watching.includes(w.key) ? `Stop watching ${w.name}` : `Watch ${w.name} as well`"
      @click="emit('toggle', w)"
    >
      <ScreenShare v-if="w.kind === 'screen'" class="h-3 w-3 shrink-0" />
      <Video v-else class="h-3 w-3 shrink-0" />
      {{ w.name }}
    </button>
  </div>
</template>
