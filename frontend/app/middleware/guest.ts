// For login/register pages: bounce already-authenticated users onward (honouring
// ?redirect so an invite link isn't lost).
export default defineNuxtRouteMiddleware((to) => {
  const { token } = useAuth()

  if (token.value) {
    const redirect = typeof to.query.redirect === 'string' ? to.query.redirect : '/'
    return navigateTo(redirect)
  }
})
