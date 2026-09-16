import { APP_SHELL_TOUR, visibleSteps, type Tour, type TourStep } from '~/lib/tour'

/**
 * The guided tours, as state.
 *
 * One tour can be running at a time and it belongs to the whole window, so the state is
 * module-level rather than per-caller: the account menu starts them, a channel auto-offers
 * one, the overlay draws whichever is up, and none of them needs to own it.
 *
 * "Seen" is a cookie *per tour*, for the same reason `useOnboarding` uses one — it decides
 * what you're shown on load, it must survive a reload, and the backend has no opinion about
 * it. There is one per tour rather than one flag for all of them because they're offered at
 * different moments: taking the tour of the shell on day one shouldn't cost you the tour of
 * a Side Space the first time you walk into one, months later.
 *
 * Written when a tour *ends*, however it ends: somebody who skips has been asked once, and
 * asking again on the next visit is nagging rather than helping.
 */
const steps = ref<TourStep[]>([])
const index = ref(0)
const running = ref<Tour | null>(null)
const active = computed(() => steps.value.length > 0)
const step = computed<TourStep | null>(() => steps.value[index.value] ?? null)

export function tourTarget(name: string): HTMLElement | null {
  if (typeof document === 'undefined') return null
  const el = document.querySelector<HTMLElement>(`[data-tour="${name}"]`)
  // An element inside a collapsed drawer or an unmounted pane has no box at all. Treating it
  // as absent is what lets `visibleSteps` quietly skip it instead of spotlighting a dot.
  if (!el || !el.getClientRects().length) return null
  return el
}

/**
 * Every visible element carrying the hook — for a step spotlighting a set rather than a thing.
 *
 * The sidebar is a virtual scroller, so this is the rows currently *rendered*, which is also
 * the rows the user can see. That's the right answer: the spotlight can only sensibly cover
 * what is on screen.
 */
export function tourTargets(name: string): HTMLElement[] {
  if (typeof document === 'undefined') return []
  return [...document.querySelectorAll<HTMLElement>(`[data-tour="${name}"]`)]
    .filter(el => el.getClientRects().length > 0)
}

function seenCookie(id: string) {
  return useCookie<boolean>(`tour_seen_${id}`, {
    maxAge: 60 * 60 * 24 * 365,
    sameSite: 'lax',
    path: '/',
    default: () => false,
  })
}

export function useTour() {
  /** Whether this tour has anything to show on the screen as it stands. */
  function canRun(tour: Tour) {
    return visibleSteps(tour.steps, tourTarget).length > 0
  }

  /** Begin a tour, over whichever of its steps the current screen can actually show. */
  function start(tour: Tour = APP_SHELL_TOUR) {
    const resolved = visibleSteps(tour.steps, tourTarget)
    if (!resolved.length) return
    running.value = tour
    steps.value = resolved
    index.value = 0
  }

  /**
   * Offer a tour to somebody who has never taken it.
   *
   * Deferred two frames past the call: the thing being toured has usually just mounted — a
   * call that has only this moment connected, a map still painting its first frame — and
   * measuring immediately would find half the targets missing and quietly hand out a
   * two-step tour. A tour already running is never interrupted; whatever the user is being
   * shown now outranks what we'd like to show them next.
   */
  function startOnce(tour: Tour = APP_SHELL_TOUR) {
    if (seenCookie(tour.id).value || active.value) return
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (!active.value) start(tour)
    }))
  }

  function next() {
    if (index.value >= steps.value.length - 1) return end()
    index.value++
  }

  function back() {
    if (index.value > 0) index.value--
  }

  function end() {
    if (running.value) seenCookie(running.value.id).value = true
    running.value = null
    steps.value = []
    index.value = 0
  }

  /** Replay from the account menu: an explicit ask beats the remembered "already seen". */
  function restart(tour: Tour = APP_SHELL_TOUR) {
    end()
    start(tour)
  }

  return { active, step, steps, index, running, canRun, start, startOnce, next, back, end, restart }
}
