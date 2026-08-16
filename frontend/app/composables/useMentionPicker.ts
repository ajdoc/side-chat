import type { Ref } from 'vue'
import type { ChannelMember } from '~/types'

export interface MentionOption {
  /** -1 marks the synthetic `@all`; anything else is a member id. */
  id: number
  name: string
  /** Their account name, when they're offered under a different one. */
  hint: string
}

/**
 * `@` autocomplete for a plain textarea.
 *
 * ## Why this isn't MarkdownEditor's menu
 *
 * The composer has one of these already, fused with its slash-command menu and with its own
 * Enter-sends keyboard handling — the two triggers share a popup there precisely because they
 * fight over the same keystrokes. The Notes app deliberately isn't a MarkdownEditor (a document
 * is not a message; Enter must insert a newline), so it needs the mention half without the
 * command half and without the send semantics.
 *
 * Rather than refactor the composer's menu — the riskiest keyboard path in the app — the
 * *mention* half lives here, where the next plain textarea that wants it can have it in three
 * lines. The composer keeps its own until there's a second reason to touch it.
 *
 * ## What it does and doesn't own
 *
 * It owns the token detection, the filtered options, the highlighted index and the insertion.
 * It does **not** own the keyboard: the caller wires `onKeydown` in, because whether Enter
 * belongs to the menu or to the document is the caller's decision, and this can only say
 * whether a menu is open.
 *
 * @param textarea the element being typed in
 * @param text the draft, read and written by the insertion
 * @param members the roster to offer, under their public names
 */
export function useMentionPicker(
  textarea: Ref<HTMLTextAreaElement | null>,
  text: Ref<string>,
  members: Ref<ChannelMember[]>,
  onInsert?: () => void,
) {
  const { publicNameFor } = useNicknames()

  const openMenu = ref(false)
  const query = ref('')
  /** Index of the `@` that opened the token. */
  const start = ref(0)
  const activeIndex = ref(0)

  /**
   * Members are offered under their *public* name — their nickname here, or their account name.
   * Never a private alias: what gets typed is `@Name`, and the server turns that back into a
   * person by matching the roster everybody shares, so a name only you can see matches nobody.
   */
  const options = computed<MentionOption[]>(() => {
    const q = query.value.toLowerCase()
    const all: MentionOption = { id: -1, name: 'all', hint: 'Everyone here' }

    const people = members.value.map((m) => {
      const name = publicNameFor(m)
      return { id: m.id, name, hint: name === m.name ? '' : m.name }
    })

    return [all, ...people]
      .filter(o => o.name.toLowerCase().includes(q) || (o.id !== -1 && o.hint.toLowerCase().includes(q)))
      .slice(0, 8)
  })

  const show = computed(() => openMenu.value && options.value.length > 0)

  function close() {
    openMenu.value = false
    query.value = ''
  }

  /**
   * Is the caret in an `@…` token?
   *
   * The `@` has to open the token — start of line or after whitespace — and the query runs to
   * the caret with no space in it, so an email address never trips it. Called from keyup and
   * click rather than from input, because moving the caret into an existing token should offer
   * the menu too.
   */
  function detect() {
    const el = textarea.value
    if (!el) return close()

    const pos = el.selectionStart ?? 0
    const match = /(?:^|\s)@([^\s@]*)$/.exec(text.value.slice(0, pos))
    if (!match) return close()

    query.value = match[1] ?? ''
    start.value = pos - query.value.length - 1
    openMenu.value = true
  }

  /** Swap the half-typed token for the chosen name and drop the caret after it. */
  function select(option: MentionOption) {
    const el = textarea.value
    const caretNow = el?.selectionStart ?? text.value.length
    const before = text.value.slice(0, start.value)
    const after = text.value.slice(caretNow)
    const inserted = `@${option.name} `

    text.value = before + inserted + after
    close()
    onInsert?.()

    nextTick(() => {
      if (!el) return
      const caret = before.length + inserted.length
      el.focus()
      el.setSelectionRange(caret, caret)
    })
  }

  /**
   * The keys the menu claims while it's up. Returns true when it handled the event, which is
   * the caller's signal to stop — everything else, Enter included, is the document's.
   */
  function onKeydown(event: KeyboardEvent): boolean {
    if (!show.value) return false

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      activeIndex.value = (activeIndex.value + 1) % options.value.length
      return true
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      activeIndex.value = (activeIndex.value - 1 + options.value.length) % options.value.length
      return true
    }
    // Tab and Enter both accept. Enter is the one that matters: while the menu is up it must
    // pick a name rather than break the line, and the caller returns early on `true` to make
    // that so.
    if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault()
      const option = options.value[activeIndex.value]
      if (option) select(option)
      return true
    }
    if (event.key === 'Escape') {
      event.preventDefault()
      close()
      return true
    }

    return false
  }

  // A fresh query is a fresh list — start the highlight at the top.
  watch(query, () => { activeIndex.value = 0 })

  return { show, options, activeIndex, detect, close, select, onKeydown }
}
