import { useLocalStorage } from '@vueuse/core'
import type { ChannelType } from '~/types'

/**
 * The split view — a second conversation docked beside the one you're standing in.
 *
 * The problem it solves is the one the floating-window shelf doesn't: a floated chat is
 * *on top of* what you're reading, deliberately small and deliberately in the way. Some
 * pairs of channels want to be read side by side at full height — the standup and the
 * incident, the design channel and the room the design is being drawn in — and for those
 * the answer is a second column, not a windowlet.
 *
 * One extra pane, not a tiling grid. Two panes is the shape of the actual task ("this,
 * while I watch that"), and each further split halves the width that made the feature
 * worth having; the shelf is still there for a third thing.
 *
 * What's stored is only which channel is in the right-hand pane and how wide it is. The
 * *left* pane is always the route — split view doesn't own navigation, it borrows the page
 * that was already there, so every link, every back button and every deep link keeps
 * working with no knowledge of any of this.
 */

export interface SplitPane {
  /** Every conversation — server channel, DM, group — is addressed by its channel id. */
  channelId: number
  title: string
  /** What to draw beside the title: `#`, a speaker, or a map pin. */
  type: ChannelType
  /** Where clicking "open fully" should navigate. Held so the pane can hand the route back. */
  path: string
}

export function useSplitView() {
  /**
   * The docked pane, persisted: a split you set up survives a reload, exactly as the
   * sidebar's folds and the shelf's windows do. Null means "not split".
   */
  const pane = useLocalStorage<SplitPane | null>('split:pane', null, {
    serializer: {
      read: (raw) => {
        try {
          return raw ? JSON.parse(raw) as SplitPane : null
        } catch {
          // A half-written or older-shaped value shouldn't wedge the layout closed.
          return null
        }
      },
      write: value => JSON.stringify(value),
    },
  })

  /**
   * How much of the window the docked pane takes, 0–1. Bounded well inside both edges: a
   * pane you can drag to 2% is a pane you can lose, and the divider that would bring it
   * back is under your cursor by a pixel.
   */
  const ratio = useLocalStorage('split:ratio', 0.42)

  const MIN_RATIO = 0.2
  const MAX_RATIO = 0.75

  const isSplit = computed(() => pane.value !== null)

  function openSplit(next: SplitPane) {
    pane.value = next
  }

  function closeSplit() {
    pane.value = null
  }

  /** Set the divider from a page-space x, as a fraction of the *splittable* area. */
  function setRatioFromX(x: number, left: number, width: number) {
    if (width <= 0) return
    const raw = 1 - (x - left) / width
    ratio.value = Math.min(MAX_RATIO, Math.max(MIN_RATIO, raw))
  }

  /**
   * A channel being dragged, as the payload the sidebar writes and the drop zone reads.
   *
   * Goes through the drag event's own dataTransfer rather than a shared ref so that the
   * browser's drag lifecycle — including a drag abandoned outside the window — is the only
   * thing that has to be right. A ref would need an equally correct dragend to clear it.
   */
  const DRAG_TYPE = 'application/x-side-chat-channel'

  function writeDragPayload(event: DragEvent, payload: SplitPane) {
    event.dataTransfer?.setData(DRAG_TYPE, JSON.stringify(payload))
    // A plain-text fallback so dropping onto a text field does something sane rather than
    // pasting "[object Object]".
    event.dataTransfer?.setData('text/plain', payload.title)
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copy'
  }

  function readDragPayload(event: DragEvent): SplitPane | null {
    const raw = event.dataTransfer?.getData(DRAG_TYPE)
    if (!raw) return null
    try {
      return JSON.parse(raw) as SplitPane
    } catch {
      return null
    }
  }

  /** Is this drag one of ours? Used to light the drop zone only for a channel. */
  function isChannelDrag(event: DragEvent) {
    return event.dataTransfer?.types.includes(DRAG_TYPE) ?? false
  }

  return {
    pane,
    ratio,
    isSplit,
    openSplit,
    closeSplit,
    setRatioFromX,
    writeDragPayload,
    readDragPayload,
    isChannelDrag,
  }
}
