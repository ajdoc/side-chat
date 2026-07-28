import type { ComputedRef, InjectionKey } from 'vue'

/**
 * Where a channel's side surfaces keep their state — which thread is open, which side chat,
 * which Side Desk app.
 *
 * All of that used to be read straight off `useRoute().query` and written with `navigateTo`,
 * which was exactly right while there was one channel on screen: the URL is shared, linkable
 * and survives a reload, and "which side chat am I in" is a fact about *where you are*.
 *
 * The split view broke the assumption, not the design. Two channels on screen means two sets
 * of that state and only one URL, so `?sidechat=12` would open the same post in both panes
 * and closing it in one would close it in the other. The fix isn't to stop using the URL —
 * it's to stop assuming there's only one place to keep this.
 *
 * So surfaces ask for a *surface route* instead. The page provides none, and they get the
 * real URL, unchanged in every observable way. A docked pane provides its own in-memory one
 * ({@link provideLocalSurfaceRoute}), and its surfaces read and write that instead — same
 * components, same keys, no navigation.
 *
 * The docked pane's state is deliberately *not* linkable or persisted. A split is a way of
 * arranging your window, and the thing you can link to is the channel you're standing in.
 */
export interface SurfaceRoute {
  /**
   * The current state as flat string keys — `sidechat`, `thread`, `desk` and friends.
   *
   * String-valued entries only. Vue Router hands back `string | string[] | null`, and every
   * consumer here has always wanted the first of those; narrowing once, here, is what let
   * the panels drop their own `typeof v === 'string'` filters.
   */
  query: ComputedRef<Record<string, string>>
  /** Merge keys in; `null` removes one. Leaves every other open column standing. */
  patch: (patch: Record<string, string | null>) => void
  /** Replace the lot — "open just this", and with `{}`, "close everything". */
  replace: (query: Record<string, string>) => void
}

export const surfaceRouteKey: InjectionKey<SurfaceRoute> = Symbol('surfaceRoute')

/** Flatten a router query to the string-valued keys the surfaces actually read. */
function flatten(query: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [k, v] of Object.entries(query)) if (typeof v === 'string') out[k] = v
  return out
}

function merged(current: Record<string, string>, patch: Record<string, string | null>) {
  const next = { ...current }
  for (const [k, v] of Object.entries(patch)) {
    if (v === null) delete next[k]
    else next[k] = v
  }
  return next
}

/**
 * The surface route in force here: a pane's own if one is being provided, the browser's URL
 * otherwise.
 *
 * The fallback is what keeps this invisible to the main column. A page that provides nothing
 * behaves exactly as it did when these components read `useRoute()` directly.
 */
export function useSurfaceRoute(): SurfaceRoute {
  const injected = inject(surfaceRouteKey, null)
  if (injected) return injected

  const route = useRoute()
  const query = computed(() => flatten(route.query))

  return {
    query,
    patch: patch => navigateTo({ path: route.path, query: merged(query.value, patch) }),
    replace: next => navigateTo({ path: route.path, query: next }),
  }
}

/**
 * Give everything below this component a surface route of its own, held in memory.
 *
 * Called by the docked pane. Returns the state so the pane can seed it and read it back —
 * it needs to know, for instance, whether any side column is open at all.
 */
export function provideLocalSurfaceRoute(initial: Record<string, string> = {}) {
  const state = ref<Record<string, string>>({ ...initial })
  const query = computed(() => state.value)

  const surface: SurfaceRoute = {
    query,
    patch: patch => { state.value = merged(state.value, patch) },
    replace: (next) => { state.value = { ...next } },
  }

  provide(surfaceRouteKey, surface)

  return { state, ...surface }
}
