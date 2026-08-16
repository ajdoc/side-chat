// A $fetch instance pointed at the Laravel API, attaching the Bearer token when present.
export function useApi() {
  const config = useRuntimeConfig()
  const token = useAuthToken()

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers: { Accept: 'application/json' },
    onRequest({ options }) {
      if (token.value) {
        const headers = new Headers(options.headers)
        headers.set('Authorization', `Bearer ${token.value}`)
        options.headers = headers
      }
    },
    /**
     * Getting blocked mid-session.
     *
     * A ban lands on people who are signed in far more often than on people at the login
     * screen (see EnsureNotBanned, server-side), and every request they make from that
     * moment answers 403. Without this the app just breaks quietly in place: rooms stop
     * loading, sends fail, and nothing says why.
     *
     * So the one 403 the client treats specially is the one flagged `banned`. It drops the
     * token and hard-navigates to the login screen carrying the admin's reason, which is the
     * same sentence they'd have got had they tried to sign in a minute later. A hard
     * navigation rather than `navigateTo` for the reason logout uses one: the tab is full of
     * this account's state, and none of it should survive.
     */
    onResponseError({ response }) {
      if (!import.meta.client || response.status !== 403 || !response._data?.banned) return

      token.value = null
      const reason = response._data?.ban_reason ?? ''
      window.location.href = `/login?blocked=${encodeURIComponent(reason)}`
    },
  })
}
