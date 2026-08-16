import type { KanbanBoard, KanbanCard, KanbanColumn } from '~/types'

/**
 * A channel's kanban board — its columns, its cards, and everyone else's edits to both.
 *
 * ## Why this exists at all
 *
 * The board used to be the widget's JSON state: the card was handed a `Widget`, read
 * `state.cards`, and pushed every change through a widget action. That worked while a board was
 * three fixed columns of one-line cards, and stopped the moment columns became editable and
 * cards became commentable — both of which need rows, not a blob (see the kanban tables
 * migration). So the board now has its own endpoints, and this is the client half of them.
 *
 * ## Shared per surface
 *
 * Through {@link useSurfaceStore}, like the calendar and the notes, because the same board can
 * be on screen twice at once — a timeline card and the Kanban tab, or an app channel and a
 * popped-out window. Two copies would drift on every local edit, since a broadcast excludes the
 * socket it came from and two views in one tab share a socket.
 *
 * ## Optimistic, but never for the shape
 *
 * A card move applies locally first: dragging is the commonest gesture on a board and a card
 * that snaps back for 80ms reads as a dropped drag. Column edits are *not* optimistic — they
 * rehome cards server-side, and guessing at where twenty cards went is how a client draws a
 * board that doesn't exist.
 */
export function useKanban(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const { state, attach } = useSurfaceStore('kanban', basePath, () => ({
    board: ref<KanbanBoard | null>(null),
    loading: ref(true),
    error: ref(''),
  }))

  const { board, loading, error } = state

  const columns = computed<KanbanColumn[]>(() => board.value?.columns ?? [])

  const cards = computed<KanbanCard[]>(() => board.value?.cards ?? [])

  /** The in-flight read, so concurrent callers await one request rather than firing several. */
  let loadingBoard: Promise<void> | null = null

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** The cards of one column, in board order. */
  function cardsIn(column: string) {
    return cards.value
      .filter(c => c.column === column)
      .sort((a, b) => a.position - b.position || a.id - b.id)
  }

  function upsert(card: KanbanCard) {
    if (!board.value) return
    const i = board.value.cards.findIndex(c => c.id === card.id)
    if (i === -1) board.value.cards.push(card)
    else board.value.cards[i] = { ...board.value.cards[i], ...card }
  }

  /**
   * Read the whole board.
   *
   * Also the recovery path for a `cards_stale` broadcast — see the listener. Guarded against
   * overlapping: a column removal that rehomes forty cards is one event, but several views of
   * one surface share this store, and a burst of edits shouldn't queue a read per edit.
   */
  async function load() {
    if (loadingBoard) return loadingBoard

    loadingBoard = (async () => {
      try {
        const res = await api<{ data: KanbanBoard }>(`${basePath}/kanban`)
        board.value = res.data
        error.value = ''
      }
      catch (e: any) {
        error.value = e?.data?.message ?? 'Could not open this board.'
      }
      finally {
        loading.value = false
        loadingBoard = null
      }
    })()

    return loadingBoard
  }

  // --- cards ---------------------------------------------------------------------------------

  async function addCard(text: string, column?: string) {
    const body: Record<string, unknown> = { text }
    if (column) body.column = column

    const res = await api<{ data: KanbanCard }>(`${basePath}/kanban/cards`, {
      method: 'POST', body, headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  /**
   * Any edit to a card: text, column, position, assignee.
   *
   * Applied locally first and reconciled with what comes back. The rollback is a reload rather
   * than an undo of the patch: a failed move usually means the column is gone, and the honest
   * answer to that is the board as it now is.
   */
  async function patchCard(id: number, changes: Partial<KanbanCard> & { assignee_id?: number | null }) {
    const before = board.value?.cards.find(c => c.id === id)
    if (before) upsert({ ...before, ...changes } as KanbanCard)

    try {
      const res = await api<{ data: KanbanCard }>(`${basePath}/kanban/cards/${id}`, {
        method: 'PATCH', body: changes, headers: socketHeaders(),
      })
      upsert(res.data)
      return res.data
    }
    catch (e) {
      await load()
      throw e
    }
  }

  async function removeCard(id: number) {
    if (board.value) board.value.cards = board.value.cards.filter(c => c.id !== id)
    await api(`${basePath}/kanban/cards/${id}`, { method: 'DELETE', headers: socketHeaders() })
  }

  /**
   * Drop a card into a column, above `beforeId` or at the end.
   *
   * The server does the shifting (everything at or after the slot moves down by one); this only
   * has to name the slot. Working out the target index from the *rendered* order rather than
   * from stored positions is what makes it correct when positions have gaps — and they always
   * do, because a card leaving a column doesn't compact it.
   */
  async function moveCard(id: number, column: string, beforeId: number | null = null) {
    const inColumn = cardsIn(column).filter(c => c.id !== id)
    const index = beforeId === null ? inColumn.length : Math.max(0, inColumn.findIndex(c => c.id === beforeId))

    await patchCard(id, { column, position: index })
  }

  // --- columns -------------------------------------------------------------------------------

  /** Every column write answers with the whole board, since they all move cards. */
  async function writeColumns(path: string, method: 'POST' | 'PATCH' | 'DELETE', body?: object) {
    const res = await api<{ data: KanbanBoard }>(`${basePath}/kanban/columns${path}`, {
      method, body, headers: socketHeaders(),
    })
    board.value = res.data
    return res.data
  }

  const addColumn = (label: string) => writeColumns('', 'POST', { label })
  const renameColumn = (key: string, label: string) => writeColumns(`/${key}`, 'PATCH', { label })
  const moveColumn = (key: string, position: number) => writeColumns(`/${key}`, 'PATCH', { position })
  const removeColumn = (key: string) => writeColumns(`/${key}`, 'DELETE')
  const clearColumn = (key: string) => writeColumns(`/${key}/cards`, 'DELETE')

  /** Load once, and follow everyone else's edits for as long as any view is mounted. */
  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = echo.private(streamName)

      // The same one-event-for-the-whole-app stream the tracker rides — see TrackerChanged.
      channel.listen('.TrackerChanged', (e: { subject: string, action: string, payload: any }) => {
        if (e.subject === 'kanban_board') {
          /*
           * A reference, not the board.
           *
           * The event carries the columns and `cards_stale` — never the cards themselves, which
           * are unbounded and blew past the websocket's message ceiling on a real import of 84.
           * See KanbanBoards::boardSaved. So: redraw the layout from what arrived, and re-read
           * the cards over HTTP, where a big response is only a big response.
           */
          if (board.value) board.value.columns = e.payload.columns ?? board.value.columns
          if (e.payload.cards_stale) void load()
          else if (!board.value) void load()
        }
        else if (e.subject === 'kanban_card') {
          if (e.action === 'removed') {
            if (board.value) board.value.cards = board.value.cards.filter(c => c.id !== e.payload.id)
          }
          else upsert(e.payload)
        }
      })

      return () => channel.stopListening('.TrackerChanged')
    })
  }

  return {
    board, columns, cards, loading, error,
    open, load, cardsIn,
    addCard, patchCard, removeCard, moveCard,
    addColumn, renameColumn, moveColumn, removeColumn, clearColumn,
  }
}
