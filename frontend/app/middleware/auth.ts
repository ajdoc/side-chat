// Protects a route: requires a token, and validates it (client-side) by loading the user.
// Preserves where the user was heading (e.g. an invite link) so login can return there.
export default defineNuxtRouteMiddleware(async (to) => {
  const { token, user, fetchUser } = useAuth()

  const toLogin = () => navigateTo({
    path: '/login',
    query: to.fullPath && to.fullPath !== '/' ? { redirect: to.fullPath } : {},
  })

  if (!token.value) {
    return toLogin()
  }

  if (import.meta.client && !user.value) {
    await fetchUser()
    if (!user.value) {
      return toLogin()
    }
  }
})
