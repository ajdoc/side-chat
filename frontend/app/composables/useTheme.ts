import type { ThemeColor, ThemeMode, User } from '~/types'

// Per-user appearance: mode (light/dark/system) x accent color (green/red/blue).
// Backed by useState (reactive, shared), mirrored to cookies for SSR/no-flash, and
// persisted to the user's account when logged in.
export function useTheme() {
  const modeCookie = useCookie<ThemeMode>('theme_mode', { maxAge: 31536000, path: '/', sameSite: 'lax' })
  const colorCookie = useCookie<ThemeColor>('theme_color', { maxAge: 31536000, path: '/', sameSite: 'lax' })

  const mode = useState<ThemeMode>('theme:mode', () => modeCookie.value ?? 'system')
  const color = useState<ThemeColor>('theme:color', () => colorCookie.value ?? 'blue')
  const systemDark = useState<boolean>('theme:systemDark', () => false)

  const isDark = computed(() => mode.value === 'dark' || (mode.value === 'system' && systemDark.value))

  function persist() {
    modeCookie.value = mode.value
    colorCookie.value = color.value

    const token = useCookie('auth_token')
    if (token.value) {
      useApi()('/api/preferences', {
        method: 'PATCH',
        body: { theme_mode: mode.value, theme_color: color.value },
      }).catch(() => { /* keep local pref even if the save fails */ })
    }
  }

  function setMode(m: ThemeMode) {
    mode.value = m
    persist()
  }
  function setColor(c: ThemeColor) {
    color.value = c
    persist()
  }

  // Seed from the logged-in user's saved preferences (called after auth loads).
  function hydrate(user: Pick<User, 'theme_mode' | 'theme_color'> | null) {
    if (!user) return
    if (user.theme_mode) {
      mode.value = user.theme_mode
      modeCookie.value = user.theme_mode
    }
    if (user.theme_color) {
      color.value = user.theme_color
      colorCookie.value = user.theme_color
    }
  }

  return { mode, color, isDark, systemDark, setMode, setColor, hydrate }
}
