<script setup lang="ts">
import { FileText, LayoutGrid, NotebookPen, PenTool } from 'lucide-vue-next'
import type { SideDeskAppId } from '~/types'

/**
 * The Side Desk — a tabbed workspace that hangs beside a chat and houses the *apps* a
 * place builds things with. It grew out of the whiteboard: the board is now just the first
 * app among several (Notes, Docs, an open widget Canvas), each a tab here.
 *
 * Surface-agnostic, the same way {@link Whiteboard} is: the host hands a REST base path and
 * the private stream this space lives on, so one component drives a channel's space, a DM's,
 * and a side chat's alike. Each app hangs its own endpoints off `basePath` (the board uses
 * `${basePath}/whiteboard`). `canEdit` gates authoring; when false apps are read-only and
 * `readonlyHint` says why. The active tab is owned by the host (so it can live in the URL),
 * passed in and emitted back out.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  activeApp: SideDeskAppId
  readonlyHint?: string
}>()

const emit = defineEmits<{
  'update:activeApp': [SideDeskAppId]
  /** Docs asking the host timeline to scroll to the message a chat file arrived in. */
  'jump': [messageId: number]
}>()

const APPS = [
  { id: 'canvas', label: 'Canvas', icon: LayoutGrid },
  { id: 'board', label: 'Board', icon: PenTool },
  { id: 'notes', label: 'Notes', icon: NotebookPen },
  { id: 'docs', label: 'Docs', icon: FileText },
] as const
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- App tab bar -->
    <nav class="flex shrink-0 border-b">
      <button
        v-for="a in APPS"
        :key="a.id"
        type="button"
        class="flex flex-1 items-center justify-center gap-1.5 border-b-2 py-2 text-sm transition-colors"
        :class="activeApp === a.id
          ? 'border-primary font-medium text-foreground'
          : 'border-transparent text-muted-foreground hover:text-foreground'"
        @click="emit('update:activeApp', a.id)"
      >
        <component :is="a.icon" class="h-4 w-4" /> {{ a.label }}
      </button>
    </nav>

    <!-- Board — the shared whiteboard, keyed by base path so switching surfaces remounts. -->
    <Whiteboard
      v-if="activeApp === 'board'"
      :key="basePath"
      :base-path="`${basePath}/whiteboard`"
      :stream-name="streamName"
      :can-draw="canEdit"
      :readonly-hint="readonlyHint"
    />

    <!-- Notes — the surface's one shared markdown document. -->
    <SideDeskNotes
      v-else-if="activeApp === 'notes'"
      :key="`${basePath}-notes`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
    />

    <!-- Open Canvas — a free 2D board of note and checklist cards. -->
    <SideDeskCanvas
      v-else-if="activeApp === 'canvas'"
      :key="`${basePath}-canvas`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
    />

    <!-- Docs — a view-only shelf of uploaded PDF / Word / Excel files. -->
    <SideDeskDocs
      v-else
      :key="`${basePath}-docs`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
      @jump="emit('jump', $event)"
    />
  </div>
</template>
