<script setup lang="ts">
import { CheckSquare, Plus, Square, X } from 'lucide-vue-next'
import type { CanvasItem, CanvasTodoEntry } from '~/types'

/**
 * A `todo` card's body — a title and a checklist. Every change (toggle, add, remove, retitle)
 * emits the whole `content` up for the host ({@link SideSpaceCanvas}) to persist. Remote
 * changes are adopted from the item, except the title while it's being edited.
 */
const props = defineProps<{ item: CanvasItem, canEdit: boolean }>()
const emit = defineEmits<{ change: [Record<string, any>] }>()

const titleInput = ref<HTMLInputElement | null>(null)
const title = ref<string>(props.item.content.title ?? '')
const entries = ref<CanvasTodoEntry[]>(props.item.content.items ?? [])
const draft = ref('')

watch(() => props.item.content, (c) => {
  entries.value = (c.items as CanvasTodoEntry[]) ?? []
  if (document.activeElement !== titleInput.value) title.value = c.title ?? ''
}, { deep: true })

function commit() {
  emit('change', { title: title.value, items: entries.value })
}
function addEntry() {
  const t = draft.value.trim()
  if (!t) return
  entries.value = [...entries.value, { id: crypto.randomUUID(), text: t, done: false }]
  draft.value = ''
  commit()
}
function toggle(id: string) {
  if (!props.canEdit) return
  entries.value = entries.value.map(e => (e.id === id ? { ...e, done: !e.done } : e))
  commit()
}
function removeEntry(id: string) {
  entries.value = entries.value.filter(e => e.id !== id)
  commit()
}
</script>

<template>
  <div class="flex h-full flex-col" @pointerdown.stop>
    <input
      v-model="title"
      :readonly="!canEdit"
      ref="titleInput"
      placeholder="Checklist"
      class="shrink-0 border-b bg-transparent px-2 py-1 text-sm font-medium outline-none placeholder:text-muted-foreground read-only:cursor-default"
      @change="commit"
    >
    <ul class="min-h-0 flex-1 overflow-y-auto p-1.5 text-sm">
      <li v-for="e in entries" :key="e.id" class="group flex items-start gap-1.5 rounded px-1 py-0.5 hover:bg-muted/50">
        <button class="mt-0.5 shrink-0 text-muted-foreground hover:text-foreground" :disabled="!canEdit" @click="toggle(e.id)">
          <CheckSquare v-if="e.done" class="h-4 w-4 text-primary" />
          <Square v-else class="h-4 w-4" />
        </button>
        <span class="min-w-0 flex-1 break-words" :class="e.done ? 'text-muted-foreground line-through' : ''">{{ e.text }}</span>
        <button v-if="canEdit" class="shrink-0 text-muted-foreground opacity-0 hover:text-destructive group-hover:opacity-100" title="Remove" @click="removeEntry(e.id)">
          <X class="h-3.5 w-3.5" />
        </button>
      </li>
      <li v-if="!entries.length" class="px-1 py-1 text-xs text-muted-foreground">No items yet.</li>
    </ul>
    <form v-if="canEdit" class="flex shrink-0 items-center gap-1 border-t p-1.5" @submit.prevent="addEntry">
      <input v-model="draft" placeholder="Add item…" class="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground">
      <button type="submit" class="shrink-0 text-muted-foreground hover:text-foreground" title="Add"><Plus class="h-4 w-4" /></button>
    </form>
  </div>
</template>
