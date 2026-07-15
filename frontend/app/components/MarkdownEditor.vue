<script setup lang="ts">
import { Bold, Code, Eye, Italic, Link2, List, SquareCode, Strikethrough, TextQuote } from 'lucide-vue-next'

const props = withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
  autofocus?: boolean
  disabled?: boolean
  /** Cap on how tall the textarea grows before it starts scrolling. */
  maxHeight?: number
}>(), {
  placeholder: 'Message',
  maxHeight: 220,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  submit: []
  cancel: []
  paste: [event: ClipboardEvent]
}>()

const textarea = ref<HTMLTextAreaElement | null>(null)
const previewing = ref(false)

const draft = computed({
  get: () => props.modelValue,
  set: (value: string) => emit('update:modelValue', value),
})

/* ---------------------------------------------------------------- autogrow */

function resize() {
  const el = textarea.value
  if (!el) return
  el.style.height = 'auto' // shrink first, otherwise it can only ever grow
  el.style.height = `${Math.min(el.scrollHeight, props.maxHeight)}px`
}

watch(() => props.modelValue, () => nextTick(resize))
watch(previewing, (on) => { if (!on) nextTick(() => { resize(); textarea.value?.focus() }) })

onMounted(() => {
  resize()
  // `autofocus` is only honoured on page load, and this editor is mounted on demand.
  if (props.autofocus) textarea.value?.focus()
})

/* ------------------------------------------------------- toolbar / commands */

/** Rewrite the draft and put the caret back where the user expects it. */
function replaceSelection(text: string, selectStart: number, selectEnd: number) {
  draft.value = text
  nextTick(() => {
    const el = textarea.value
    if (!el) return
    el.focus()
    el.setSelectionRange(selectStart, selectEnd)
    resize()
  })
}

/** Wrap (or unwrap) the selection — bold, italic, strikethrough, inline code. */
function surround(marker: string, placeholder: string, closing = marker) {
  const el = textarea.value
  if (!el) return

  const { selectionStart: start, selectionEnd: end } = el
  const value = props.modelValue
  const selected = value.slice(start, end)

  if (selected.startsWith(marker) && selected.endsWith(closing) && selected.length > marker.length + closing.length - 1) {
    const stripped = selected.slice(marker.length, selected.length - closing.length)
    replaceSelection(value.slice(0, start) + stripped + value.slice(end), start, start + stripped.length)
    return
  }

  const body = selected || placeholder
  const wrapped = `${marker}${body}${closing}`
  replaceSelection(
    value.slice(0, start) + wrapped + value.slice(end),
    start + marker.length,
    start + marker.length + body.length, // leave the text selected so typing overwrites the placeholder
  )
}

/** Toggle a prefix on every line the selection touches — lists, quotes. */
function prefixLines(prefix: string) {
  const el = textarea.value
  if (!el) return

  const value = props.modelValue
  const lineStart = value.lastIndexOf('\n', el.selectionStart - 1) + 1
  const lineEndIndex = value.indexOf('\n', el.selectionEnd)
  const lineEnd = lineEndIndex === -1 ? value.length : lineEndIndex

  const lines = value.slice(lineStart, lineEnd).split('\n')
  const allPrefixed = lines.every(line => line.startsWith(prefix))
  const next = lines
    .map(line => (allPrefixed ? line.slice(prefix.length) : prefix + line))
    .join('\n')

  replaceSelection(
    value.slice(0, lineStart) + next + value.slice(lineEnd),
    lineStart,
    lineStart + next.length,
  )
}

function codeBlock() {
  const el = textarea.value
  if (!el) return

  const { selectionStart: start, selectionEnd: end } = el
  const value = props.modelValue
  const selected = value.slice(start, end) || 'code'
  const lead = start > 0 && value[start - 1] !== '\n' ? '\n' : ''
  const block = `${lead}\`\`\`\n${selected}\n\`\`\``

  replaceSelection(
    value.slice(0, start) + block + value.slice(end),
    start + lead.length + 4, // just past the opening fence + newline
    start + lead.length + 4 + selected.length,
  )
}

function link() {
  const el = textarea.value
  if (!el) return

  const { selectionStart: start, selectionEnd: end } = el
  const value = props.modelValue
  const label = value.slice(start, end) || 'text'
  const inserted = `[${label}](url)`
  const urlAt = start + label.length + 3

  replaceSelection(value.slice(0, start) + inserted + value.slice(end), urlAt, urlAt + 3)
}

const tools = [
  { icon: Bold, title: 'Bold (Ctrl+B)', run: () => surround('**', 'bold') },
  { icon: Italic, title: 'Italic (Ctrl+I)', run: () => surround('*', 'italic') },
  { icon: Strikethrough, title: 'Strikethrough', run: () => surround('~~', 'strikethrough') },
  { icon: Code, title: 'Inline code (Ctrl+E)', run: () => surround('`', 'code') },
  { icon: SquareCode, title: 'Code block', run: codeBlock },
  { icon: Link2, title: 'Link (Ctrl+K)', run: link },
  { icon: List, title: 'Bulleted list', run: () => prefixLines('- ') },
  { icon: TextQuote, title: 'Quote', run: () => prefixLines('> ') },
]

/* -------------------------------------------------------------- keyboard */

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('cancel')
    return
  }

  // Enter sends; Shift+Enter drops to a new line. `isComposing` keeps us from
  // sending mid-word when an IME candidate is being confirmed with Enter.
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault()
    emit('submit')
    return
  }

  if (!event.ctrlKey && !event.metaKey) return

  const shortcut: Record<string, () => void> = {
    b: () => surround('**', 'bold'),
    i: () => surround('*', 'italic'),
    e: () => surround('`', 'code'),
    k: link,
  }
  const run = shortcut[event.key.toLowerCase()]
  if (run) {
    event.preventDefault()
    run()
  }
}

defineExpose({ focus: () => textarea.value?.focus() })
</script>

<template>
  <div class="rounded-md border bg-transparent dark:bg-input/30 focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50 transition-[color,box-shadow]">
    <div class="flex items-center gap-0.5 border-b px-1 py-0.5">
      <button
        v-for="tool in tools"
        :key="tool.title"
        type="button"
        tabindex="-1"
        class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-40"
        :title="tool.title"
        :disabled="previewing || disabled"
        @click="tool.run"
      >
        <component :is="tool.icon" class="h-3.5 w-3.5" />
      </button>

      <div class="ml-auto flex items-center gap-0.5">
        <slot name="toolbar-end" />
        <button
          type="button"
          tabindex="-1"
          class="rounded p-1.5 hover:bg-muted"
          :class="previewing ? 'bg-muted text-foreground' : 'text-muted-foreground hover:text-foreground'"
          :title="previewing ? 'Back to editing' : 'Preview'"
          :aria-pressed="previewing"
          @click="previewing = !previewing"
        >
          <Eye class="h-3.5 w-3.5" />
        </button>
      </div>
    </div>

    <div
      v-if="previewing"
      class="min-h-[2.5rem] px-3 py-2"
      @click="previewing = false"
    >
      <MarkdownBody v-if="draft.trim()" :source="draft" />
      <p v-else class="text-sm text-muted-foreground">Nothing to preview.</p>
    </div>

    <textarea
      v-else
      ref="textarea"
      v-model="draft"
      rows="1"
      :placeholder="placeholder"
      :autofocus="autofocus"
      :disabled="disabled"
      class="block w-full resize-none bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
      @keydown="onKeydown"
      @paste="emit('paste', $event)"
    />

    <slot name="footer" />
  </div>
</template>
