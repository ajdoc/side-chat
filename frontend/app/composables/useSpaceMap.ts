import type { SpaceMap } from '~/lib/spaceMapEngine'
import { MAIN_MAP } from '~/lib/spaceMapEngine'

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
export function useSpaceMap(channelId: number, startAt = MAIN_MAP) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const map = ref<SpaceMap | null>(null)
  const loading = ref(true)
  const error = ref('')

  /**
   * Which of the channel's rooms this composable is currently looking at.
   *
   * A Side Space is a building: one channel holding an overworld and the interiors behind its
   * doors. Every read and write below carries this, and {@link openMap} is how walking through
   * a door changes it. It is a `ref` rather than an argument because the composable is created
   * once when the room is opened and then lives for as long as somebody is standing in it —
   * they may walk through four doors in that time, and each one must not re-create the echo
   * subscription underneath them.
   *
   * `startAt` is for the callers that are handed a map rather than going and finding one — the
   * editor, above all. It builds its own instance purely to save through, and an editor opened
   * on an interior that defaulted to `main` would write the cinema's grid over the lobby.
   */
  const slug = ref(startAt)

  /** `?map=` for anything but the way in, which every endpoint reads as the default. */
  function query() {
    return slug.value === MAIN_MAP ? '' : `?map=${encodeURIComponent(slug.value)}`
  }

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
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map${query()}`)
    map.value = res.data

    return res.data
  }

  /**
   * Walk into another of this channel's rooms.
   *
   * The whole of what changes when you go through a door: the grid under everyone's feet. No
   * navigation, no `leave()`, no presence channel to rejoin — which is exactly why interiors
   * live inside a channel instead of being channels of their own. The call you are on does not
   * notice this happened.
   *
   * Throws if the room isn't there — a door pointing at a deleted interior 404s, and the caller
   * (the walk) is the one that knows what to do about it: stay where you were.
   */
  async function openMap(next: string) {
    const was = slug.value
    slug.value = next

    try {
      return await refresh()
    }
    catch (e) {
      // Put the composable back where it was, or every later save would be addressed to a room
      // that isn't there while the map on screen is still the old one's.
      slug.value = was
      throw e
    }
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
  const SERVER_OWNED = [
    'id',
    'channel_id',
    // Which room this is, and what its neighbours are called. Both are facts about the
    // *building*, and the building is not what a map save edits — the room being written to is
    // named by `?map=` on the URL, so a slug in the body could only ever disagree with it.
    'slug',
    'siblings',
    'rooms',
    'locks',
    'updated_by',
    'updated_at',
  ] as const

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

    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map${query()}`, {
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
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/objects${query()}`, {
      method: 'PUT',
      body: { objects },
    })

    map.value = res.data

    return res.data
  }

  function subscribe() {
    if (!echo) return

    channel = echo.private(`channel.${channelId}`)
    channel.listen('.SideSpaceMapUpdated', (payload: { id: number, slug?: string, updated_at: string | null }) => {
      // Already holding this version: a save through this same composable left `map` at exactly
      // what the server stored, so the ping that follows it has nothing to teach us.
      if (payload.updated_at && map.value?.updated_at === payload.updated_at) return

      /*
       * News about a different room of the same building.
       *
       * One broadcast channel now serves every map on the channel, so somebody rebuilding the
       * lobby pings people standing in screen one. Without this they would refetch — and get
       * back *their own* room, since `refresh` is addressed by our slug, but a version behind
       * for no reason and once per edit anybody anywhere in the building makes.
       *
       * The exception is a payload with no slug, which is a server that predates interiors: for
       * that, the old behaviour is right.
       */
      if (payload.slug && payload.slug !== slug.value) {
        // Except that the room *list* may have changed — a room added or deleted — and the
        // editor's switcher is drawn from it. Cheap, and only the names travel.
        void refreshSiblings()

        return
      }

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

  /**
   * Re-read just the building's room list, leaving the map on screen alone.
   *
   * Somebody adding or deleting an interior has to reach the switcher and every portal's
   * destination picker on every screen in the building — but must not replace the grid the
   * reader is standing on. So this reads the *main* map purely for its `siblings` and keeps
   * nothing else from it.
   */
  async function refreshSiblings() {
    try {
      const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/map`)

      if (map.value) map.value = { ...map.value, siblings: res.data.siblings }
    }
    catch {
      // The switcher keeps the list it has. A stale room name is a far smaller problem than a
      // failed background fetch taking the room off the screen.
    }
  }

  /**
   * Add an interior — another room to this building.
   *
   * Doesn't open it. Adding a room and walking into it are separate acts, and the editor does
   * both in that order; folding them together would move whoever pressed the button off the map
   * they were in the middle of editing.
   */
  async function createMap(body: {
    slug: string
    name: string
    preset?: string
    /*
     * Which map the new room's way out should lead back to — the one hanging the door. Sent when
     * a doorway *makes* the room it leads to, which is the only moment anything knows both
     * halves. A map and not a tile: where you come out is resolved when you travel, by finding
     * the doorway back. See the stage's arrivalIn.
     */
    return_to?: string
  }) {
    const res = await api<{ data: SpaceMap }>(`/api/channels/${channelId}/space/maps`, {
      method: 'POST',
      body,
    })

    // The new room's own payload carries the updated list, so the switcher is current without a
    // second read — even though we aren't standing in it.
    if (map.value) map.value = { ...map.value, siblings: res.data.siblings }

    return res.data
  }

  /**
   * Hang a picture in one of the map's frames, or replace the one that's there.
   *
   * Multipart, because it carries a file — so no JSON body, and the caller builds the FormData.
   * Staff only server-side. The response is the whole map, so what comes back already has the
   * new picture in `exhibit_pieces` and nothing needs re-reading.
   */
  async function hangExhibit(exhibitId: string, body: FormData) {
    const res = await api<{ data: SpaceMap }>(
      `/api/channels/${channelId}/space/exhibits/${encodeURIComponent(exhibitId)}${query()}`,
      { method: 'POST', body },
    )

    map.value = res.data

    return res.data
  }

  /** Take a picture back down. The frame stays — it is geometry, and this doesn't edit the map. */
  async function unhangExhibit(exhibitId: string) {
    const res = await api<{ data: SpaceMap }>(
      `/api/channels/${channelId}/space/exhibits/${encodeURIComponent(exhibitId)}${query()}`,
      { method: 'DELETE' },
    )

    map.value = res.data

    return res.data
  }

  /** Take a room out of the building. Staff only server-side; refused for the way in. */
  async function deleteMap(which: string) {
    await api(`/api/channels/${channelId}/space/maps/${encodeURIComponent(which)}`, {
      method: 'DELETE',
    })

    // Deleting the room you are standing in is the one case that has to move you, and the only
    // place there is to go is the way in.
    if (slug.value === which) await openMap(MAIN_MAP)
    else await refreshSiblings()
  }

  return {
    map,
    slug,
    loading,
    error,
    load,
    refresh,
    openMap,
    createMap,
    deleteMap,
    hangExhibit,
    unhangExhibit,
    save,
    saveObjects,
    subscribe,
    unsubscribe,
  }
}
