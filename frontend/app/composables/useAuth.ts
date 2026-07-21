import type { AuthResponse, User } from '~/types'

interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

interface LoginPayload {
  email: string
  password: string
}

export function useAuth() {
  const token = useCookie<string | null>('auth_token', {
    maxAge: 60 * 60 * 24 * 30,
    sameSite: 'lax',
    path: '/',
  })
  const user = useState<User | null>('auth:user', () => null)
  const api = useApi()

  const isLoggedIn = computed(() => !!user.value)

  async function register(payload: RegisterPayload) {
    const res = await api<AuthResponse>('/api/auth/register', { method: 'POST', body: payload })
    token.value = res.token
    user.value = res.user
    useTheme().hydrate(res.user)
  }

  async function login(payload: LoginPayload) {
    const res = await api<AuthResponse>('/api/auth/login', { method: 'POST', body: payload })
    token.value = res.token
    user.value = res.user
    useTheme().hydrate(res.user)
  }

  async function fetchUser() {
    if (!token.value) {
      user.value = null
      return
    }
    try {
      const res = await api<{ data: User }>('/api/auth/me')
      user.value = res.data
      useTheme().hydrate(res.data)
    } catch {
      token.value = null
      user.value = null
    }
  }

  /**
   * Sign out, and leave nothing of this account behind in memory.
   *
   * The hard navigation is the point. Almost everything the app knows — the sidebar's
   * servers and chats, the open server's channels, threads, pins, the music dock, the live
   * websocket subscriptions — lives in `useState`, which is per-*tab*, not per-page. A
   * client-side `navigateTo('/login')` leaves all of it sitting there, so signing in as
   * somebody else lands on the previous account's sidebar: the fetches that would refill it
   * (`fetchServers`, `fetchConversations`) short-circuit when their page counter says
   * "already loaded", and it still says that.
   *
   * Reloading the document is the one reset that can't fall out of step with whatever state
   * the next composable adds — and it drops the old account's data rather than keeping it
   * addressable in the new session.
   */
  async function logout() {
    try {
      await api('/api/auth/logout', { method: 'POST' })
    } catch {
      // ignore — clear locally regardless
    }
    token.value = null
    user.value = null

    // Let the cookie ref flush to document.cookie before the document goes away, or the
    // reloaded app finds a token it no longer has a session for.
    await nextTick()
    window.location.href = '/login'
  }

  function setToken(value: string) {
    token.value = value
  }

  /**
   * Change your display name — the one everyone else sees you by.
   *
   * Only the sidebar and your own menu update on the spot; names already stamped on
   * messages and rosters elsewhere catch up when those views refetch.
   */
  async function updateProfile(payload: { name: string }) {
    const res = await api<{ data: User }>('/api/profile', { method: 'PATCH', body: payload })
    user.value = res.data
    return res.data
  }

  return { user, token, isLoggedIn, register, login, logout, fetchUser, setToken, updateProfile }
}
