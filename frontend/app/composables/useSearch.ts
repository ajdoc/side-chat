import type { SearchFilters, SearchHas, SearchMessage, SearchResults, SearchSurface } from '~/types'

/**
 * Search, for both of the places it's used.
 *
 * The palette (⌘K) and the channel search panel look nothing alike and want the same three
 * things: debounce the typing, drop answers to questions the user has already retyped, and
 * turn `from:ana has:link` into query parameters. So they share this, and differ only in
 * which of the two fetchers they call.
 *
 * The stale-answer guard is the part worth keeping. Search fires per keystroke, responses
 * come back out of order more often than you'd think on a slow connection, and the failure
 * looks like the results flickering back to a previous query — a bug that never reproduces
 * when you're looking for it. Every request takes a sequence number and anything that isn't
 * the newest is dropped on arrival.
 */

/** How long after the last keystroke a request goes out. */
const DEBOUNCE_MS = 180

/** Below this there are too many matches for the answer to mean anything. */
const MIN_TERM = 2

const EMPTY: SearchResults = {
  conversations: [], channels: [], side_chats: [], threads: [], side_chat_groups: [], servers: [], messages: [],
}

export function useSearch() {
  const api = useApi()

  const results = ref<SearchResults>({ ...EMPTY })
  const messages = ref<SearchMessage[]>([])
  // Named places matching the same term — the strip above the message results.
  const surfaces = ref<SearchSurface[]>([])
  const loading = ref(false)
  const page = ref(1)
  const lastPage = ref(1)

  const hasMore = computed(() => page.value < lastPage.value)

  // Newest request wins. See the note above — this is the whole reason it exists. Surfaces
  // get their own counter: they're fetched in parallel with the messages, so sharing one
  // would have each request cancel the other's answer.
  let sequence = 0
  let surfaceSequence = 0
  let timer: ReturnType<typeof setTimeout> | undefined

  function params(term: string, filters: SearchFilters, extra: Record<string, string | number> = {}) {
    const query: Record<string, string> = { q: term }
    for (const [key, value] of Object.entries({ ...filters, ...extra })) {
      if (value !== undefined && value !== null && value !== '') query[key] = String(value)
    }
    return new URLSearchParams(query).toString()
  }

  /** A few of each kind — what ⌘K shows. */
  async function palette(term: string, filters: SearchFilters = {}) {
    const trimmed = term.trim()
    if (trimmed.length < MIN_TERM) {
      results.value = { ...EMPTY }
      loading.value = false
      return
    }

    const ticket = ++sequence
    loading.value = true
    try {
      const res = await api<{ data: SearchResults }>(`/api/search?${params(trimmed, filters)}`)
      if (ticket !== sequence) return
      results.value = res.data
    } catch {
      if (ticket === sequence) results.value = { ...EMPTY }
    } finally {
      if (ticket === sequence) loading.value = false
    }
  }

  /**
   * The named places in scope — side chats, threads and groups — as one short list.
   *
   * Fetched alongside the messages rather than instead of them, and capped rather than
   * paginated: it sits above the message results as a "did you mean this place?" strip. A
   * channel has tens of these, not thousands, so a handful is the whole useful answer and a
   * second page would be a page nobody turns.
   */
  async function searchSurfaces(term: string, filters: SearchFilters = {}) {
    const trimmed = term.trim()
    if (trimmed.length < MIN_TERM) {
      surfaces.value = []
      return
    }

    const ticket = ++surfaceSequence
    try {
      // The palette endpoint, reused: it already returns a few of each kind in one round
      // trip, which is exactly this list. Asking for three types separately would be three
      // requests per keystroke to build one strip.
      const res = await api<{ data: SearchResults }>(`/api/search?${params(trimmed, filters)}`)
      if (ticket !== surfaceSequence) return
      surfaces.value = [...res.data.side_chats, ...res.data.threads, ...res.data.side_chat_groups]
    } catch {
      if (ticket === surfaceSequence) surfaces.value = []
    }
  }

  /** The full, paginated message list — what the search panel shows. */
  async function searchMessages(term: string, filters: SearchFilters = {}, append = false) {
    const trimmed = term.trim()
    if (trimmed.length < MIN_TERM) {
      messages.value = []
      return
    }

    const ticket = ++sequence
    const next = append ? page.value + 1 : 1
    loading.value = true
    try {
      const res = await api<{ data: SearchMessage[], meta: { current_page: number, last_page: number } }>(
        `/api/search?${params(trimmed, filters, { type: 'messages', page: next })}`,
      )
      if (ticket !== sequence) return
      // Deduped on append: a message sent while you were reading page one shifts everything
      // down, and the same row would otherwise arrive twice on page two.
      const seen = new Set(append ? messages.value.map(m => m.id) : [])
      messages.value = append
        ? [...messages.value, ...res.data.filter(m => !seen.has(m.id))]
        : res.data
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } catch {
      if (ticket === sequence && !append) messages.value = []
    } finally {
      if (ticket === sequence) loading.value = false
    }
  }

  /** Run `fn` once the typing settles, cancelling whatever was already queued. */
  function debounced(fn: () => void) {
    clearTimeout(timer)
    timer = setTimeout(fn, DEBOUNCE_MS)
  }

  function reset() {
    clearTimeout(timer)
    // Bump the ticket so anything already in flight lands on the floor rather than
    // repopulating a list the user has just closed.
    sequence++
    surfaceSequence++
    results.value = { ...EMPTY }
    messages.value = []
    surfaces.value = []
    loading.value = false
    page.value = 1
    lastPage.value = 1
  }

  onScopeDispose(() => clearTimeout(timer))

  return { results, messages, surfaces, loading, hasMore, palette, searchMessages, searchSurfaces, debounced, reset, MIN_TERM }
}

/**
 * `from:ana has:link before:2026-01-01 deploy` → filters, plus the words left over.
 *
 * Typed tokens rather than a row of dropdowns because the two audiences want opposite
 * things: the chips are discoverable and the syntax is fast, and parsing the syntax out of
 * the same box means the chips can simply *write* it — one input, one source of truth, and
 * a search you can copy out of the box and paste back in.
 *
 * `from:` resolves to a name, not an id; the caller matches it against people it knows
 * about, since only it knows whose names are in scope.
 */
export interface ParsedQuery {
  /** The search words, with the tokens removed. */
  term: string
  fromName: string | null
  has: SearchHas | null
  after: string | null
  before: string | null
}

const HAS_VALUES: SearchHas[] = ['link', 'file', 'image']

export function parseSearchQuery(raw: string): ParsedQuery {
  const parsed: ParsedQuery = { term: '', fromName: null, has: null, after: null, before: null }
  const words: string[] = []

  for (const word of raw.split(/\s+/)) {
    // `from:"ana lee"` — quotes survive the split above as part of the token, so strip them
    // here rather than trying to be clever about splitting on whitespace.
    const match = /^(from|has|after|before):(.*)$/i.exec(word)
    const value = match?.[2]?.replace(/^["']|["']$/g, '') ?? ''

    if (!match || !value) {
      words.push(word)
      continue
    }

    switch (match[1]!.toLowerCase()) {
      case 'from':
        parsed.fromName = value
        break
      case 'has':
        // An unrecognised `has:` value is left in the search words rather than dropped —
        // silently ignoring it would return results that quietly ignore what was asked.
        if (HAS_VALUES.includes(value.toLowerCase() as SearchHas)) parsed.has = value.toLowerCase() as SearchHas
        else words.push(word)
        break
      case 'after':
        parsed.after = value
        break
      case 'before':
        parsed.before = value
        break
    }
  }

  parsed.term = words.join(' ').trim()

  return parsed
}
