/**
 * One shared store per surface, for the Side Desk apps that can now be open in two places at
 * once.
 *
 * ## Why this exists
 *
 * An app used to be one tab, rendered once. Now the same app can be a tab *and* a card on the
 * Open Canvas *and* a popped-out floating window — and the obvious implementation, where each
 * view calls the composable and gets its own `ref`, is quietly broken.
 *
 * Broadcast doesn't save it. Every save goes out with `->toOthers()`, which excludes the
 * *socket* it came from — and two views in one browser tab share a socket. So the other views
 * on this machine are the exact ones the echo never reaches: type in the Calendar tab, and the
 * Calendar card on the canvas beside it sits there stale until a reload. That's the failure the
 * "it must sync" requirement is really about, and no amount of server-side plumbing fixes it,
 * because the server correctly believes it already told us.
 *
 * So views of one app on one surface share *state* rather than each holding a copy. A local
 * mutation is then visible everywhere in this tab by construction, and the broadcast handles
 * every other machine, which is the half it's actually good at.
 *
 * ## Refcounting
 *
 * Sharing state means sharing the subscription too. Two views mounting must not open two
 * broadcast listeners (every remote change applied twice), and — the sharper edge — the *first*
 * view to unmount must not tear the listener out from under the one still on screen. So
 * `attach` counts, and only the transitions matter: 0→1 subscribes, 1→0 tears down.
 *
 * Keyed by `basePath`, which already uniquely names a surface (`/api/channels/12`,
 * `/api/side-chats/3`) — the same key the REST calls hang off.
 */

/**
 * A plain module-level map, deliberately *not* `useState`.
 *
 * `useState` hands back a deep-reactive object, and a `ref` living inside one gets unwrapped on
 * access — `state.events` would come out as the bare array and `state.events.value` as
 * undefined, silently, at runtime. The stores hold refs by design, so they must sit outside
 * anything that reactifies them. Nothing is lost: the app is SPA-only (`ssr: false`), so there
 * is no payload to hydrate this from, and every entry is client-side view state.
 */
const stores = new Map<string, SurfaceEntry<any>>()

interface SurfaceEntry<T> {
  state: T
  /** How many mounted views are holding this store open. */
  refs: number
  /** Teardown for the broadcast subscription, set while `refs > 0`. */
  release: (() => void) | null
}

/**
 * Get (or create) the shared store for one app on one surface.
 *
 * `key` names the app (`'calendar'`, `'notes'`), `basePath` names the surface. `create` builds
 * the state the first time anyone asks and is never called again for that pair, so it's where
 * the reactive refs belong.
 */
export function useSurfaceStore<T extends object>(
  key: string,
  basePath: string,
  create: () => T,
): {
  state: T
  /**
   * Hold the store open for the calling component's lifetime.
   *
   * `subscribe` runs only when the first view arrives and must return its own teardown; both
   * are handled here rather than by callers, because "did I open this, and am I the last one
   * out?" is exactly the bookkeeping every caller would otherwise get subtly wrong.
   */
  attach: (subscribe: () => (() => void) | void) => void
} {
  const id = `${key}:${basePath}`

  if (!stores.has(id)) stores.set(id, { state: create(), refs: 0, release: null })
  const entry = stores.get(id) as SurfaceEntry<T>

  function attach(subscribe: () => (() => void) | void) {
    entry.refs++
    // 0 → 1: this view is the first, so it opens the subscription for everyone.
    if (entry.refs === 1) entry.release = subscribe() ?? null

    onScopeDispose(() => {
      entry.refs--
      // 1 → 0: last one out closes it. Anything above zero still has a view on screen that
      // needs the stream, so leaving it open is the point, not a leak.
      if (entry.refs > 0) return
      entry.release?.()
      entry.release = null
    })
  }

  return { state: entry.state, attach }
}
