import type { GifResult } from '~/types'

/**
 * The GIF picker's data source — trending on open, search as you type. Talks to our own
 * `/api/gifs/*` proxy (Giphy lives behind the backend so the key never reaches the browser).
 *
 * A 422 means the server has no provider key configured; we flip `unconfigured` so the
 * picker can show a note instead of an empty grid (mirrors the Spotify "not configured" path).
 */
export function useGifs() {
  const api = useApi()
  const results = ref<GifResult[]>([])
  // Attribution labels for the configured providers, e.g. ['GIPHY', 'KLIPY'].
  const providers = ref<string[]>([])
  const loading = ref(false)
  const unconfigured = ref(false)

  let seq = 0

  async function run(path: string) {
    const mine = ++seq
    loading.value = true
    try {
      const res = await api<{ data: GifResult[], providers: string[] }>(path)
      if (mine !== seq) return // a newer query superseded this one
      results.value = res.data
      providers.value = res.providers ?? []
      unconfigured.value = false
    }
    catch (err: any) {
      if (mine !== seq) return
      if (err?.response?.status === 422 || err?.statusCode === 422) {
        unconfigured.value = true
        results.value = []
      }
    }
    finally {
      if (mine === seq) loading.value = false
    }
  }

  function featured() {
    return run('/api/gifs/featured')
  }

  function search(query: string) {
    const q = query.trim()
    if (!q) return featured()
    return run(`/api/gifs/search?q=${encodeURIComponent(q)}`)
  }

  return { results, providers, loading, unconfigured, featured, search }
}
