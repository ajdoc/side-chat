<script setup lang="ts">
import { Check, Eye, Loader2, Pencil } from 'lucide-vue-next'
import { merge3 } from '~/lib/mergeText'

/**
 * The Notes app — a Side Space's one shared markdown document. A plain autogrowing textarea
 * (not {@link MarkdownEditor}, whose Enter-sends behaviour belongs to the composer, not a
 * document) with a preview toggle that renders the body through {@link MarkdownBody}.
 *
 * Saves are debounced — typing schedules a PUT ~700ms after you stop — and edits *merge*
 * rather than overwrite. A save from someone else lands straight in while this editor is idle;
 * while you're mid-edit it's three-way merged against the body you both started from
 * ({@link merge3}), so neither your paragraph nor theirs disappears and your cursor stays put.
 * The same merge runs on the save path when the server refuses a stale write (see
 * {@link useSpaceNote}). Surface-agnostic via the same base-path / stream contract the board uses.
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
const textarea = ref<HTMLTextAreaElement | null>(null)
let saveTimer: ReturnType<typeof setTimeout> | undefined
// True from the first keystroke until the ensuing save resolves — it marks text that only
// exists here, which is what every merge below is protecting.
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
  const submitted = content.value
  const settled = await save(submitted)
  if (settled === submitted) return

  // The save came back merged with somebody else's. Fold that result in — against whatever
  // has been typed since, if the editor moved on while the request was in flight.
  setContent(content.value === submitted ? settled : merge3(submitted, content.value, settled))
  scheduleSave() // the merged body is ours alone until we push it back up
}

/**
 * A save from someone else. Idle editor: take it as-is. Mid-edit: merge it into the text on
 * screen against `ancestor`, the body both versions grew out of, so their edit appears without
 * swallowing the sentence being typed.
 */
function onRemote(next: string, ancestor: string) {
  if (!focused.value && !dirty.value) {
    content.value = next
    return
  }
  const merged = merge3(ancestor, content.value, next)
  if (merged === content.value) return
  setContent(merged)
  if (dirty.value) scheduleSave() // our half of the merge still has to reach the server
}

/**
 * Replace the body under an active cursor without throwing the caret to the end — keep its
 * offset when the text before it is untouched (edits below don't move you), and otherwise
 * shift it by however much the text ahead of it grew or shrank.
 */
function setContent(next: string) {
  const el = textarea.value
  if (!el || !focused.value) {
    content.value = next
    return
  }
  const caret = el.selectionStart
  const prev = content.value
  const before = prev.slice(0, caret)
  content.value = next
  nextTick(() => {
    const at = next.startsWith(before)
      ? caret
      : Math.max(0, Math.min(next.length, caret + (next.length - prev.length)))
    el.setSelectionRange(at, at)
  })
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
      ref="textarea"
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
