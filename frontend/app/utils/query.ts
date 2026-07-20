import type { LocationQuery } from 'vue-router'

/**
 * Merge a patch into the current route query instead of replacing it — the panels here live
 * in the URL (`?thread=…&sidechat=…&scthread=…`), and a thread, a side chat and a side chat's
 * own thread can all stand open at once. Replacing the query would close the others; this
 * touches only the keys in `patch` (a `null` value deletes its key) and leaves the rest.
 *
 * Returns a plain string map ready to hand to `navigateTo({ query })`; array-valued params
 * (which these panels never use) are dropped.
 */
export function mergeQuery(
  current: LocationQuery,
  patch: Record<string, string | null>,
): Record<string, string> {
  const q: Record<string, string> = {}
  for (const [k, v] of Object.entries(current)) if (typeof v === 'string') q[k] = v
  for (const [k, v] of Object.entries(patch)) {
    if (v === null) delete q[k]
    else q[k] = v
  }
  return q
}
