/**
 * What the first native release is allowed to show.
 *
 * The app builds are chat and voice only. Everything else Side Chat has grown — Side Spaces,
 * the Side Desk, server administration — still ships in the same bundle (there is one bundle),
 * so the boundary has to be drawn at navigation time rather than at build time.
 *
 * Two kinds of thing get turned away here: whole routes, and the query flags that open a
 * full-column panel over a route that is otherwise fine. A blocked route goes home; a blocked
 * panel is quietly stripped, because the user reached it from a button that shouldn't have
 * been there and a redirect would feel like a crash.
 *
 * Remove this file and the `isNative` guards it names to lift the gate.
 */

/** Route paths the native shells may reach, as patterns against `to.path`. */
const ALLOWED = [
  /^\/$/,
  /^\/login$/,
  /^\/register$/,
  /^\/onboarding$/,
  /^\/auth\/callback$/,
  /^\/invite\/[^/]+$/,
  /^\/chats$/,
  /^\/chats\/\d+$/,
  /^\/servers\/\d+$/,
  /^\/servers\/\d+\/channels\/\d+$/,
]

/** Query flags that open a desktop-only panel. Dropped rather than redirected. */
const BLOCKED_QUERY = ['desk']

export default defineNuxtRouteMiddleware((to) => {
  const { isNative } = usePlatform()
  if (!isNative.value) return

  if (!ALLOWED.some(pattern => pattern.test(to.path))) {
    return navigateTo('/')
  }

  const stripped = BLOCKED_QUERY.filter(key => key in to.query)
  if (stripped.length) {
    const query = { ...to.query }
    for (const key of stripped) delete query[key]
    return navigateTo({ path: to.path, query })
  }
})
