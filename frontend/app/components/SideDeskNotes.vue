<script setup lang="ts">
import { AtSign, Check, Eye, Loader2, Pencil } from 'lucide-vue-next'
import { merge3 } from '~/lib/mergeText'
import { mentionNamesKey, useChannelMembers } from '~/composables/useChannelMembers'

/**
 * The Notes app — a Side Desk's one shared markdown document. A plain autogrowing textarea
 * (not {@link MarkdownEditor}, whose Enter-sends behaviour belongs to the composer, not a
 * document) with a preview toggle that renders the body through {@link MarkdownBody}.
 *
 * Saves are debounced — typing schedules a PUT ~700ms after you stop — and edits *merge*
 * rather than overwrite. A save from someone else lands straight in while this editor is idle;
 * while you're mid-edit it's three-way merged against the body you both started from
 * ({@link merge3}), so neither your paragraph nor theirs disappears and your cursor stays put.
 * The same merge runs on the save path when the server refuses a stale write (see
 * {@link useSpaceNote}). Surface-agnostic via the same base-path / stream contract the board uses.
 *
 * ## @mentions
 *
 * Typing `@` offers the channel roster ({@link useMentionPicker}), the preview renders the
 * names as chips off that same roster, and the server tells whoever was named — once, when the
 * save that first adds their name lands. See AnnounceNoteMentionsAction.
 *
 * The picker owns Enter only while its menu is up. In a document Enter is a newline, and that
 * is why this isn't a {@link MarkdownEditor} in the first place.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  readonlyHint?: string
  /**
   * The channel whose roster fills the `@` picker.
   *
   * Optional, and defaulted from the stream name, because a surface app is handed a base path
   * and a stream and nothing else — see FloatingSurfaceContent, which has no channel to pass. A
   * side chat's desk passes its *parent* channel's id, the same scoping its widgets use; a
   * surface with neither simply gets no autocomplete, which is a plain textarea and still a
   * working note.
   */
  channelId?: number
}>()

const {
  content, noteId, updatedBy, updatedAt, loading, saving,
  load, save, subscribe, unsubscribe,
} = useSpaceNote(props.basePath, props.streamName)

/**
 * Whose names the picker offers and whose chips the preview draws.
 *
 * Provided down to MarkdownBody as well: the timeline provides this key for messages, and a
 * note rendered in a floating window or an app channel has no timeline above it to inherit
 * from — without this the preview would render `@Ada` as plain text in exactly the places the
 * note is most likely to be read.
 */
const mentionChannelId = computed(() => {
  if (props.channelId) return props.channelId
  const match = /^channel\.(\d+)$/.exec(props.streamName)
  return match ? Number(match[1]) : null
})

const { members, names: mentionNames, load: loadMembers } = useChannelMembers()
provide(mentionNamesKey, mentionNames)

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

const mentions = useMentionPicker(textarea, content, members, scheduleSave)

/** The picker claims the arrows, Enter, Tab and Escape while its menu is up; the rest is typing. */
function onKeydown(event: KeyboardEvent) {
  mentions.onKeydown(event)
}

onMounted(async () => {
  await load()
  subscribe(onRemote)
  if (mentionChannelId.value) void loadMembers(mentionChannelId.value)
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
    <div v-else class="relative flex min-h-0 flex-1 flex-col">
      <textarea
        ref="textarea"
        v-model="content"
        :readonly="!canEdit"
        :placeholder="canEdit ? 'Jot down shared notes… Markdown supported, @ to mention.' : (readonlyHint ?? 'Read-only')"
        class="min-h-0 flex-1 resize-none bg-transparent px-4 py-3 font-mono text-sm leading-relaxed outline-none placeholder:text-muted-foreground read-only:cursor-default"
        @input="scheduleSave"
        @keydown="onKeydown"
        @keyup="mentions.detect()"
        @click="mentions.detect()"
        @focus="focused = true"
        @blur="onBlur"
      />

      <!--
        The `@` picker.

        Anchored to the bottom of the editor rather than to the caret: a note is a full-height
        document and the caret can sit anywhere in it, so a caret-following popup would need the
        line geometry the composer computes for a three-line box. A fixed shelf is always in the
        same place, which for a menu you drive with the arrow keys is the better trade.

        `mousedown.prevent` so clicking an option doesn't blur the textarea first — a blur would
        flush the save and throw the caret away before the name is inserted.
      -->
      <ul
        v-if="mentions.show.value && canEdit"
        class="absolute inset-x-3 bottom-3 z-20 max-h-56 overflow-y-auto rounded-md border bg-popover p-1 text-sm shadow-md"
      >
        <li
          v-for="(option, i) in mentions.options.value"
          :key="option.id"
          class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5"
          :class="i === mentions.activeIndex.value ? 'bg-muted' : ''"
          @mouseenter="mentions.activeIndex.value = i"
          @mousedown.prevent="mentions.select(option)"
        >
          <AtSign class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span class="truncate">{{ option.name }}</span>
          <span v-if="option.hint" class="ml-auto truncate text-xs text-muted-foreground">{{ option.hint }}</span>
        </li>
      </ul>
    </div>

    <!--
      Talk *about* the note, under it.

      Distinct from the note itself, which is the thing being agreed on — a shared document
      whose margins fill with "should this say X?" stops being the shared document. Only once
      the note exists: there is nothing to discuss before its first save.
    -->
    <div v-if="noteId != null" class="shrink-0 border-t px-4 py-2">
      <AppItemDiscussion
        :base-path="basePath"
        subject="space_note"
        :item-id="noteId"
        :can-edit="canEdit"
      />
    </div>
  </div>
</template>
