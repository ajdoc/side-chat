import type { SpaceMap } from '~/lib/spaceMapEngine'

/**
 * A Side Space's map: loaded once over HTTP, then kept current over the channel's own stream.
 *
 * Deliberately the *slow* half of the room. Nothing anybody does moment to moment comes through
 * here — walking is whispered peer-to-peer (see {@link useSpacePresence}) and never touches the
 * server. What arrives on this channel is the room being rebuilt, which happens when somebody
 * saves the editor and not otherwise.
 *
 * That rarity is exactly why it's broadcast rather than polled, though: a wall only one person
 * knows about is a wall that only stops that person. Everyone standing in the room has to get
 * the new collision grid, and get it immediately.
 */
export function useSpaceMap(channelId: number) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const map = ref<SpaceMap | null>(null)
  const loading = ref(true)
  const error = ref('')

  // Held so teardown removes our handler from the exact channel object we joined, never a
  // fresh `echo.private(name)` — the channel's message stream lives on this same name, and
  // re-privating it would resurrect a channel somebody else is still tearing down. Same
  // reasoning as useWhiteboard / useSpaceNote.
  let channel: any = null

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map`)
      map.value = res.data
    }
    catch {
      error.value = 'Could not load this space.'
    }
    finally {
      loading.value = false
    }
  }

  /** Save a rebuilt room. Owner only server-side; the caller hides the editor from everyone else. */
  async function save(next: Omit<SpaceMap, 'id' | 'channel_id'>) {
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map`, {
      method: 'PUT',
      body: {
        name: next.name,
        width: next.width,
        height: next.height,
        tiles: next.tiles,
        zones: next.zones,
        spawn: next.spawn,
      },
    })

    map.value = res.data

    return res.data
  }

  function subscribe() {
    if (!echo) return

    channel = echo.private(`channel.${channelId}`)
    channel.listen('.SideSpaceMapUpdated', (payload: SpaceMap) => {
      map.value = payload
    })
  }

  function unsubscribe() {
    // Not echo.leave() — useMessages and useReads share this channel and own tearing it down.
    channel?.stopListening('.SideSpaceMapUpdated')
    channel = null
  }

  return { map, loading, error, load, save, subscribe, unsubscribe }
}
