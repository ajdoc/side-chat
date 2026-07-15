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

  async function logout() {
    try {
      await api('/api/auth/logout', { method: 'POST' })
    } catch {
      // ignore — clear locally regardless
    }
    token.value = null
    user.value = null
    await navigateTo('/login')
  }

  function setToken(value: string) {
    token.value = value
  }

  return { user, token, isLoggedIn, register, login, logout, fetchUser, setToken }
}
