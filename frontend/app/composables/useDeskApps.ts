import {
  CalendarDays, Columns3, FileText, Film, Flag, Gamepad2, LayoutGrid, Music,
  NotebookPen, Palette, PenTool, Spade, Vote,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { SideDeskAppId, WidgetType } from '~/types'

/**
 * The Side Desk app catalogue, and which of them a surface shows.
 *
 * ## What changed, and why
 *
 * The Side Desk used to be four hard-coded tabs, and the Open Canvas separately knew how to
 * place seven widgets. Those were two lists of the same idea, and the seam showed: a kanban
 * board could be a card on the canvas but never a tab, while the shared notes could be a tab
 * but never a card. This registry collapses both into one list of *apps*, each of which
 * declares where it's allowed to appear.
 *
 * ## The two families
 *
 * - **surface apps** own storage hanging off the surface: the board's strokes, the one shared
 *   note, the doc shelf, the calendar's events, the canvas's cards. They're addressed by
 *   `basePath`, so a channel's board and a side chat's are different boards.
 * - **widget apps** are the interactive widgets promoted to full tabs. They store nothing new.
 *   A tab renders the *channel's* widget of that type — the identical row the timeline card and
 *   the canvas card render.
 *
 * That last point is the whole answer to "the app and the widget must stay in sync": nothing
 * syncs them, because there was only ever one of them. The same is true in reverse for the
 * apps that can be canvas cards — a `calendar` card and the Calendar tab read and write the
 * same endpoints, sharing state through {@link useSurfaceStore}.
 *
 * ## Storage
 *
 * The enabled list is per *surface* and shared by everyone on it (see the migration), because
 * everything else on a Side Desk is shared too — one board, one note, one calendar. A strip
 * that differed per person would make "it's on the Calendar tab" untrue for whoever you said it
 * to. Null from the API means "never customised", and the client fills in {@link DEFAULT_APPS};
 * storing the defaults server-side would freeze them against later releases.
 */

export interface DeskApp {
  id: SideDeskAppId
  label: string
  icon: Component
  /**
   * `surface` apps have their own per-surface storage; `widget` apps render the channel's
   * shared widget of this type. See the class comment.
   */
  family: 'surface' | 'widget'
  /**
   * Can it be removed from the tab strip? Only the Open Canvas can't — it's where you *place*
   * the other apps, so a desk without it has no way back to them.
   */
  removable: boolean
  /** Can it be dropped on the Open Canvas as a card? The canvas obviously can't hold itself. */
  canvasable: boolean
  /** Default card size when placed on the canvas. */
  card?: { w: number, h: number }
  /** Groups the "add an app" picker; games are a shelf you go to on purpose. */
  group: 'workspace' | 'tool' | 'game'
}

/**
 * Every app there is.
 *
 * Order here is the order the picker offers them in — it is *not* the tab order, which each
 * surface owns. Adding an app is one row here plus a branch in SideDesk's renderer (and, for a
 * widget, nothing else at all).
 */
export const DESK_APPS: DeskApp[] = [
  // --- surface apps ---
  { id: 'canvas', label: 'Canvas', icon: LayoutGrid, family: 'surface', removable: false, canvasable: false, group: 'workspace' },
  { id: 'board', label: 'Board', icon: PenTool, family: 'surface', removable: true, canvasable: true, card: { w: 420, h: 320 }, group: 'workspace' },
  { id: 'notes', label: 'Notes', icon: NotebookPen, family: 'surface', removable: true, canvasable: true, card: { w: 320, h: 300 }, group: 'workspace' },
  { id: 'calendar', label: 'Calendar', icon: CalendarDays, family: 'surface', removable: true, canvasable: true, card: { w: 300, h: 340 }, group: 'workspace' },
  // Docs is a file shelf with its own upload flow and viewers; squeezed into a canvas card it's
  // a scrollbar around a scrollbar, so it stays a tab.
  { id: 'docs', label: 'Docs', icon: FileText, family: 'surface', removable: true, canvasable: false, group: 'workspace' },

  // --- widget apps ---
  { id: 'music', label: 'Music', icon: Music, family: 'widget', removable: true, canvasable: true, card: { w: 300, h: 190 }, group: 'tool' },
  // Taller than the rest: the card leads with a 16:9 screen, so a short one clips it.
  { id: 'video', label: 'Video', icon: Film, family: 'widget', removable: true, canvasable: true, card: { w: 400, h: 520 }, group: 'tool' },
  { id: 'kanban', label: 'Kanban', icon: Columns3, family: 'widget', removable: true, canvasable: true, card: { w: 340, h: 320 }, group: 'tool' },
  { id: 'poll', label: 'Poll', icon: Vote, family: 'widget', removable: true, canvasable: true, card: { w: 280, h: 260 }, group: 'tool' },
  { id: 'shooter', label: 'Galaga', icon: Gamepad2, family: 'widget', removable: true, canvasable: true, card: { w: 320, h: 420 }, group: 'game' },
  { id: 'racing', label: 'Racing', icon: Flag, family: 'widget', removable: true, canvasable: true, card: { w: 340, h: 380 }, group: 'game' },
  { id: 'poker', label: 'Poker', icon: Spade, family: 'widget', removable: true, canvasable: true, card: { w: 360, h: 460 }, group: 'game' },
  { id: 'skribbl', label: 'Skribbl', icon: Palette, family: 'widget', removable: true, canvasable: true, card: { w: 360, h: 520 }, group: 'game' },
]

const BY_ID = new Map(DESK_APPS.map(a => [a.id, a]))

export function deskApp(id: SideDeskAppId) {
  return BY_ID.get(id)
}

/**
 * What a Side Desk shows before anyone customises it — exactly the four tabs it had before this
 * existed, so nothing moves for anyone who never opens the picker.
 */
export const DEFAULT_APPS: SideDeskAppId[] = ['canvas', 'board', 'notes', 'docs']

/** The apps that can be placed on the Open Canvas, for its toolbar. */
export const CANVASABLE_APPS = DESK_APPS.filter(a => a.canvasable)

/** True for the widget family — the ids that resolve to a {@link Widget} rather than a surface. */
export function isWidgetApp(id: SideDeskAppId): id is WidgetType {
  return BY_ID.get(id)?.family === 'widget'
}

/**
 * A surface's tab strip: which apps, in which order, shared by everyone on it.
 *
 * Kept in a {@link useSurfaceStore} so the desk panel, a popped-out desk and the side chat's
 * own desk are one strip rather than three that drift — the same reason the calendar is.
 */
export function useDeskAppList(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const { state, attach } = useSurfaceStore('desk-apps', basePath, () => ({
    /** Null until loaded *or* when never customised — either way the defaults render. */
    stored: ref<SideDeskAppId[] | null>(null),
    loaded: ref(false),
  }))

  const { stored, loaded } = state

  /**
   * The strip as rendered.
   *
   * The Canvas is forced in at the front whatever is stored: it can't be removed, and a row
   * that predates that rule (or a hand-written API call) mustn't be able to strand a desk with
   * no way to reach its own cards. Unknown ids are dropped rather than rendered blank — a
   * client that's a release behind shouldn't show a tab it has no component for.
   */
  const apps = computed<SideDeskAppId[]>(() => {
    const list = (stored.value ?? DEFAULT_APPS).filter(id => BY_ID.has(id) && id !== 'canvas')
    return ['canvas', ...list]
  })

  const enabled = computed(() => new Set(apps.value))

  async function load() {
    try {
      const res = await api<{ apps: SideDeskAppId[] | null }>(`${basePath}/desk-apps`)
      stored.value = res.apps
    } catch {
      // A surface we can't read the strip for still gets the defaults, which is a working desk.
    } finally {
      loaded.value = true
    }
  }

  /** Rewrite the whole strip. The array *is* the order, so this covers add, remove and reorder. */
  async function save(next: SideDeskAppId[]) {
    const prev = stored.value
    // Canvas is implicit in the rendered strip and never stored — see `apps`.
    const body = next.filter(id => id !== 'canvas')
    stored.value = body

    try {
      await api(`${basePath}/desk-apps`, {
        method: 'PUT',
        body: { apps: body },
        headers: { 'X-Socket-ID': echo?.socketId() ?? '' },
      })
    } catch (e) {
      stored.value = prev
      throw e
    }
  }

  function toggle(id: SideDeskAppId) {
    const app = BY_ID.get(id)
    if (!app?.removable) return
    return enabled.value.has(id)
      ? save(apps.value.filter(a => a !== id))
      : save([...apps.value, id])
  }

  /** Load once and follow changes for as long as any view of this strip is mounted. */
  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = echo.private(streamName)
      channel.listen('.DeskAppsSaved', (p: { apps: SideDeskAppId[] }) => {
        stored.value = p.apps
      })

      return () => channel.stopListening('.DeskAppsSaved')
    })
  }

  return { apps, enabled, loaded, open, save, toggle }
}
