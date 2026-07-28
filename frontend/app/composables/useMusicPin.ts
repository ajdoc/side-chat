import { useLocalStorage } from '@vueuse/core'
import type { Widget } from '~/types'

/**
 * The one music widget that follows you around.
 *
 * A music card is a *message*, so it lives and dies with the timeline it was posted in:
 * change channel, server, DM or group chat and the card unmounts, taking its player — and
 * the sound — with it. Pinning lifts one widget out of that lifecycle. The pinned widget
 * is held here, at the app level, and rendered once as a window on the floating shelf
 * (`FloatingMusicContent` inside `FloatingWindows`), which is mounted in the layout and
 * therefore survives every navigation. Pinning opens that window and unpinning closes it (see
 * pin/unpin below). The card back in the timeline becomes a stub (see WidgetCard) so there is
 * never a second engine playing the same song.
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
  // The dock outlives every timeline, so it keeps its own hold on the widget's stream.
  const { hold, release } = useEchoStream()
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

  /**
   * Listen on the pinned widget's channel — and *hold* that channel open.
   *
   * The hold is what lets the dock outlive the timeline it was pinned from. `echo.leave` takes
   * a whole subscription with it, so for a long time changing channel struck the dock deaf and
   * it had to be handed its listener back afterwards. Counting the holders removes the need for
   * that dance entirely: the timeline's release simply isn't the last one. See useEchoStream.
   */
  function listen(channelId: number | null) {
    if (!echo || listeningOn === channelId) return
    if (channel && handler) channel.stopListening('.WidgetUpdated', handler)
    if (listeningOn != null) release(`channel.${listeningOn}`)
    channel = null
    handler = null
    listeningOn = channelId
    if (channelId == null) return
    handler = (ref: { id: number }) => {
      if (ref.id !== widget.value?.id) return
      void fetchWidget(ref.id).then((w) => { if (w && widget.value?.id === w.id) widget.value = w })
    }
    channel = hold(`channel.${channelId}`)
    channel.listen('.WidgetUpdated', handler)
  }

  const floating = useFloatingWindows()

  function pin(w: Widget) {
    widget.value = w
    savedId.value = w.id
    listen(w.channel_id)
    // The pinned player now lives as a window on the floating shelf ({@link FloatingMusicContent}),
    // rendered at the app level so navigation can't unmount it — which is the whole point of the
    // pin: the sound follows you across channels, DMs, groups and servers. Music keeps its own
    // brain here (this composable) rather than the generic widget path, because its Spotify /
    // YouTube / listen-along machinery is bespoke; the shelf just gives it a frame.
    floating.open({ kind: 'widget', widgetType: 'music', widgetId: w.id, channelId: w.channel_id, title: 'Music' })
  }

  /**
   * Drop the pin itself, without touching the shelf.
   *
   * Split out from {@link unpin} because the two directions have to meet in the middle: the
   * pin is cleared when the *window* goes (the shelf calls this from `close`), and the window
   * is closed when the *pin* goes. Keeping the shelf-facing half free of `floating.close` is
   * what stops that from being a loop.
   */
  function clear() {
    widget.value = null
    savedId.value = 0
    listen(null)
  }

  function unpin() {
    const id = widget.value?.id
    clear()
    if (id != null) floating.close(`widget:${id}`)
  }

  /** Pull the pinned widget's current state. Also the recovery path after a lost listener. */
  async function refresh() {
    const id = widget.value?.id
    if (id == null) return
    const w = await fetchWidget(id)
    if (w && widget.value?.id === w.id) widget.value = w
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

  return { widget, pin, unpin, clear, toggle, isPinned, restore, refresh, hasJoined, markJoined }
}
