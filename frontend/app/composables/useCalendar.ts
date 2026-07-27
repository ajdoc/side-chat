import type { CalendarEvent, CalendarEventColor } from '~/types'

/**
 * A Side Desk's shared Calendar — loaded over HTTP, kept in sync over broadcast, and shared
 * between every view of it on this machine.
 *
 * Surface-agnostic like {@link useCanvas} and {@link useWhiteboard}: the caller passes the REST
 * base path and the private stream, so this drives a channel's calendar
 * (`/api/channels/{id}/calendar`, `channel.{id}`) and a side chat's alike.
 *
 * The state lives in a {@link useSurfaceStore}, which is what makes the Calendar *app* and the
 * Calendar *canvas card* one calendar rather than two that agree most of the time — see that
 * file for why broadcast alone can't do it.
 */

/** What a new event defaults to. Exported so the canvas card and the app agree on it. */
export const DEFAULT_EVENT_COLOR: CalendarEventColor = 'primary'

export function useCalendar(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  // Held for as long as this view is on screen, so the surface's own message stream
  // leaving the channel can't take our listeners with it. See useEchoStream.
  const { hold, release } = useEchoStream()

  const { state, attach } = useSurfaceStore('calendar', basePath, () => ({
    events: ref<CalendarEvent[]>([]),
    /** False until the first load lands, so a view can hold a skeleton rather than "no events". */
    loaded: ref(false),
  }))

  const { events, loaded } = state

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** Keep the list in start order — the month grid and the agenda both read it straight. */
  function sort() {
    events.value = [...events.value].sort(
      (a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime(),
    )
  }

  function upsert(event: CalendarEvent) {
    const idx = events.value.findIndex(e => e.id === event.id)
    if (idx === -1) events.value = [...events.value, event]
    else events.value.splice(idx, 1, event)
    sort()
  }

  async function load() {
    const res = await api<{ data: CalendarEvent[] }>(`${basePath}/calendar`)
    events.value = res.data
    loaded.value = true
  }

  async function add(input: {
    title: string
    description?: string | null
    starts_at: string
    ends_at?: string | null
    all_day?: boolean
    color?: CalendarEventColor
  }) {
    const res = await api<{ data: CalendarEvent }>(`${basePath}/calendar`, {
      method: 'POST',
      body: { color: DEFAULT_EVENT_COLOR, ...input },
      headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  /** Persist a partial change, optimistically — dragging to another day saves just the times. */
  async function patch(id: number, changes: Partial<CalendarEvent>) {
    const idx = events.value.findIndex(e => e.id === id)
    if (idx === -1) return
    const prev = events.value[idx]!
    events.value.splice(idx, 1, { ...prev, ...changes })
    sort()
    try {
      const res = await api<{ data: CalendarEvent }>(`${basePath}/calendar/${id}`, {
        method: 'PATCH',
        body: changes,
        headers: socketHeaders(),
      })
      upsert(res.data)
    } catch (e) {
      upsert(prev)
      throw e
    }
  }

  async function remove(id: number) {
    const prev = events.value
    events.value = events.value.filter(e => e.id !== id)
    try {
      await api(`${basePath}/calendar/${id}`, { method: 'DELETE', headers: socketHeaders() })
    } catch (e) {
      events.value = prev
      throw e
    }
  }

  /**
   * Hold the calendar open for this component's life: load it once, listen while anyone is
   * watching, and let go when the last view unmounts. Every view calls this; the refcounting in
   * useSurfaceStore makes the duplicates free.
   */
  function open() {
    attach(() => {
      // Only the first view fetches — later ones find the list already there.
      void load()

      if (!echo) return
      const channel = hold(streamName)
      channel
        .listen('.CalendarEventSaved', (e: CalendarEvent) => upsert(e))
        .listen('.CalendarEventRemoved', (p: { id: number }) => {
          events.value = events.value.filter(e => e.id !== p.id)
        })

      return () => {
        channel.stopListening('.CalendarEventSaved').stopListening('.CalendarEventRemoved')
        release(streamName)
      }
    })
  }

  return { events, loaded, open, load, add, patch, remove }
}
