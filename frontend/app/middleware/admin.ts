// The admin panel's client-side gate: signed in, and holding a site role.
//
// A convenience, not a security boundary — every /api/admin endpoint checks the role itself
// and 404s without it (EnsureSuperAdmin). What this buys is that somebody who types /admin
// lands somewhere sensible instead of on a page that renders empty and then errors.
//
// It sends non-admins home rather than to the login screen: they *are* signed in, and the
// panel is not something an ordinary account should be told exists.
export default defineNuxtRouteMiddleware(async (to) => {
  const { token, user, fetchUser } = useAuth()

  if (!token.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }

  if (import.meta.client && !user.value) {
    await fetchUser()
  }

  if (!user.value) {
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
  }

  if (user.value.role !== 'super_admin') {
    return navigateTo('/')
  }

  /*
   * Arriving here — by URL, bookmark or the switch — counts as choosing this side, so `/`
   * leads back to the panel rather than out to the app.
   *
   * In the middleware rather than the layout's `onMounted`, because middleware runs only on
   * the way *into* an admin route. A layout hook is tied to mounting, which is a lifecycle
   * the router can run more than once around a transition, and every extra run would be a
   * write of "admin" racing whatever the exit had just set.
   */
  if (import.meta.client) {
    usePanelSide().side.value = 'admin'
  }
})
