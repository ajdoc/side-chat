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
      await refresh()
    }
    catch {
      error.value = 'Could not load this space.'
    }
    finally {
      loading.value = false
    }
  }

  /**
   * Read the map again without saying so.
   *
   * The broadcast is only a ping — the map itself is too big for the websocket's frame limit
   * once a room is properly furnished (see SideSpaceMapUpdated). So this is the second half of
   * every remote change, and it deliberately leaves `loading` alone: somebody else saving the
   * editor must not blank out the room you're standing in for the length of a round trip.
   */
  async function refresh() {
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map`)
    map.value = res.data

    return res.data
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
        objects: next.objects,
        spawn: next.spawn,
      },
    })

    map.value = res.data

    return res.data
  }

  /**
   * Save the furniture alone — the member-facing half of the editor.
   *
   * Sends only the objects, against the room's own tiles on the server. That's the whole reason
   * it's a separate endpoint from {@link save}: rebuilding the geometry is owner-only, but any
   * member may rearrange what's standing on it. See the two requests server-side.
   */
  async function saveObjects(objects: SpaceMap['objects']) {
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/objects`, {
      method: 'PUT',
      body: { objects },
    })

    map.value = res.data

    return res.data
  }

  function subscribe() {
    if (!echo) return

    channel = echo.private(`channel.${channelId}`)
    channel.listen('.SideSpaceMapUpdated', (payload: { id: number, updated_at: string | null }) => {
      // Already holding this version: a save through this same composable left `map` at exactly
      // what the server stored, so the ping that follows it has nothing to teach us.
      if (payload.updated_at && map.value?.updated_at === payload.updated_at) return

      // Nothing to do on failure: the map on screen is the last one the server confirmed, which
      // is a better thing to keep drawing than an error.
      refresh().catch(() => {})
    })
  }

  function unsubscribe() {
    // Not echo.leave() — useMessages and useReads share this channel and own tearing it down.
    channel?.stopListening('.SideSpaceMapUpdated')
    channel = null
  }

  return { map, loading, error, load, refresh, save, saveObjects, subscribe, unsubscribe }
}
