<script setup lang="ts">
import { Check, Eye, Loader2, Pencil } from 'lucide-vue-next'

/**
 * The Notes app — a Side Space's one shared markdown document. A plain autogrowing textarea
 * (not {@link MarkdownEditor}, whose Enter-sends behaviour belongs to the composer, not a
 * document) with a preview toggle that renders the body through {@link MarkdownBody}.
 *
 * Saves are debounced and last-write-wins: typing schedules a PUT ~700ms after you stop, and
 * a save arriving from someone else is applied only while this editor is idle — so a remote
 * edit never yanks the text out from under an active cursor. Surface-agnostic via the same
 * base-path / stream contract the board uses.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  readonlyHint?: string
}>()

const {
  content, updatedBy, updatedAt, loading, saving,
  load, save, subscribe, unsubscribe,
} = useSpaceNote(props.basePath, props.streamName)

const previewing = ref(false)
const focused = ref(false)
let saveTimer: ReturnType<typeof setTimeout> | undefined
// True from the first keystroke until the ensuing save resolves — so we don't clobber a
// pending local edit with a remote one that raced it.
const dirty = ref(false)

function scheduleSave() {
  if (!props.canEdit) return
  dirty.value = true
  clearTimeout(saveTimer)
  saveTimer = setTimeout(flush, 700)
}

async function flush() {
  clearTimeout(saveTimer)
  if (!dirty.value) return
  dirty.value = false
  await save(content.value)
}

/** A save from someone else. Apply it only when we're not the one mid-edit. */
function onRemote(next: string) {
  if (!focused.value && !dirty.value) content.value = next
}

function onBlur() {
  focused.value = false
  void flush() // persist immediately on blur rather than waiting out the debounce
}

function relTime(iso: string | null) {
  if (!iso) return ''
  const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (secs < 60) return 'just now'
  const mins = Math.round(secs / 60)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  return hrs < 24 ? `${hrs}h ago` : `${Math.round(hrs / 24)}d ago`
}

onMounted(async () => {
  await load()
  subscribe(onRemote)
})
onBeforeUnmount(() => {
  void flush()
  unsubscribe()
})
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Status / preview toggle -->
    <div class="flex h-9 shrink-0 items-center gap-2 border-b px-3 text-xs text-muted-foreground">
      <template v-if="saving">
        <Loader2 class="h-3.5 w-3.5 animate-spin" /> Saving…
      </template>
      <template v-else-if="updatedBy">
        <Check class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
        <span class="truncate">Edited by {{ updatedBy.name }} · {{ relTime(updatedAt) }}</span>
      </template>
      <span v-else>No notes yet.</span>

      <button
        type="button"
        class="ml-auto flex items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
        :class="previewing ? 'text-foreground' : ''"
        :aria-pressed="previewing"
        @click="previewing = !previewing"
      >
        <component :is="previewing ? Pencil : Eye" class="h-3.5 w-3.5" />
        {{ previewing ? 'Edit' : 'Preview' }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex flex-1 items-center justify-center">
      <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
    </div>

    <!-- Preview -->
    <div v-else-if="previewing" class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
      <MarkdownBody v-if="content.trim()" :source="content" />
      <p v-else class="text-sm text-muted-foreground">Nothing to preview yet.</p>
    </div>

    <!-- Edit -->
    <textarea
      v-else
      v-model="content"
      :readonly="!canEdit"
      :placeholder="canEdit ? 'Jot down shared notes… Markdown supported.' : (readonlyHint ?? 'Read-only')"
      class="min-h-0 flex-1 resize-none bg-transparent px-4 py-3 font-mono text-sm leading-relaxed outline-none placeholder:text-muted-foreground read-only:cursor-default"
      @input="scheduleSave"
      @focus="focused = true"
      @blur="onBlur"
    />
  </div>
</template>
