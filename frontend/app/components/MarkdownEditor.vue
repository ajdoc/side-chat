<script setup lang="ts">
import { AtSign, Bold, Code, Eye, Italic, Link2, List, SquareCode, Strikethrough, TextQuote } from 'lucide-vue-next'
import type { ChannelMember } from '~/types'

const props = withDefaults(defineProps<{
  modelValue: string
  placeholder?: string
  autofocus?: boolean
  disabled?: boolean
  /** Cap on how tall the textarea grows before it starts scrolling. */
  maxHeight?: number
  /** Roster for the `@` autocomplete. Empty is fine — `@all` is always offered. */
  mentionMembers?: ChannelMember[]
}>(), {
  placeholder: 'Message',
  maxHeight: 220,
  mentionMembers: () => [],
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

/* ------------------------------------------------------- @mention autocomplete */

interface MentionOption {
  /** -1 marks the synthetic "all"; otherwise a real member id. */
  id: number
  name: string
  hint: string
}

const menuOpen = ref(false)
const mentionQuery = ref('')
const mentionStart = ref(0) // index of the `@` in the draft
const activeIndex = ref(0)

const options = computed<MentionOption[]>(() => {
  const q = mentionQuery.value.toLowerCase()
  const all: MentionOption = { id: -1, name: 'all', hint: 'Notify everyone here' }
  const members: MentionOption[] = props.mentionMembers.map(m => ({ id: m.id, name: m.name, hint: '' }))
  return [all, ...members]
    .filter(o => o.name.toLowerCase().includes(q))
    .slice(0, 8)
})

const showMenu = computed(() => menuOpen.value && options.value.length > 0)

/**
 * Is the caret sitting in an `@…` token? If so, arm the menu and remember where the token
 * starts. The `@` must open the token — start of line or after whitespace — and the query
 * runs to the caret with no space in it, so "@" in an email never trips it.
 */
function detectMention() {
  const el = textarea.value
  if (!el || props.disabled) return closeMenu()

  const pos = el.selectionStart ?? 0
  const match = /(?:^|\s)@([^\s@]*)$/.exec(props.modelValue.slice(0, pos))
  if (!match) return closeMenu()

  mentionQuery.value = match[1] ?? ''
  mentionStart.value = pos - mentionQuery.value.length - 1
  menuOpen.value = true
}

function closeMenu() {
  menuOpen.value = false
  mentionQuery.value = ''
}

/** Swap the half-typed `@query` for the chosen name and drop the caret after it. */
function selectMention(option: MentionOption) {
  const el = textarea.value
  const caretNow = el?.selectionStart ?? props.modelValue.length
  const before = props.modelValue.slice(0, mentionStart.value)
  const after = props.modelValue.slice(caretNow)
  const inserted = `@${option.name} `

  draft.value = before + inserted + after
  closeMenu()

  nextTick(() => {
    if (!el) return
    const caret = before.length + inserted.length
    el.focus()
    el.setSelectionRange(caret, caret)
    resize()
  })
}

// A fresh query is a fresh list — start the highlight at the top.
watch(mentionQuery, () => { activeIndex.value = 0 })

/* -------------------------------------------------------------- keyboard */

function onKeydown(event: KeyboardEvent) {
  // While the mention menu is up it owns the arrow keys, Enter/Tab and Escape — so none of
  // them reach the "send" / "cancel" handlers below.
  if (showMenu.value) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      activeIndex.value = (activeIndex.value + 1) % options.value.length
      return
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      activeIndex.value = (activeIndex.value - 1 + options.value.length) % options.value.length
      return
    }
    if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault()
      selectMention(options.value[activeIndex.value]!)
      return
    }
    if (event.key === 'Escape') {
      event.preventDefault()
      closeMenu()
      return
    }
  }

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

    <div v-else class="relative">
      <textarea
        ref="textarea"
        v-model="draft"
        rows="1"
        :placeholder="placeholder"
        :autofocus="autofocus"
        :disabled="disabled"
        class="block w-full resize-none bg-transparent px-3 py-2 text-base outline-none placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
        @keydown="onKeydown"
        @keyup="detectMention()"
        @click="detectMention()"
        @blur="closeMenu()"
        @paste="emit('paste', $event)"
      />

      <!-- @mention picker: floats just above the caret's line. Mousedown (not click) so it
           fires before the textarea's blur tears the menu down. -->
      <ul
        v-if="showMenu"
        class="absolute bottom-full left-2 z-20 mb-1 max-h-56 w-64 overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
      >
        <li
          v-for="(option, i) in options"
          :key="option.id"
          class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm"
          :class="i === activeIndex ? 'bg-accent text-accent-foreground' : ''"
          @mouseenter="activeIndex = i"
          @mousedown.prevent="selectMention(option)"
        >
          <AtSign class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span class="truncate font-medium">{{ option.name }}</span>
          <span v-if="option.hint" class="ml-auto truncate text-xs text-muted-foreground">{{ option.hint }}</span>
        </li>
      </ul>
    </div>

    <slot name="footer" />
  </div>
</template>
