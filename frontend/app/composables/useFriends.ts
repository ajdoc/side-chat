import type { Friendship, FriendshipEvent, User } from '~/types'

/**
 * Your friends, what's outstanding either way, and who you've blocked.
 *
 * Shared state rather than per-call refs, for the same reason useJoinRequests is: the badge
 * on the sidebar and the list on the page are two views of one number, and two copies of it
 * will disagree the moment a request arrives while the page is open.
 *
 * The live half is the interesting one. A friend request has to land on whatever screen the
 * recipient happens to be on — they aren't subscribed to you and there's no shared room to
 * put it in — so it comes down the personal `user.{id}` stream, the same road a DM and a
 * ringing phone use. See useUserStream, which owns that subscription and calls in here.
 */
export function useFriends() {
  const api = useApi()
  const { user } = useAuth()

  const friends = useState<User[]>('friends', () => [])
  const pending = useState<Friendship[]>('friends:pending', () => [])
  const blocked = useState<Friendship[]>('friends:blocked', () => [])
  const loaded = useState<boolean>('friends:loaded', () => false)
  const loading = ref(false)

  /** What the sidebar badge counts: requests waiting on *you*, not ones you sent. */
  const incoming = computed(() => pending.value.filter(f => f.direction === 'incoming'))
  const outgoing = computed(() => pending.value.filter(f => f.direction === 'outgoing'))

  async function load(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    try {
      const [f, p, b] = await Promise.all([
        api<{ data: User[] }>('/api/friends'),
        api<{ data: Friendship[] }>('/api/friends/requests'),
        api<{ data: Friendship[] }>('/api/friends/blocked'),
      ])
      friends.value = f.data
      pending.value = p.data
      blocked.value = b.data
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /**
   * Ask someone to be friends. Comes back accepted if they'd already asked you — the
   * server treats two crossing requests as a yes rather than as a second row.
   */
  async function add(target: { user_id: number } | { name: string }) {
    const res = await api<{ data: Friendship }>('/api/friends', { method: 'POST', body: target })
    apply(res.data)

    return res.data
  }

  async function accept(friendship: Friendship) {
    const res = await api<{ data: Friendship }>(`/api/friends/${friendship.id}/accept`, { method: 'POST' })
    apply(res.data)

    return res.data
  }

  async function decline(friendship: Friendship) {
    await api(`/api/friends/${friendship.id}/decline`, { method: 'POST' })
    forget(friendship.id)
  }

  /** Unfriend, or take back a request. One endpoint, because it's one row either way. */
  async function remove(friendship: Friendship) {
    await api(`/api/friends/${friendship.id}`, { method: 'DELETE' })
    forget(friendship.id)
  }

  /** The button next to a face, where there's a person to hand but no row. */
  async function removeUser(userId: number) {
    await api(`/api/friends/user/${userId}`, { method: 'DELETE' })
    friends.value = friends.value.filter(u => u.id !== userId)
    pending.value = pending.value.filter(f => f.user.id !== userId)
  }

  async function block(userId: number) {
    const res = await api<{ data: Friendship }>('/api/friends/block', {
      method: 'POST',
      body: { user_id: userId },
    })
    apply(res.data)

    return res.data
  }

  async function unblock(userId: number) {
    await api(`/api/friends/block/${userId}`, { method: 'DELETE' })
    blocked.value = blocked.value.filter(f => f.user.id !== userId)
  }

  function isFriend(userId: number) {
    return friends.value.some(u => u.id === userId)
  }

  /** The tie you have with this person, whatever state it's in — or null for strangers. */
  function friendshipWith(userId: number) {
    return [...pending.value, ...blocked.value].find(f => f.user.id === userId) ?? null
  }

  /**
   * Put a friendship into the right list and out of the wrong ones.
   *
   * Every list here is a slice of one table, so a status change is always a move: accepting
   * takes a row out of Pending and puts a person into Friends. Doing that in one place is
   * what keeps a live event and a button press from producing different screens.
   */
  function apply(friendship: Friendship) {
    forget(friendship.id)

    if (friendship.status === 'accepted') {
      if (!friends.value.some(u => u.id === friendship.user.id)) {
        friends.value = [...friends.value, friendship.user].sort((a, b) => a.name.localeCompare(b.name))
      }
      return
    }

    friends.value = friends.value.filter(u => u.id !== friendship.user.id)

    if (friendship.status === 'pending') pending.value = [friendship, ...pending.value]
    // Only the blocker has a Blocked list to show; being blocked isn't something you're told.
    else if (friendship.direction === 'outgoing') blocked.value = [friendship, ...blocked.value]
  }

  /** Drop a row from every list. The person may stay in Friends — see apply. */
  function forget(id: number) {
    pending.value = pending.value.filter(f => f.id !== id)
    blocked.value = blocked.value.filter(f => f.id !== id)
  }

  /**
   * Turn a broadcast into this viewer's version of it.
   *
   * The event carries both people because it goes to both of them and an event has one
   * body — so picking "the other one", and which way round the request went, is the
   * client's job. It's the same resolution FriendshipResource does server-side.
   */
  function applyEvent(event: FriendshipEvent) {
    const me = user.value?.id
    if (!me) return

    const outgoing = event.requester_id === me

    apply({
      id: event.id,
      status: event.status,
      direction: outgoing ? 'outgoing' : 'incoming',
      user: outgoing ? event.addressee : event.requester,
      created_at: event.created_at,
      updated_at: event.updated_at,
    })
  }

  /** Someone declined, cancelled, unfriended or unblocked. Either way: it's gone. */
  function removeEvent(event: { id: number, requester_id: number, addressee_id: number }) {
    const me = user.value?.id
    const otherId = event.requester_id === me ? event.addressee_id : event.requester_id

    forget(event.id)
    friends.value = friends.value.filter(u => u.id !== otherId)
  }

  return {
    friends,
    pending,
    blocked,
    incoming,
    outgoing,
    loading,
    load,
    add,
    accept,
    decline,
    remove,
    removeUser,
    block,
    unblock,
    isFriend,
    friendshipWith,
    applyEvent,
    removeEvent,
  }
}
