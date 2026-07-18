interface SpotifyStatus {
  linked: boolean
  premium: boolean
  product: string | null
}

// The viewer's Spotify account link, shared app-wide.
//
// Drives whether the music player can use the real Spotify engine (Premium + linked) or
// must fall back to YouTube. Linking runs through a popup (see the spotify-link plugin);
// the SDK later pulls fresh access tokens through `getToken`.
export function useSpotifyAuth() {
  const api = useApi()
  const status = useState<SpotifyStatus>('spotify:status', () => ({ linked: false, premium: false, product: null }))
  const loaded = useState<boolean>('spotify:statusLoaded', () => false)

  async function refreshStatus() {
    try {
      status.value = await api<SpotifyStatus>('/api/spotify/status')
    } catch {
      status.value = { linked: false, premium: false, product: null }
    } finally {
      loaded.value = true
    }
  }

  /** Load status once per session (safe to call from any component's onMounted). */
  async function ensureLoaded() {
    if (!loaded.value) await refreshStatus()
  }

  /** Open the Spotify authorisation popup; resolves once it closes, status refreshed. */
  async function connect(): Promise<boolean> {
    const { url } = await api<{ url: string }>('/api/spotify/connect')
    const popup = window.open(url, 'spotify-link', 'width=480,height=760')

    return new Promise((resolve) => {
      const finish = async (ok: boolean) => {
        window.removeEventListener('message', onMsg)
        clearInterval(poll)
        await refreshStatus()
        resolve(ok)
      }
      const onMsg = (e: MessageEvent) => {
        if (e.origin === window.location.origin && e.data?.type === 'spotify-linked') finish(!!e.data.ok)
      }
      window.addEventListener('message', onMsg)
      // Fallback: the popup was closed (or blocked) without a message.
      const poll = setInterval(() => {
        if (!popup || popup.closed) finish(true)
      }, 800)
    })
  }

  async function disconnect() {
    await api('/api/spotify/disconnect', { method: 'POST' })
    await refreshStatus()
  }

  /** A fresh access token for the Web Playback SDK, or null if the link is gone. */
  async function getToken(): Promise<string | null> {
    try {
      const res = await api<{ access_token: string }>('/api/spotify/token')
      return res.access_token
    } catch {
      return null
    }
  }

  const canUseSpotify = computed(() => status.value.linked && status.value.premium)

  return { status, canUseSpotify, ensureLoaded, refreshStatus, connect, disconnect, getToken }
}
