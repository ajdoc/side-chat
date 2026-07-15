// On client startup, restore the session from the token cookie (e.g. after a refresh).
export default defineNuxtPlugin(async () => {
  const { token, user, fetchUser } = useAuth()

  if (token.value && !user.value) {
    await fetchUser()
  }
})
