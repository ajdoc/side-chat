const KEY = 'auth_token'
const MAX_AGE = 60 * 60 * 24 * 30

/**
 * The API token, wherever this shell can actually keep one.
 *
 * On the web a cookie is right: it survives a reload, it's scoped to the origin, and it's
 * what the app has always used. Inside Capacitor and Electron it is *not* — both load the
 * bundle from a synthetic origin (`http://localhost`, `app://`) whose cookie jar the OS
 * feels free to clear between launches, which shows up as the user being silently logged
 * out every few days. `localStorage` in an app WebView has no such eviction policy.
 *
 * Callers get one `Ref<string | null>` and never learn which of the two backed it. The ref
 * is shared per-request via `useState`, so writing it anywhere updates every reader.
 */
export function useAuthToken(): Ref<string | null> {
  const { isNative } = usePlatform()

  // The web path is a plain cookie ref — same object every call, already reactive.
  if (!isNative.value) {
    return useCookie<string | null>(KEY, { maxAge: MAX_AGE, sameSite: 'lax', path: '/' })
  }

  const token = useState<string | null>('auth:token', () => {
    if (typeof window === 'undefined') return null
    return window.localStorage.getItem(KEY)
  })

  // Mirror writes back out. Registered once per state instance, not once per call.
  const bound = useState('auth:token:bound', () => false)
  if (!bound.value && typeof window !== 'undefined') {
    bound.value = true
    watch(token, (value) => {
      if (value) window.localStorage.setItem(KEY, value)
      else window.localStorage.removeItem(KEY)
    })
  }

  return token
}
