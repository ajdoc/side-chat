/**
 * The management side of rooms and locks — as distinct from the *runtime* side, which arrives
 * inside the map and is what makes doors open.
 *
 * Two different questions, deliberately answered by two different payloads:
 *
 *   - "will this door open for that person" is asked sixty times a second by every browser in
 *     the room, about everybody in it. It rides along with the map (`map.locks`), unfiltered,
 *     because a door that opened on one screen and not another would be a door people walk
 *     through. See lib/spaceDoors.ts.
 *   - "which locks am I entitled to administer" is asked when somebody opens a panel, and the
 *     answer is *scoped to them*: the server's owner sees every lock in the space, a room owner
 *     sees the ones they set, everybody else sees none. That's this.
 *
 * Nothing here is cached across channels — the panel is opened rarely and a stale list of who
 * can get into which room is worse than a fetch.
 */

export interface SpaceLockRow {
  object_id: string
  /** The kind of door, or null once it has been taken out of the map. */
  door: string | null
  /** Whether the door still exists. A stale lock is shown, not hidden — it's the one you'd tidy. */
  present: boolean
  zone_id: string | null
  room: string | null
  created_by: string | null
  /** Whether this is one of mine — the room owner's list is entirely these. */
  mine: boolean
  /** Everybody who may pass, resolved — the explicit keys plus the people who never need one. */
  allowed: Array<{ id: number, name: string | null }>
  /**
   * The keys stored on this lock, and the only thing an edit may send back.
   *
   * Separate from `allowed` on purpose: writing the resolved list back would bake the room's
   * current owners into the row as standing keys, which then survive the room changing hands.
   */
  granted: number[]
  created_at: string
}

export function useSpaceLocks(channelId: number) {
  const api = useApi()

  const locks = ref<SpaceLockRow[]>([])
  /** Whether to offer the Rooms tab at all — the server owner's power, and only theirs. */
  const canManageRooms = ref(false)
  /** The zones whose doors this person may lock. Every zone, for a server owner. */
  const myRooms = ref<string[]>([])
  const loading = ref(false)
  const error = ref('')

  const base = `/api/channels/${channelId}/space`

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const res = await api<{ data: SpaceLockRow[], can_manage_rooms: boolean, my_rooms: string[] }>(`${base}/locks`)
      locks.value = res.data
      canManageRooms.value = res.can_manage_rooms
      myRooms.value = res.my_rooms
    } catch {
      error.value = 'Could not load the locks.'
    } finally {
      loading.value = false
    }
  }

  /**
   * Every write answers with the whole map and broadcasts it, so there is nothing to patch
   * locally: the map stream applies it, the doors change for everybody at once, and this only
   * has to refresh its own scoped list.
   */
  async function lock(objectId: string, allowed: number[]) {
    await api(`${base}/locks/${objectId}`, { method: 'PUT', body: { allowed } })
    await load()
  }

  async function unlock(objectId: string) {
    await api(`${base}/locks/${objectId}`, { method: 'DELETE' })
    await load()
  }

  /**
   * Set who is in charge of a room — the whole set, replacing whatever was there. An empty list
   * takes the room back. Server owner only.
   *
   * Replacing rather than adding is what makes "remove Alice" and "add Bob" the same call, and
   * what stops two people editing the list at once from interleaving into a state neither asked
   * for: the last list written is the list.
   */
  async function assignRoom(zoneId: string, ownerIds: number[]) {
    await api(`${base}/rooms/${zoneId}`, { method: 'PUT', body: { owner_ids: ownerIds } })
    await load()
  }

  return { locks, canManageRooms, myRooms, loading, error, load, lock, unlock, assignRoom }
}
