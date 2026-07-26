import { useMediaQuery } from '@vueuse/core'

/**
 * Fingers instead of a mouse.
 *
 * A surprising amount of this app is only reachable by hovering — the message action bar, the
 * server's settings chevron, a card's edit/delete buttons. On a touch screen there is no hover
 * state to enter, so those controls are not merely awkward, they don't exist. Anywhere that
 * matters, the hover affordance keeps working for mice and a long-press opens the same thing
 * for fingers.
 *
 * The question asked here is `(pointer: coarse)` rather than "are we inside Capacitor". The two
 * are not the same: a touchscreen laptop and the web app opened on a phone both want the
 * long-press, and neither is a native build. `usePlatform().isMobile` still answers "which shell
 * is this", which is a different question and stays where it is.
 */
export function useCoarsePointer() {
  return useMediaQuery('(pointer: coarse)')
}

export interface LongPressOptions {
  /** How long a finger must rest before it counts. */
  delay?: number
  /** How far it may drift first — past this it was a scroll, not a press. */
  moveTolerance?: number
  /** Only fire for touch/pen, never a held mouse button. */
  coarseOnly?: boolean
}

/**
 * Press-and-hold, as a bag of listeners to spread onto an element.
 *
 * Returned as `v-on="..."` handlers rather than a directive so the caller can see in the
 * template exactly which element grew the gesture — and so it composes with the click handlers
 * already on that element.
 *
 * Two details do the real work. A press that drifts more than `moveTolerance` is abandoned,
 * because on a phone almost every touch on a message list is the start of a scroll. And once
 * the press *has* fired, the `click` that the browser synthesises afterwards is swallowed, so
 * long-pressing a message doesn't also open whatever the message links to.
 */
export function useLongPress(handler: (event: PointerEvent) => void, options: LongPressOptions = {}) {
  const { delay = 450, moveTolerance = 10, coarseOnly = true } = options

  let timer: ReturnType<typeof setTimeout> | undefined
  let origin: { x: number, y: number } | null = null
  let fired = false

  function cancel() {
    clearTimeout(timer)
    timer = undefined
    origin = null
  }

  // Bare event names, not `onPointerdown`. `v-on="handlers"` runs the object through Vue's
  // `toHandlers()`, which prefixes every key with `on` itself — spelling them `onPointerdown`
  // here produced `onOnPointerdown`, and no listener was ever attached.
  return {
    pointerdown(event: PointerEvent) {
      if (coarseOnly && event.pointerType === 'mouse') return

      fired = false
      origin = { x: event.clientX, y: event.clientY }
      timer = setTimeout(() => {
        fired = true
        origin = null
        handler(event)
      }, delay)
    },
    pointermove(event: PointerEvent) {
      if (!origin) return
      if (Math.hypot(event.clientX - origin.x, event.clientY - origin.y) > moveTolerance) cancel()
    },
    pointerup: cancel,
    pointercancel: cancel,
    pointerleave: cancel,
    // Android's WebView raises its own text-selection menu on a long press; iOS raises the
    // callout. Neither is wanted on top of ours.
    contextmenu(event: Event) {
      if (fired) event.preventDefault()
    },
    click(event: Event) {
      if (!fired) return
      fired = false
      event.preventDefault()
      event.stopPropagation()
    },
  }
}
