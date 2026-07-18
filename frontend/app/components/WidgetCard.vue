<script setup lang="ts">
import type { Widget } from '~/types'

/**
 * Renders a widget message's card by dispatching on its `type`. The timeline doesn't need
 * to know a music player from a board — it just hands the widget here (see MessageItem).
 */
defineProps<{ widget: Widget }>()
</script>

<template>
  <!-- A card can arrive as a reference (no state) over the socket; its state lands a beat
       later from /api/widgets/{id}. Hold a slim placeholder until it does. -->
  <div v-if="!widget.state" class="h-16 animate-pulse rounded-lg bg-muted" />
  <MusicPlayer v-else-if="widget.type === 'music'" :widget="widget" />
  <KanbanBoard v-else-if="widget.type === 'kanban'" :widget="widget" />
</template>
