import type { WhiteboardStroke, WhiteboardStrokeKind, WhiteboardStrokePayload } from '~/types'
import { simplify } from '~/lib/whiteboardEngine'

/** How often the live drag / cursor may whisper — matches the co-op games' ~12Hz peer rate. */
const WHISPER_EVERY = 80
/** Drop a remote cursor we haven't heard from in this long (they moved away or left). */
const CURSOR_TTL = 4000
/** Hard cap on points in a *live preview* whisper, so a long path never nears Reverb's limit. */
const LIVE_POINT_CAP = 300

/**
 * A detached copy of a stroke's payload.
 *
 * `structuredClone` is the obvious tool and the wrong one: a payload reached through
 * `strokes.value` is a Vue reactive *proxy*, and the structured-clone algorithm refuses proxies
 * outright — "could not be cloned", thrown from the middle of drawing. A payload is plain JSON
 * by construction (numbers, strings, a list of points), so a round trip through JSON is both
 * sufficient and immune to what it's wrapped in.
 */
export function snapshot(payload: WhiteboardStrokePayload): WhiteboardStrokePayload {
  return JSON.parse(JSON.stringify(payload))
}

/** A remote person's live, in-progress stroke or cursor — ephemeral, never persisted. */
export interface RemoteCursor { id: number, name: string, x: number, y: number, at: number }
export interface LiveStroke { id: number, name: string, stroke: { kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload } }

export type BoardOp =
  /** A mark I made. Forward: draw it. Back: delete it. */
  | { op: 'add', clientId: string, kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload }
  /** A mark I erased, snapshotted so going back can put it there again. */
  | { op: 'erase', clientId: string, kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload }
  /** A mark I moved, resized or painted — both sides of the change. */
  | { op: 'edit', clientId: string, before: WhiteboardStrokePayload, after: WhiteboardStrokePayload }

/** What one gesture did, in the order it did it. */
export type BoardEntry = BoardOp[]

/**
 * A shared whiteboard: the persistent board (over HTTP + broadcast) and the live layer (over
 * whispers). Surface-agnostic — the caller supplies the board's REST base path and the
 * private stream it lives on, so the exact same machinery drives a side chat's board
 * (`/api/side-chats/{id}/whiteboard`, `sidechat.{id}`) and a channel's
 * (`/api/channels/{id}/whiteboard`, `channel.{id}`).
 *
 * The split is the one threads/typing and the co-op games draw. A *committed* stroke is
 * durable: POSTed, stored, broadcast to everyone, and loaded by anyone who opens the board
 * later. The *in-progress* drag and the moving cursor are worth nothing a moment later, so
 * they ride over whispers straight between subscribers and expire — they never touch
 * Laravel, and a missed one just means a slightly later frame.
 *
 * This adds its own listeners to a stream the surface's message stack already keeps open,
 * and removes only its own on teardown — it never `leave()`s the stream out from under the
 * timeline.
 */
export function useWhiteboard(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  // Held for as long as this view is on screen, so the surface's own message stream
  // leaving the channel can't take our listeners with it. See useEchoStream.
  const { hold, release } = useEchoStream()
  const { user } = useAuth()

  const strokes = ref<WhiteboardStroke[]>([])
  const liveStrokes = ref<Record<number, LiveStroke>>({})
  const cursors = ref<Record<number, RemoteCursor>>({})

  // The subscribed channel object, held so teardown removes *our* handlers from the exact
  // channel we joined — never `echo.private(name)` afresh, which would resurrect a channel
  // the surface's message stream may already have `leave()`d during the same teardown.
  let channel: any = null
  // Separate throttles: while drawing, the live stroke and the cursor are whispered from
  // different call sites and must not starve each other by sharing one clock.
  let lastDrawAt = 0
  let lastCursorAt = 0
  let pruneTimer: ReturnType<typeof setInterval> | undefined

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  async function load() {
    const res = await api<{ data: WhiteboardStroke[] }>(basePath)
    strokes.value = res.data
  }

  /** Commit a stroke: paint it locally at once, then persist + reconcile by client_id. */
  async function addStroke(kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload, clientId: string) {
    const optimistic: WhiteboardStroke = {
      id: -Date.now(), kind, payload, client_id: clientId, user: user.value ?? undefined,
    }
    strokes.value = [...strokes.value, optimistic]
    try {
      const res = await api<{ data: WhiteboardStroke }>(`${basePath}/strokes`, {
        method: 'POST',
        body: { kind, payload, client_id: clientId },
        headers: socketHeaders(),
      })
      const idx = strokes.value.findIndex(s => s.client_id === clientId)
      if (idx !== -1) strokes.value.splice(idx, 1, res.data)
    } catch (e) {
      strokes.value = strokes.value.filter(s => s.client_id !== clientId)
      throw e
    }
  }

  /**
   * Persist a move/resize. The stroke's payload has already been mutated locally for instant
   * feedback; this saves it and reconciles with the server's copy. Optimistic strokes (still
   * awaiting their id) aren't persisted yet, so there's nothing to PATCH.
   */
  async function updateStroke(stroke: WhiteboardStroke) {
    if (stroke.id <= 0) return
    const res = await api<{ data: WhiteboardStroke }>(`${basePath}/strokes/${stroke.id}`, {
      method: 'PATCH',
      body: { payload: stroke.payload },
      headers: socketHeaders(),
    })
    const i = strokes.value.findIndex(s => s.id === stroke.id)
    if (i !== -1) strokes.value.splice(i, 1, res.data)
  }

  async function removeStroke(stroke: WhiteboardStroke) {
    const prev = strokes.value
    strokes.value = strokes.value.filter(s => s.id !== stroke.id)
    // A stroke still awaiting its server id (negative) was never persisted — nothing to DELETE.
    if (stroke.id <= 0) return
    try {
      await api(`${basePath}/strokes/${stroke.id}`, { method: 'DELETE', headers: socketHeaders() })
    } catch (e) {
      strokes.value = prev
      throw e
    }
  }

  async function clear() {
    const prev = strokes.value
    strokes.value = []
    try {
      await api(basePath, { method: 'DELETE', headers: socketHeaders() })
    } catch (e) {
      strokes.value = prev
      throw e
    }
  }

  // --- live layer (whispers) ---

  /** Broadcast the in-progress stroke (or `null` to clear it) to the other subscribers. */
  function whisperLive(stroke: { kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload } | null, force = false) {
    if (!channel || !user.value) return
    const now = Date.now()
    if (!force && now - lastDrawAt < WHISPER_EVERY) return
    lastDrawAt = now

    let payload = stroke?.payload ?? null
    // Keep a live pen preview small: simplify, and cap to the most recent points.
    if (stroke?.kind === 'pen' && payload?.points) {
      let pts = simplify(payload.points, 2.5)
      if (pts.length > LIVE_POINT_CAP) pts = pts.slice(pts.length - LIVE_POINT_CAP)
      payload = { ...payload, points: pts }
    }
    channel.whisper('wb-draw', {
      id: user.value.id,
      name: user.value.name,
      stroke: stroke ? { kind: stroke.kind, payload } : null,
    })
  }

  function whisperCursor(x: number, y: number) {
    if (!channel || !user.value) return
    const now = Date.now()
    if (now - lastCursorAt < WHISPER_EVERY) return
    lastCursorAt = now
    channel.whisper('wb-cursor', { id: user.value.id, name: user.value.name, x, y })
  }

  /** Broadcast the in-progress move/resize of an existing stroke so others see it glide. */
  function whisperMove(strokeId: number, payload: WhiteboardStrokePayload) {
    if (!channel) return
    const now = Date.now()
    if (now - lastDrawAt < WHISPER_EVERY) return
    lastDrawAt = now
    channel.whisper('wb-move', { strokeId, payload })
  }

  function pruneCursors() {
    const cutoff = Date.now() - CURSOR_TTL
    const next: Record<number, RemoteCursor> = {}
    for (const [id, c] of Object.entries(cursors.value)) if (c.at > cutoff) next[Number(id)] = c
    cursors.value = next
  }

  function subscribe() {
    if (!echo) return
    channel = hold(streamName)

    channel
      .listen('.WhiteboardStrokeAdded', (s: WhiteboardStroke) => {
        // De-dupe against our optimistic copy and any re-delivery.
        if (strokes.value.some(x => x.client_id === s.client_id || x.id === s.id)) return
        strokes.value = [...strokes.value, s]
        // Their drag is done — drop any live preview we were showing for them.
        if (s.user?.id != null) removeLive(s.user.id)
      })
      .listen('.WhiteboardStrokeUpdated', (s: WhiteboardStroke) => {
        const idx = strokes.value.findIndex(x => x.id === s.id)
        if (idx !== -1) strokes.value.splice(idx, 1, s)
      })
      .listen('.WhiteboardStrokeRemoved', (p: { id: number }) => {
        strokes.value = strokes.value.filter(x => x.id !== p.id)
      })
      .listen('.WhiteboardCleared', () => { strokes.value = [] })
      .listenForWhisper('wb-move', (m: { strokeId: number, payload: WhiteboardStrokePayload }) => {
        const idx = strokes.value.findIndex(x => x.id === m.strokeId)
        if (idx !== -1) strokes.value.splice(idx, 1, { ...strokes.value[idx]!, payload: m.payload })
      })
      .listenForWhisper('wb-draw', (m: LiveStroke) => {
        if (m.id === user.value?.id) return
        if (!m.stroke) removeLive(m.id)
        else liveStrokes.value = { ...liveStrokes.value, [m.id]: m }
      })
      .listenForWhisper('wb-cursor', (m: RemoteCursor) => {
        if (m.id === user.value?.id) return
        cursors.value = { ...cursors.value, [m.id]: { ...m, at: Date.now() } }
      })

    pruneTimer = setInterval(pruneCursors, 1000)
  }

  function removeLive(userId: number) {
    if (!(userId in liveStrokes.value)) return
    const next = { ...liveStrokes.value }
    delete next[userId]
    liveStrokes.value = next
  }

  function unsubscribe() {
    clearInterval(pruneTimer)
    liveStrokes.value = {}
    cursors.value = {}
    // Drop only our own handlers, on the exact channel object we joined — the message stream
    // still lives on it. (Never re-`private()` here; see the note by `channel`.)
    channel
      ?.stopListening('.WhiteboardStrokeAdded')
      .stopListening('.WhiteboardStrokeUpdated')
      .stopListening('.WhiteboardStrokeRemoved')
      .stopListening('.WhiteboardCleared')
      .stopListeningForWhisper('wb-draw')
      .stopListeningForWhisper('wb-cursor')
      .stopListeningForWhisper('wb-move')
    channel = null
    release(streamName)
  }

  // --- undo / redo ---

  /*
   * A board is shared, so "undo" has to mean something narrower than "put the board back how it
   * was a second ago" — the second ago in question may contain three other people's marks. It
   * means *take back what I just did*, and the way to take something back on a shared surface is
   * to perform its inverse through the very same API, so everyone else sees the correction land
   * exactly as they saw the mistake.
   *
   * Hence a log of my own operations rather than a stack of board snapshots. Each entry is the
   * set of primitives one gesture amounted to — usually one, but the paint bucket on bare board
   * is "add a backdrop, remove the old ones", and undoing half of that would be worse than not
   * undoing it at all.
   *
   * Marks are tracked by `client_id`, never by server id, because that is the only name for a
   * mark that survives the round trip: undo an `add` and the row is deleted, redo it and the
   * server hands back a *different* id. The client id is the drawer's own and we re-use it, so a
   * mark can go and come back any number of times and every later entry still points at it.
   */

  const past = ref<BoardEntry[]>([])
  const future = ref<BoardEntry[]>([])
  /** True while an undo or redo is being applied, so its own writes aren't logged as new ones. */
  let replaying = false

  /**
   * How far back the board remembers. Deep enough to cover any plausible "no, not that" and
   * shallow enough that a long session's log stays a log rather than a second copy of the board.
   */
  const HISTORY_LIMIT = 100

  const canUndo = computed(() => past.value.length > 0)
  const canRedo = computed(() => future.value.length > 0)

  /**
   * Log a gesture. Doing anything new abandons the redo branch — the ordinary rule, and the only
   * honest one: once you've drawn over the thing you undid, there is no "forward" left to go.
   */
  function record(entry: BoardEntry) {
    if (replaying || entry.length === 0) return

    past.value = [...past.value, entry].slice(-HISTORY_LIMIT)
    future.value = []
  }

  function strokeFor(clientId: string): WhiteboardStroke | undefined {
    return strokes.value.find(s => s.client_id === clientId)
  }

  /** Draw a mark and remember that I did. The path every committed stroke should take. */
  async function draw(kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload, clientId = crypto.randomUUID()) {
    await addStroke(kind, payload, clientId)
    record([{ op: 'add', clientId, kind, payload: snapshot(payload) }])
  }

  /** Erase a mark and remember it whole, so undo can put it back rather than approximate it. */
  async function erase(stroke: WhiteboardStroke) {
    // Snapshotted *before* the delete: afterwards there is nothing left to copy.
    const erased: BoardOp = {
      op: 'erase',
      clientId: stroke.client_id,
      kind: stroke.kind,
      payload: snapshot(stroke.payload),
    }
    await removeStroke(stroke)
    record([erased])
  }

  /**
   * Persist a move, resize or recolour and remember both sides of it.
   *
   * `before` has to come from the caller: the payload has already been mutated in place for
   * instant feedback by the time anybody thinks about saving it, so this is the last moment the
   * old geometry still exists anywhere, and it exists only where it was copied.
   */
  async function editStroke(stroke: WhiteboardStroke, before: WhiteboardStrokePayload) {
    const entry: BoardEntry = [{
      op: 'edit',
      clientId: stroke.client_id,
      before: snapshot(before),
      after: snapshot(stroke.payload),
    }]
    await updateStroke(stroke)
    record(entry)
  }

  /** Several primitives that must undo together — the paint bucket on bare board. */
  async function asOneGesture(steps: () => Promise<BoardEntry>) {
    const entry = await steps()
    record(entry)
  }

  /** Put a payload back on a mark that is currently on the board. */
  async function restore(clientId: string, payload: WhiteboardStrokePayload) {
    const stroke = strokeFor(clientId)
    if (!stroke) return

    stroke.payload = snapshot(payload)
    await updateStroke(stroke)
  }

  async function applyOp(op: BoardOp, direction: 'back' | 'forward') {
    if (op.op === 'add') {
      if (direction === 'forward') await addStroke(op.kind, op.payload, op.clientId)
      else {
        const stroke = strokeFor(op.clientId)
        if (stroke) await removeStroke(stroke)
      }

      return
    }

    if (op.op === 'erase') {
      // Going back re-draws it under the *undoer's* name, because re-creating a row is the only
      // way to un-delete one. Erasing somebody else's mark and taking it back therefore leaves
      // it authored by you — which is a fair price for the mark being there again at all, on a
      // board where anybody may erase anything.
      if (direction === 'back') await addStroke(op.kind, op.payload, op.clientId)
      else {
        const stroke = strokeFor(op.clientId)
        if (stroke) await removeStroke(stroke)
      }

      return
    }

    await restore(op.clientId, direction === 'back' ? op.before : op.after)
  }

  /**
   * Take back the last gesture, or put it back.
   *
   * The ops of one gesture are undone in reverse and redone in order, for the reason every
   * transaction log is replayed that way: "add the new backdrop, then remove the old" only
   * un-does correctly as "put the old back, then remove the new".
   *
   * A failed step reloads from the board of record rather than guessing, and the entry is
   * consumed either way — an undo that can't be applied and stays at the top of the stack is an
   * undo the user will press again, to no effect, forever.
   */
  async function stepHistory(direction: 'back' | 'forward') {
    const from = direction === 'back' ? past : future
    const to = direction === 'back' ? future : past
    const entry = from.value.at(-1)
    if (!entry) return

    from.value = from.value.slice(0, -1)
    replaying = true

    try {
      const ops = direction === 'back' ? [...entry].reverse() : entry
      for (const op of ops) await applyOp(op, direction)
      to.value = [...to.value, entry]
    } catch {
      await load()
    } finally {
      replaying = false
    }
  }

  const undo = () => stepHistory('back')
  const redo = () => stepHistory('forward')

  /** Wiping the board for everyone leaves nothing for a personal history to point at. */
  async function clearAll() {
    await clear()
    past.value = []
    future.value = []
  }

  onBeforeUnmount(() => clearInterval(pruneTimer))

  return {
    strokes, liveStrokes, cursors,
    load, addStroke, updateStroke, removeStroke,
    draw, erase, editStroke, asOneGesture, clear: clearAll,
    undo, redo, canUndo, canRedo,
    whisperLive, whisperCursor, whisperMove,
    subscribe, unsubscribe,
  }
}
