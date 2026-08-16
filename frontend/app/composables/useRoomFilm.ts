import type { VideoSource, VideoState, Widget } from '~/types'
import { watchAlongPosition } from '~/lib/watchAlong'

/**
 * What the room is watching, for the map's screens to paint.
 *
 * The Side Space half of the watch-along the {@link file://../components/VideoPlayer.vue video
 * widget} already runs. That widget's whole design is that **the server owns the transport** —
 * playlist, what's on screen, playing or paused, the speed, and the position as of `updated_at`
 * — and every viewer plays their own copy in lockstep. Nothing streams to anybody.
 *
 * So a screen hanging on a map is simply one more viewer. It reads the same shared state and
 * points its own `<video>` at the same file, which is why the cinema screen and everybody's
 * widget stay together without any of them talking to each other.
 *
 * ## Only files can be painted
 *
 * A canvas can copy a frame out of a `<video>` and out of nothing else. The widget plays three
 * kinds of source (see VideoPlayer): a `file` is a real video element and works; `youtube` and
 * `embed` are **cross-origin iframes**, and there is no frame of them to copy — not a limitation
 * of this code but of every renderer that has ever tried. A screen showing a YouTube watch-along
 * therefore stays dark, and the room is told so rather than left wondering.
 */
export function useRoomFilm(channelId: number, enabled: () => boolean) {
  const api = useApi()
  const echo: any = import.meta.client ? useNuxtApp().$echo : null

  const widget = ref<Widget | null>(null)
  const state = computed(() => (widget.value?.state ?? null) as VideoState | null)

  let channel: any = null
  let handler: ((ref: { id: number }) => void) | null = null

  /**
   * The current source, when it's one a canvas can actually paint.
   *
   * Null for YouTube and for provider embeds — see the note above. Null is also what a room with
   * nothing playing looks like, and the two are deliberately distinguished by
   * {@link unpaintable} rather than here, because the *screen* has different things to say about
   * "nothing on" and "something on that I can't show you".
   */
  const film = computed<VideoSource | null>(() => {
    const s = state.value
    if (!s || s.currentIndex == null) return null

    const current = s.playlist?.[s.currentIndex] ?? null

    return current?.kind === 'file' && current.url ? current : null
  })

  /** Something is on, and it is a kind no canvas can copy a frame out of. */
  const unpaintable = computed(() => {
    const s = state.value
    if (!s || s.currentIndex == null) return false

    const current = s.playlist?.[s.currentIndex] ?? null

    return !!current && current.kind !== 'file'
  })

  const playing = computed(() => state.value?.status === 'playing')
  const speed = computed(() => state.value?.speed ?? 1)

  /**
   * Where the film should be *right now*, extrapolated from the server's snapshot.
   *
   * Shared with VideoPlayer rather than reimplemented — see {@link watchAlongPosition} for why
   * that matters more than it looks.
   */
  function targetPosition(): number {
    return watchAlongPosition(state.value)
  }

  async function fetchWidget(id: number) {
    try {
      const res = await api<{ data: Widget }>(`/api/widgets/${id}`)
      if (res.data.id === widget.value?.id) widget.value = res.data
    }
    catch {
      // Gone, or no longer visible. The screen falls back to showing its name, which is the
      // right thing for a room whose player has been taken away.
      widget.value = null
    }
  }

  /**
   * Find the room's player, creating one if the room hasn't got one yet.
   *
   * Only ever called for a map that actually hangs a screen — which is to say, a room somebody
   * built in order to show something. Creating an idle player there is what the room is *for*,
   * and it is the same call the television in the corner makes when anybody presses E on it.
   *
   * Nothing happens in rooms without screens, which is nearly all of them.
   */
  async function load() {
    if (!enabled()) return

    try {
      const res = await api<{ data: Widget }>(`/api/channels/${channelId}/widgets/ensure`, {
        method: 'POST',
        body: { type: 'video' },
      })

      widget.value = res.data
    }
    catch {
      // A room whose player can't be read is a room with a dark screen, which is survivable.
    }
  }

  /**
   * Follow the transport.
   *
   * The same reference-then-fetch dance the timeline does: the event carries an id, not the
   * state, so anybody who cares refetches. Listening on the channel's own stream, which the
   * stage is already subscribed to for the map — hence `stopListening` for our handler alone on
   * teardown rather than `echo.leave`, which would take the map's listener with it.
   */
  function subscribe() {
    // Idempotent: this is re-run whenever the room under your feet changes, and a second
    // listener on the same stream would refetch the widget twice for every transport change.
    if (!echo || !enabled() || handler) return

    handler = (ref: { id: number }) => {
      if (ref.id === widget.value?.id) void fetchWidget(ref.id)
    }

    channel = echo.private(`channel.${channelId}`)
    channel.listen('.WidgetUpdated', handler)
  }

  function unsubscribe() {
    if (channel && handler) channel.stopListening('.WidgetUpdated', handler)
    channel = null
    handler = null
  }

  /** Nothing is playing here any more — walked into a room with no screen, or out of the space. */
  function forget() {
    unsubscribe()
    widget.value = null
  }

  return { widget, film, unpaintable, playing, speed, targetPosition, load, subscribe, unsubscribe, forget }
}
