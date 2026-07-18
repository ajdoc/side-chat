import type { WhiteboardStroke, WhiteboardStrokeKind, WhiteboardStrokePayload } from '~/types'
import { simplify } from '~/lib/whiteboardEngine'

/** How often the live drag / cursor may whisper — matches the co-op games' ~12Hz peer rate. */
const WHISPER_EVERY = 80
/** Drop a remote cursor we haven't heard from in this long (they moved away or left). */
const CURSOR_TTL = 4000
/** Hard cap on points in a *live preview* whisper, so a long path never nears Reverb's limit. */
const LIVE_POINT_CAP = 300

/** A remote person's live, in-progress stroke or cursor — ephemeral, never persisted. */
export interface RemoteCursor { id: number, name: string, x: number, y: number, at: number }
export interface LiveStroke { id: number, name: string, stroke: { kind: WhiteboardStrokeKind, payload: WhiteboardStrokePayload } }

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
    channel = echo.private(streamName)

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
  }

  onBeforeUnmount(() => clearInterval(pruneTimer))

  return {
    strokes, liveStrokes, cursors,
    load, addStroke, updateStroke, removeStroke, clear,
    whisperLive, whisperCursor, whisperMove,
    subscribe, unsubscribe,
  }
}
