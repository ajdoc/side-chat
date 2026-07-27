/**
 * Who still wants a private Echo channel open.
 *
 * `echo.leave(name)` is all-or-nothing: it tears down the whole subscription, every listener
 * on it, no matter who put them there. That's fine while a channel stream has exactly one
 * owner — and for a long time `channel.{id}` did, namely the timeline. It doesn't any more. A
 * floating widget window, a floating conversation, a floating board and the pinned music player
 * all listen on the very same stream, and all of them outlive the timeline on purpose: the
 * point of floating something is that it follows you when you walk away from the channel it
 * came from.
 *
 * So walking away used to break them. `useMessages.unsubscribe` left the channel, Echo dropped
 * the socket subscription, and the floating window went deaf — still on screen, still showing
 * the state it had, silently no longer live. Which reads as "the kanban board stopped updating
 * until I go back to its channel", because that is exactly what it was.
 *
 * The fix is the same ref count {@link useSurfaceStore} keeps, one level down: hold a stream
 * while you need it, release it when you don't, and only the *last* release actually leaves.
 * Callers still add and remove their own listeners — this owns the subscription's lifetime, not
 * its contents.
 *
 * Module scope, and client-only: a hold has to outlive the component that took it (that's the
 * whole point), and there is exactly one Echo per tab.
 */

const holds = new Map<string, number>()

export function useEchoStream() {
  const echo: any = import.meta.client ? useNuxtApp().$echo : null

  /**
   * Join `name` if nobody has yet, and count this caller among the ones keeping it open.
   * Returns the Echo channel so the caller can attach its listeners to it.
   */
  function hold(name: string): any {
    if (!echo) return null

    holds.set(name, (holds.get(name) ?? 0) + 1)

    return echo.private(name)
  }

  /**
   * Give up this caller's hold. The subscription goes only when the count reaches zero —
   * anything above it is somebody still on screen who needs to hear.
   */
  function release(name: string): void {
    if (!echo) return

    const count = (holds.get(name) ?? 0) - 1

    if (count > 0) {
      holds.set(name, count)

      return
    }

    holds.delete(name)
    echo.leave(name)
  }

  /**
   * Hold for the calling component's lifetime, releasing on scope dispose.
   *
   * The shape almost every caller wants: subscribe in `onMounted`, and never think about the
   * teardown again. `attach` is handed the channel and may return nothing — its listeners come
   * off with the subscription when the last holder leaves, and until then they are supposed to
   * still be there.
   */
  function holdWhileMounted(name: string, attach: (channel: any) => void): void {
    const channel = hold(name)

    if (channel) attach(channel)

    onScopeDispose(() => release(name))
  }

  return { hold, release, holdWhileMounted }
}
