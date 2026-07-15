// A $fetch instance pointed at the Laravel API, attaching the Bearer token when present.
export function useApi() {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')

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
  })
}
