import { useLocalStorage } from '@vueuse/core'
import type { Widget } from '~/types'

/**
 * The one music widget that follows you around.
 *
 * A music card is a *message*, so it lives and dies with the timeline it was posted in:
 * change channel, server, DM or group chat and the card unmounts, taking its player — and
 * the sound — with it. Pinning lifts one widget out of that lifecycle. The pinned widget
 * is held here, at the app level, and rendered once by `MusicDock`, which is mounted in the
 * layout and therefore survives every navigation. The card back in the timeline becomes a
 * stub (see WidgetCard) so there is never a second engine playing the same song.
 *
 * Nothing about this is shared: pinning is a *local* view decision, exactly like volume.
 * The transport is still the server's, so a pinned listener stays in lockstep with the room
 * they left behind — which is the whole point. Because the timeline that used to feed the
 * card its updates is gone, we join the widget's own channel stream here and refetch on
 * `.WidgetUpdated`, the same reference-then-fetch dance useMessages does.
 *
 * Only one widget can be pinned at a time — two docked players would be two songs at once.
 */

// Module scope on purpose: the subscription has to outlive every component that touches it,
// and there must be exactly one of it per tab. `handler` is held so teardown removes *our*
// listener and not the timeline's, which listens for the same event on the same channel.
let channel: any = null
let listeningOn: number | null = null
let handler: ((ref: { id: number }) => void) | null = null

export function useMusicPin() {
  const api = useApi()
  // Captured here, in setup, rather than reached for inside the callbacks below: those run
  // from clicks and sockets, where the Nuxt instance isn't guaranteed to be current.
  const echo: any = import.meta.client ? useNuxtApp().$echo : null
  const widget = useState<Widget | null>('music:pinned', () => null)
  // Which widgets this viewer has opted in to hearing ("Listen along"). Global so the opt-in
  // survives the hand-off between the timeline card and the dock — being made to click
  // "Listen along" again every time you pinned would defeat the feature.
  const joinedIds = useState<number[]>('music:joined', () => [])
  // Remembered across reloads: the dock comes back on its own, silent until "Listen along"
  // is clicked (a fresh page gets no autoplay without a gesture, and shouldn't).
  const savedId = import.meta.client ? useLocalStorage<number>('music:pinnedId', 0) : ref(0)

  async function fetchWidget(id: number): Promise<Widget | null> {
    try {
      const res = await api<{ data: Widget }>(`/api/widgets/${id}`)
      return res.data
    } catch {
      return null // gone, or no longer visible to us — the dock just closes
    }
  }

  function listen(channelId: number | null) {
    if (!echo || listeningOn === channelId) return
    if (channel && handler) channel.stopListening('.WidgetUpdated', handler)
    channel = null
    handler = null
    listeningOn = channelId
    if (channelId == null) return
    handler = (ref: { id: number }) => {
      if (ref.id !== widget.value?.id) return
      void fetchWidget(ref.id).then((w) => { if (w && widget.value?.id === w.id) widget.value = w })
    }
    channel = echo.private(`channel.${channelId}`)
    channel.listen('.WidgetUpdated', handler)
  }

  function pin(w: Widget) {
    widget.value = w
    savedId.value = w.id
    listen(w.channel_id)
  }

  function unpin() {
    widget.value = null
    savedId.value = 0
    listen(null)
  }

  const isPinned = (id: number) => widget.value?.id === id
  const toggle = (w: Widget) => (isPinned(w.id) ? unpin() : pin(w))

  /** Re-pin whatever was pinned before a reload. Called once, by the dock. */
  async function restore() {
    if (widget.value || !savedId.value) return
    const w = await fetchWidget(savedId.value)
    if (w) pin(w)
    else savedId.value = 0
  }

  const hasJoined = (id: number) => joinedIds.value.includes(id)
  function markJoined(id: number) {
    if (!hasJoined(id)) joinedIds.value = [...joinedIds.value, id]
  }

  return { widget, pin, unpin, toggle, isPinned, restore, hasJoined, markJoined }
}
