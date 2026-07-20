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
 * @param key   storage key, unique per panel (e.g. 'thread')
 * @param edge  which border carries the handle — 'left' for right-hand panels, 'right' for the sidebar
 */
export function useResizable(
  key: string,
  defaultWidth: number,
  opts: { min?: number, max?: number, edge?: 'left' | 'right' } = {},
) {
  const min = opts.min ?? 240
  const max = opts.max ?? 720
  const edge = opts.edge ?? 'left'

  const width = useLocalStorage(`panel-width:${key}`, defaultWidth)
  const clamp = (n: number) => Math.min(max, Math.max(min, n))
  // A stored value from a previous min/max could sit out of range; pull it back in.
  width.value = clamp(width.value)

  function startResize(e: PointerEvent) {
    e.preventDefault()
    const startX = e.clientX
    const startWidth = width.value
    // Right-edge handle (sidebar): dragging right grows it. Left-edge handle (side panels):
    // dragging left grows it. So a leftward pixel is +1 for one and -1 for the other.
    const dir = edge === 'right' ? 1 : -1

    const onMove = (ev: PointerEvent) => {
      width.value = clamp(startWidth + (ev.clientX - startX) * dir)
    }
    const onUp = () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      document.body.style.cursor = ''
      document.body.style.userSelect = ''
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    // While dragging, keep the col-resize cursor and stop the pointer selecting text under it.
    document.body.style.cursor = 'col-resize'
    document.body.style.userSelect = 'none'
  }

  return { width, startResize }
}
