import type { SideDeskSurfaceAppId, Widget } from '~/types'

type WidgetType = Widget['type']

/**
 * The floating-window shelf — the app's roaming panels.
 *
 * A timeline card lives and dies with the chat it was posted in; open another channel and it
 * unmounts. Some things you want to keep in front of you *while* you move around: a video the
 * room is watching, a poll you're waiting on, a chat you don't want to lose sight of. Floating
 * one lifts it out of the timeline's lifecycle and into a small window rendered once by
 * {@link FloatingWindows}, which is mounted in the layout and so survives every navigation —
 * the same trick the music dock plays, generalised to any number of windows and three kinds of
 * content: a widget, a conversation, and a Side Desk surface app (the board, the notes…).
 *
 * State lives here at module scope (via `useState`, so it's one list shared by every component
 * and SSR-safe) and is mirrored to localStorage so the shelf comes back after a reload. Music
 * is *not* here: it keeps its own dedicated dock ({@link useMusicPin} / {@link MusicDock}),
 * whose listen-along and autoplay-restore behaviour is bespoke enough that folding it in would
 * cost more than it saves. A floating window is for everything else.
 */

export type FloatingWindowKind = 'widget' | 'conversation' | 'surface'
export type FloatingConversationIcon = 'channel' | 'dm' | 'group'

interface FloatingWindowBase {
  /** Instance id — unique per window, so the same widget can't open twice. */
  id: string
  kind: FloatingWindowKind
  /** Stack order; the focused window carries the highest. */
  z: number
  x: number
  y: number
  w: number
  h: number
  collapsed: boolean
}

export interface FloatingWidgetWindow extends FloatingWindowBase {
  kind: 'widget'
  /** The channel-scoped widget this window renders live. */
  widgetId: number
  /** The channel the widget hangs off — the stream its `.WidgetUpdated` events arrive on. */
  channelId: number
  widgetType: WidgetType
  title: string
}

export interface FloatingConversationWindow extends FloatingWindowBase {
  kind: 'conversation'
  /** Every conversation — server channel, DM, group — is addressed by its channel id. */
  channelId: number
  title: string
  icon: FloatingConversationIcon
}

/**
 * A Side Desk *surface* app — the board, the notes, the calendar, the doc shelf, the canvas —
 * lifted out of the desk panel and into a window that follows you around the app.
 *
 * Unlike a widget, a surface app has no id of its own: it's addressed by the surface it hangs
 * off (`basePath`) plus which app it is, exactly as the desk tab addresses it. That pair is
 * also the window id, so a channel's board and a side chat's are two different windows while
 * the same board can't open twice. All state syncing is already handled a level down, by
 * {@link useSurfaceStore} — the floating copy and the tab beside it are literally one store.
 */
export interface FloatingSurfaceWindow extends FloatingWindowBase {
  kind: 'surface'
  app: SideDeskSurfaceAppId
  /** REST base for the surface, e.g. `/api/channels/12` — what the desk tab is handed. */
  basePath: string
  /** The private stream that surface's changes ride on, e.g. `channel.12`. */
  streamName: string
  /** Whether this person may author here; false renders the app read-only. */
  canEdit: boolean
  title: string
}

export type FloatingWindow = FloatingWidgetWindow | FloatingConversationWindow | FloatingSurfaceWindow

/** What {@link open} takes: the content, minus the geometry the shelf assigns itself. */
export type OpenWidget = Pick<FloatingWidgetWindow, 'kind' | 'widgetId' | 'channelId' | 'widgetType' | 'title'>
  & Partial<Pick<FloatingWidgetWindow, 'w' | 'h'>>
export type OpenConversation = Pick<FloatingConversationWindow, 'kind' | 'channelId' | 'title' | 'icon'>
  & Partial<Pick<FloatingConversationWindow, 'w' | 'h'>>
export type OpenSurface = Pick<FloatingSurfaceWindow, 'kind' | 'app' | 'basePath' | 'streamName' | 'canEdit' | 'title'>
  & Partial<Pick<FloatingSurfaceWindow, 'w' | 'h'>>

const STORAGE_KEY = 'floating:windows'

/** Default size per widget type — the same rough footprints the Open Canvas seats them at. */
const WIDGET_SIZE: Record<string, { w: number, h: number }> = {
  music: { w: 360, h: 420 },
  video: { w: 420, h: 560 },
  kanban: { w: 380, h: 460 },
  poll: { w: 320, h: 380 },
  shooter: { w: 360, h: 520 },
  racing: { w: 380, h: 480 },
  skribbl: { w: 380, h: 560 },
}
const DEFAULT_WIDGET_SIZE = { w: 380, h: 480 }
const DEFAULT_CONVERSATION_SIZE = { w: 360, h: 480 }

/**
 * Surface apps float larger than widgets do. They're workspaces rather than cards — a board you
 * can't draw a shape on and a note showing four words at a time are worse than not floating
 * them — so they open at roughly the width the desk panel gives them.
 */
const SURFACE_SIZE: Record<string, { w: number, h: number }> = {
  board: { w: 640, h: 480 },
  notes: { w: 520, h: 520 },
  calendar: { w: 560, h: 520 },
  docs: { w: 460, h: 520 },
  canvas: { w: 680, h: 520 },
}
const DEFAULT_SURFACE_SIZE = { w: 560, h: 480 }

export function useFloatingWindows() {
  const windows = useState<FloatingWindow[]>(STORAGE_KEY, () => [])

  /** Save the shelf, geometry and all, so it comes back after a reload. Client-only. */
  function persist() {
    if (!import.meta.client) return
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(windows.value))
    } catch {
      // A full or disabled localStorage just means the shelf won't survive a reload.
    }
  }

  /** Re-seat the shelf from localStorage. Called once by the container on mount. */
  function hydrate() {
    if (!import.meta.client || windows.value.length) return
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw) windows.value = JSON.parse(raw)
    } catch {
      windows.value = []
    }
  }

  function topZ(): number {
    return windows.value.reduce((max, w) => Math.max(max, w.z), 0)
  }

  /**
   * Where a fresh window lands: anchored to the **bottom** of the screen (bottom-right), each new
   * one stepped up-and-left off the last so their title bars stay reachable rather than stacking
   * dead on top of one another. Clamped to stay on-screen.
   */
  function nextCorner(w: number, h: number): { x: number, y: number } {
    const gap = 16
    const step = 32
    const offset = (windows.value.length % 6) * step
    const vw = import.meta.client ? window.innerWidth : 1280
    const vh = import.meta.client ? window.innerHeight : 800
    const x = Math.max(8, Math.min(vw - w - gap, vw - w - gap - offset))
    const y = Math.max(8, Math.min(vh - h - gap, vh - h - gap - offset))
    return { x, y }
  }

  /** Bring a window to the front. */
  function focus(id: string) {
    const win = windows.value.find(w => w.id === id)
    if (!win || win.z === topZ()) return
    win.z = topZ() + 1
    persist()
  }

  function open(spec: OpenWidget): FloatingWidgetWindow
  function open(spec: OpenConversation): FloatingConversationWindow
  function open(spec: OpenSurface): FloatingSurfaceWindow
  function open(spec: OpenWidget | OpenConversation | OpenSurface): FloatingWindow {
    // A widget floats at most once (its state is shared anyway); a conversation likewise, and a
    // surface app once per surface. Reopen just brings the existing window forward rather than
    // stacking a duplicate on top of it.
    const id = spec.kind === 'widget'
      ? `widget:${spec.widgetId}`
      : spec.kind === 'surface'
        ? `surface:${spec.basePath}:${spec.app}`
        : `conversation:${spec.channelId}`
    const existing = windows.value.find(w => w.id === id)
    if (existing) {
      existing.collapsed = false
      focus(id)
      return existing
    }

    // Only one song at a time — a second docked music player would be a second engine playing
    // over the first, the very thing the pin was built to avoid. Floating a new one evicts the
    // old (useMusicPin has already handed the pin to the new widget by the time we're here).
    if (spec.kind === 'widget' && spec.widgetType === 'music') {
      windows.value = windows.value.filter(w => !(w.kind === 'widget' && w.widgetType === 'music'))
    }

    const size = spec.kind === 'widget'
      ? (WIDGET_SIZE[spec.widgetType] ?? DEFAULT_WIDGET_SIZE)
      : spec.kind === 'surface'
        ? (SURFACE_SIZE[spec.app] ?? DEFAULT_SURFACE_SIZE)
        : DEFAULT_CONVERSATION_SIZE
    // Never open wider or taller than the window itself: the workspace apps ask for 640×480,
    // which is most of a phone and more than some of it. Clamped here rather than at the frame,
    // so the saved geometry is honest about the size it actually got.
    const vw = import.meta.client ? window.innerWidth : 1280
    const vh = import.meta.client ? window.innerHeight : 800
    const w = Math.min(spec.w ?? size.w, vw - 16)
    const h = Math.min(spec.h ?? size.h, vh - 16)
    const { x, y } = nextCorner(w, h)

    const win = {
      ...spec,
      id,
      z: topZ() + 1,
      x,
      y,
      w,
      h,
      collapsed: false,
    } as FloatingWindow

    windows.value = [...windows.value, win]
    persist()
    return win
  }

  function close(id: string) {
    const going = windows.value.find(w => w.id === id)
    windows.value = windows.value.filter(w => w.id !== id)
    persist()
    // Closing the music window is the gesture that means "stop the music" — the ✕ the old
    // dock had. The pin has to go with it, or the shelf would come back on the next reload
    // pointing at a widget nothing is pinned to. `clear` deliberately doesn't call back into
    // close(), so unpin → close → clear terminates.
    if (going?.kind === 'widget' && going.widgetType === 'music') useMusicPin().clear()
  }

  /** Patch geometry / collapsed state. Callers persist explicitly (e.g. on drag end). */
  function update(id: string, patch: Partial<Pick<FloatingWindow, 'x' | 'y' | 'w' | 'h' | 'collapsed'>>) {
    const win = windows.value.find(w => w.id === id)
    if (win) Object.assign(win, patch)
  }

  const isWidgetFloating = (widgetId: number) => windows.value.some(w => w.kind === 'widget' && w.widgetId === widgetId)
  const isConversationFloating = (channelId: number) => windows.value.some(w => w.kind === 'conversation' && w.channelId === channelId)
  const isSurfaceFloating = (basePath: string, app: string) =>
    windows.value.some(w => w.kind === 'surface' && w.basePath === basePath && w.app === app)

  return { windows, hydrate, persist, open, close, update, focus, isWidgetFloating, isConversationFloating, isSurfaceFloating }
}
