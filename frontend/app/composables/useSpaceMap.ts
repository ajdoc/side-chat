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

  /**
   * Fields the server owns, which must never be echoed back at it.
   *
   * The counterpart to the rule below: everything on the map document is sent *except* these.
   * `id` and `channel_id` say which map this is and are the route's business; `rooms` and `locks`
   * are permissions the map is merely delivered with, and a client that could write them could
   * grant itself a key; `updated_by` and `updated_at` are the server's record of the save it is
   * currently being asked to make.
   */
  const SERVER_OWNED = ['id', 'channel_id', 'rooms', 'locks', 'updated_by', 'updated_at'] as const

  /**
   * Save a rebuilt room. Owner only server-side; the caller hides the editor from everyone else.
   *
   * ## Why this sends everything except a deny list
   *
   * It used to name each field it sent. That is the safer-looking arrangement and it was wrong
   * three times in a row, in exactly the same way each time: a new field was added to the map —
   * `backdrops`, then a rename of it, then `portals` — the editor filled it in, and this function
   * quietly dropped it on the floor. The server reads an absent field as "there are none of
   * those", so every save wiped the thing that had just been added. Nothing errored. The feature
   * simply did not persist, and the cause was thirty lines away from anything that looked wrong.
   *
   * An allow list fails *closed and silently*; a deny list fails *open and harmlessly* — an extra
   * key the server doesn't validate is ignored, while a missing one destroys data. Given that,
   * the default has to be "send it".
   */
  async function save(next: Omit<SpaceMap, 'id' | 'channel_id'>) {
    const body = { ...next } as Record<string, unknown>
    for (const key of SERVER_OWNED) delete body[key]

    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map`, {
      method: 'PUT',
      body,
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
