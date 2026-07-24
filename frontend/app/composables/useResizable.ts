import { useLocalStorage } from '@vueuse/core'

/**
 * A draggable panel width that remembers itself.
 *
 * Panels here are fixed-width flex children next to a `flex-1` timeline, so a panel doesn't
 * resize itself — it resizes the space *beside* it. Drag a right-hand panel's left border
 * leftward and it grows while the timeline shrinks; drag the sidebar's right border rightward
 * and the reverse. That asymmetry is the whole reason for `edge`: it decides which way a
 * given pixel of drag counts.
 *
 * The width is clamped to [min, max] on every move and once more on load, so a value saved
 * under an old min/max (or a hand-edited one) can never wedge a panel off-screen.
 *
 * The same logic drives a *height* when `edge` is 'top' or 'bottom' — a Side Space's map is a
 * full-width band above the timeline, and dragging its bottom edge down grows the room and
 * shrinks the conversation. Only two things change: which pointer coordinate is read, and
 * which cursor the body wears while dragging.
 *
 * @param key   storage key, unique per panel (e.g. 'thread')
 * @param edge  which border carries the handle — 'left' for right-hand panels, 'right' for the
 *              sidebar, 'bottom' for a band whose lower edge is dragged
 */
export function useResizable(
  key: string,
  defaultWidth: number,
  opts: { min?: number, max?: number, edge?: 'left' | 'right' | 'top' | 'bottom' } = {},
) {
  const min = opts.min ?? 240
  const max = opts.max ?? 720
  const edge = opts.edge ?? 'left'
  const vertical = edge === 'top' || edge === 'bottom'

  const width = useLocalStorage(`panel-width:${key}`, defaultWidth)
  const clamp = (n: number) => Math.min(max, Math.max(min, n))
  // A stored value from a previous min/max could sit out of range; pull it back in.
  width.value = clamp(width.value)

  function startResize(e: PointerEvent) {
    e.preventDefault()
    const start = vertical ? e.clientY : e.clientX
    const startWidth = width.value
    // Right-edge handle (sidebar) and bottom-edge handle (a band): dragging away from the
    // panel grows it. Left- and top-edge handles: dragging towards it grows it. So the same
    // pixel counts +1 for one pair and -1 for the other.
    const dir = edge === 'right' || edge === 'bottom' ? 1 : -1

    const onMove = (ev: PointerEvent) => {
      width.value = clamp(startWidth + ((vertical ? ev.clientY : ev.clientX) - start) * dir)
    }
    const onUp = () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    // While dragging, keep the resize cursor and stop the pointer selecting text under it.
    document.body.style.cursor = vertical ? 'row-resize' : 'col-resize'
    document.body.style.userSelect = 'none'
  }

  return { width, startResize }
}
