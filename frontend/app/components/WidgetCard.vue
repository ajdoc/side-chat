<script setup lang="ts">
import { ListMusic, PinOff } from 'lucide-vue-next'
import type { Widget } from '~/types'

/**
 * Renders a widget message's card by dispatching on its `type`. The timeline doesn't need
 * to know a music player from a board — it just hands the widget here (see MessageItem).
 */
defineProps<{ widget: Widget }>()

// A pinned music widget is being played by MusicDock, at the app level. Rendering the full
// card here too would build a second engine for the same song and play it twice, so the
// message shows a stub pointing at the dock instead.
const { isPinned, unpin } = useMusicPin()
</script>

<template>
  <!-- A card can arrive as a reference (no state) over the socket; its state lands a beat
       later from /api/widgets/{id}. Hold a slim placeholder until it does. -->
  <div v-if="!widget.state" class="h-16 animate-pulse rounded-lg bg-muted" />
  <div
    v-else-if="widget.type === 'music' && isPinned(widget.id)"
    class="mt-1.5 flex w-full max-w-md items-center gap-2 rounded-xl border bg-muted/30 px-3 py-2 text-xs text-muted-foreground"
  >
    <ListMusic class="h-3.5 w-3.5 flex-none text-primary" />
    <span class="min-w-0 flex-1">Playing in your pinned player — it follows you around.</span>
    <button class="flex flex-none items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground" title="Unpin" @click="unpin">
      <PinOff class="h-3.5 w-3.5" /> Unpin
    </button>
  </div>
  <MusicPlayer v-else-if="widget.type === 'music'" :widget="widget" />
  <KanbanBoard v-else-if="widget.type === 'kanban'" :widget="widget" />
  <PollWidget v-else-if="widget.type === 'poll'" :widget="widget" />
  <CoopShooter v-else-if="widget.type === 'shooter'" :widget="widget" />
  <CoopRacer v-else-if="widget.type === 'racing'" :widget="widget" />
  <SkribblGame v-else-if="widget.type === 'skribbl'" :widget="widget" />
</template>
