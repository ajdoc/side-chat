import type { Ref } from 'vue'
import type { MusicTrack } from '~/types'
import { parseLrc, plainToLines, type LyricLine } from '~/lib/lrc'

interface LyricsPayload {
  synced: string | null
  plain: string | null
  title: string
  artist: string | null
  instrumental: boolean
}

/**
 * Lyrics for whatever the music widget is currently playing.
 *
 * Follows the given track and keeps one parsed result in hand. The lookup is deliberately
 * lazy — nothing is fetched until karaoke is actually open — because most listeners never
 * open it, and the queue can be long.
 *
 * Results are memoised per track id in a module-level map so toggling the pane, or popping
 * out to the full-screen view, doesn't re-fetch what we already parsed. (The server caches
 * too; this just saves the round trip.)
 */

type Entry
  = { status: 'done', lines: LyricLine[], synced: boolean, instrumental: boolean } | { status: 'none' }

/**
 * One request per track across the whole app. Keyed by track id and holding the *promise*,
 * not the value, so the in-card pane and the full-screen view — which are mounted at the
 * same time — share a single lookup instead of racing two.
 */
const cache = new Map<string, Promise<Entry>>()

function fetchLyrics(api: ReturnType<typeof useApi>, t: MusicTrack): Promise<Entry> {
  const hit = cache.get(t.id)
  if (hit) return hit

  const request = api<{ lyrics: LyricsPayload | null }>('/api/lyrics', {
    query: { title: t.title, artist: t.artist ?? undefined, duration: t.duration ?? undefined },
  }).then(({ lyrics }): Entry => {
    if (!lyrics) return { status: 'none' }
    const lines = lyrics.synced ? parseLrc(lyrics.synced) : []
    // A "synced" document that parsed to nothing is effectively unsynced — fall through to
    // the plain text rather than showing an empty pane.
    if (lines.length) return { status: 'done', lines, synced: true, instrumental: lyrics.instrumental }
    if (lyrics.plain) return { status: 'done', lines: plainToLines(lyrics.plain), synced: false, instrumental: lyrics.instrumental }
    if (lyrics.instrumental) return { status: 'done', lines: [], synced: false, instrumental: true }
    return { status: 'none' }
  }).catch((): Entry => {
    // Offline, rate-limited, whatever — the pane says "no lyrics", the music plays on.
    // Drop the rejection from the cache so opening karaoke again gets a fresh try.
    cache.delete(t.id)
    return { status: 'none' }
  })

  cache.set(t.id, request)
  return request
}

export function useLyrics(track: Ref<MusicTrack | null>, enabled: Ref<boolean>) {
  const api = useApi()
  const entry = ref<Entry | null>(null)
  const loading = ref(false)

  // Keyed on the track *id*, not the track object: the widget's state is replaced wholesale
  // on every broadcast, so the object identity churns even when the song hasn't changed.
  watch(
    [() => track.value?.id ?? null, enabled],
    async ([id, on]) => {
      entry.value = null
      const t = track.value
      if (!on || !id || !t) { loading.value = false; return }

      loading.value = true
      const result = await fetchLyrics(api, t)
      // Only publish if we're still on the track we asked about; skipping past three songs
      // must not paint the first one's lyrics over the third's.
      if (track.value?.id !== t.id) return
      entry.value = result
      loading.value = false
    },
    { immediate: true },
  )

  const lines = computed<LyricLine[]>(() => (entry.value?.status === 'done' ? entry.value.lines : []))
  const synced = computed(() => entry.value?.status === 'done' && entry.value.synced)
  const instrumental = computed(() => entry.value?.status === 'done' && entry.value.instrumental)
  const missing = computed(() => entry.value?.status === 'none')

  return { lines, synced, instrumental, loading, missing }
}
