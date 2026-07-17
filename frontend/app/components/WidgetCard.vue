<script setup lang="ts">
import type { Widget } from '~/types'

/**
 * Renders a widget message's card by dispatching on its `type`. The timeline doesn't need
 * to know a music player from a board — it just hands the widget here (see MessageItem).
 */
defineProps<{ widget: Widget }>()
</script>

<template>
  <MusicPlayer v-if="widget.type === 'music'" :widget="widget" />
  <KanbanBoard v-else-if="widget.type === 'kanban'" :widget="widget" />
</template>
