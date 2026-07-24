import type { CanvasItem, CanvasItemKind, Widget } from '~/types'

/**
 * A Side Desk's Open Canvas — the free 2D board of cards, loaded over HTTP and kept in sync
 * over broadcast. Surface-agnostic like {@link useWhiteboard} and {@link useSpaceNote}: the
 * caller passes the REST base path and the private stream, so this drives a side chat's
 * canvas (`/api/side-chats/{id}/canvas`, `sidechat.{id}`) and a channel's alike.
 *
 * Geometry changes (drag, resize) are applied locally at once and persisted on drop, so a
 * drag stays smooth without a request per frame; content edits are debounced by the card.
 * Each save broadcasts to everyone else via `->toOthers()` (keyed by the `X-Socket-ID`
 * header sent here), and the actor skips its own echo. There's no whisper layer in v1 — a
 * card jumps to its final spot for onlookers when the dragger lets go.
 */
export function useCanvas(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const items = ref<CanvasItem[]>([])

  // Held so teardown removes our handlers from the exact channel — see useWhiteboard.
  let channel: any = null

  // Widget cards render live channel-scoped widgets, whose state moves via `WidgetUpdated` on
  // the *channel* stream (a reference — the full state is refetched). That stream may differ
  // from this canvas's surface stream (a side chat's canvas pins its parent channel's widget),
  // so we join each involved channel and listen there. The callback ref is kept so teardown
  // removes *only ours* — the timeline (useMessages) listens for the same event on the same
  // channel object, and a bare stopListening would tear its handler down too.
  const widgetChannels = new Map<number, any>()
  const onWidgetUpdated = (ref: { id: number }) => { void refreshWidget(ref.id) }

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** Pull a widget's fresh state and fold it into every card that renders it. */
  async function refreshWidget(widgetId: number) {
    try {
      const res = await api<{ data: Widget }>(`/api/widgets/${widgetId}`)
      let changed = false
      const next = items.value.map((it) => {
        if (it.widget?.id === widgetId) { changed = true; return { ...it, widget: res.data } }
        return it
      })
      if (changed) items.value = next
    } catch {
      // A transient fetch failure just means a slightly stale card until the next update.
    }
  }

  /** Ensure we're listening for widget updates on every channel a widget card lives on. */
  function syncWidgetStreams() {
    if (!echo) return
    for (const it of items.value) {
      const cid = it.widget?.channel_id
      if (cid == null || widgetChannels.has(cid)) continue
      const ch = echo.private(`channel.${cid}`)
      ch.listen('.WidgetUpdated', onWidgetUpdated)
      widgetChannels.set(cid, ch)
    }
  }

  async function load() {
    const res = await api<{ data: CanvasItem[] }>(`${basePath}/canvas`)
    items.value = res.data
    syncWidgetStreams()
  }

  /** Create a card and drop it on the board. Returns the saved item (with its server id). */
  async function add(kind: CanvasItemKind, content: Record<string, any>, geo: Pick<CanvasItem, 'x' | 'y' | 'w' | 'h'>) {
    const res = await api<{ data: CanvasItem }>(`${basePath}/canvas`, {
      method: 'POST',
      body: { kind, content, ...geo },
      headers: socketHeaders(),
    })
    items.value = [...items.value, res.data]
    syncWidgetStreams() // a fresh widget card may bring a channel we're not yet listening on
    return res.data
  }

  /** Persist a partial change (geometry and/or content), optimistically. */
  async function patch(id: number, changes: Partial<Pick<CanvasItem, 'content' | 'x' | 'y' | 'w' | 'h' | 'z'>>) {
    const idx = items.value.findIndex(i => i.id === id)
    if (idx === -1) return
    const prev = items.value[idx]!
    items.value.splice(idx, 1, { ...prev, ...changes })
    try {
      const res = await api<{ data: CanvasItem }>(`${basePath}/canvas/${id}`, {
        method: 'PATCH',
        body: changes,
        headers: socketHeaders(),
      })
      const i = items.value.findIndex(x => x.id === id)
      if (i !== -1) items.value.splice(i, 1, res.data)
    } catch (e) {
      const i = items.value.findIndex(x => x.id === id)
      if (i !== -1) items.value.splice(i, 1, prev)
      throw e
    }
  }

  async function remove(id: number) {
    const prev = items.value
    items.value = items.value.filter(i => i.id !== id)
    try {
      await api(`${basePath}/canvas/${id}`, { method: 'DELETE', headers: socketHeaders() })
    } catch (e) {
      items.value = prev
      throw e
    }
  }

  /** The next stack order — one above the current top card. */
  function topZ() {
    return items.value.reduce((max, i) => Math.max(max, i.z), 0) + 1
  }

  function subscribe() {
    if (!echo) return
    channel = echo.private(streamName)
    channel
      .listen('.CanvasItemSaved', (it: CanvasItem) => {
        const idx = items.value.findIndex(x => x.id === it.id)
        if (idx === -1) items.value = [...items.value, it]
        else items.value.splice(idx, 1, it)
        syncWidgetStreams() // in case that was a widget card on a new channel
      })
      .listen('.CanvasItemRemoved', (p: { id: number }) => {
        items.value = items.value.filter(x => x.id !== p.id)
      })
  }

  function unsubscribe() {
    channel
      ?.stopListening('.CanvasItemSaved')
      .stopListening('.CanvasItemRemoved')
    channel = null
    // Drop only our WidgetUpdated handler from each channel — the timeline still listens there.
    for (const ch of widgetChannels.values()) ch.stopListening('.WidgetUpdated', onWidgetUpdated)
    widgetChannels.clear()
  }

  return { items, load, add, patch, remove, topZ, subscribe, unsubscribe }
}
