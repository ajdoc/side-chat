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
  const token = useAuthToken()
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
    // Before the token goes: revoking needs a valid session to authenticate, and the phone
    // has to stop receiving this account's messages the moment somebody else signs in on it.
    await usePushNotifications().unregister()

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
   * Adopt a session that was minted somewhere other than the login form — a guest walking in
   * through a meeting link.
   *
   * **Sets the user from the response rather than re-fetching it**, which is not an optimisation:
   * `useApi()` captures its own cookie ref when it is created, so a `/auth/me` fired in the same
   * tick as the token being written goes out with no Authorization header at all, 401s, and
   * `fetchUser` dutifully clears the token it was given. That is exactly what login has always
   * avoided by taking the user from its own response — this is the same move, named.
   */
  function setSession(value: string, account: User) {
    token.value = value
    user.value = account
    useTheme().hydrate(account)
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

  /**
   * Save account preferences and keep the local user in step.
   *
   * The local update is the important half: the notification defaults are the bottom of the
   * resolution chain that useNotifyPolicy consults on *every* arriving message, so a saved
   * default that hadn't landed here yet would go on alerting by the old rule until reload.
   */
  async function updatePreferences(payload: Record<string, unknown>) {
    const res = await api<{ data: User }>('/api/preferences', { method: 'PATCH', body: payload })
    user.value = res.data
    return res.data
  }

  return { user, token, isLoggedIn, register, login, logout, fetchUser, setToken, setSession, updateProfile, updatePreferences }
}
