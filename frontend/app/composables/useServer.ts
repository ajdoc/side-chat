import type { Channel, ChannelType, Server } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

// The currently-open server (metadata) plus its paginated channel list (200 per page).
export function useServer() {
  const api = useApi()
  const server = useState<Server | null>('active-server', () => null)
  const channels = useState<Channel[]>('active-server:channels', () => [])
  const chPage = useState<number>('active-server:chPage', () => 1)
  const chLast = useState<number>('active-server:chLast', () => 1)
  const chLoading = ref(false)
  // The server id most recently requested by openServer(), used to discard a
  // response that arrives after the user has already switched to another server.
  const requestedId = useState<number | null>('active-server:requestedId', () => null)

  const hasMoreChannels = computed(() => chPage.value < chLast.value)

  /**
   * Loads a server and its first page of channels as one atomic unit.
   *
   * Loading them via two independent calls (as before) let `server` and `channels`
   * fall out of sync: whichever request resolved first would update its own ref
   * while the other still held the *previous* server's data. A page reading both
   * (e.g. "does this server have a text channel to redirect into?") could then act
   * on a server/channels pairing that never actually existed together — which is
   * how switching to an empty server could redirect into a channel that belongs to
   * the server you just left.
   *
   * Fix: clear both refs synchronously before loading, and only commit the
   * response if this is still the most recently requested server (guards against
   * a stale response winning a race when the user switches servers again before
   * the first request finishes).
   */
  async function openServer(id: number) {
    if (server.value?.id === id) return

    const joinRequests = useJoinRequests()
    const unread = useUnread()
    const voiceRoster = useVoiceRoster()
    const lifecycle = useServerLifecycle()

    if (requestedId.value) {
      // Order matters: unread, the voice roster and lifecycle only detach their listeners,
      // join-requests leaves the channel outright — so it has to go last.
      unread.unsubscribe(requestedId.value)
      voiceRoster.unsubscribe(requestedId.value)
      lifecycle.unsubscribe(requestedId.value)
      joinRequests.unsubscribe(requestedId.value)
    }

    requestedId.value = id
    server.value = null
    channels.value = []

    const [serverRes, channelsRes] = await Promise.all([
      api<{ data: Server }>(`/api/servers/${id}`),
      api<Paginated<Channel>>(`/api/servers/${id}/channels?page=1`),
      joinRequests.load(id),
      voiceRoster.load(id),
    ])

    if (requestedId.value !== id) return // superseded by a newer switch

    server.value = serverRes.data
    channels.value = channelsRes.data
    chPage.value = channelsRes.meta.current_page
    chLast.value = channelsRes.meta.last_page
    joinRequests.subscribe(id)
    unread.subscribe(id)
    voiceRoster.subscribe(id)
    lifecycle.subscribe(id)
    // What people are called *in this server*. Fire-and-forget: names render fine
    // without it, and it swaps them in when it lands.
    void useNicknames().open({ kind: 'server', id })
  }

  async function loadMoreChannels(id: number) {
    if (!hasMoreChannels.value || chLoading.value) return
    chLoading.value = true
    try {
      const res = await api<Paginated<Channel>>(`/api/servers/${id}/channels?page=${chPage.value + 1}`)
      if (requestedId.value !== id) return // server was switched mid-fetch
      const seen = new Set(channels.value.map(c => c.id))
      channels.value = [...channels.value, ...res.data.filter(c => !seen.has(c.id))]
      chPage.value = res.meta.current_page
      chLast.value = res.meta.last_page
    } finally {
      chLoading.value = false
    }
  }

  async function createChannel(serverId: number, payload: { name: string, type: ChannelType }) {
    const res = await api<{ data: Channel }>(`/api/servers/${serverId}/channels`, {
      method: 'POST',
      body: payload,
    })
    channels.value = [...channels.value, res.data]
    return res.data
  }

  /** Take a channel out of the sidebar — deleted here, or deleted by someone else. */
  function forgetChannel(id: number) {
    channels.value = channels.value.filter(c => c.id !== id)
  }

  /**
   * Patch a channel in the sidebar, from our own rename or somebody else's broadcast.
   *
   * Spread over what's already there rather than replacing it: `unread_count` is a fact
   * about *this* viewer, so a payload broadcast to every member can't carry one — and
   * overwriting the row wholesale would blank the badge for everyone on every rename.
   */
  function patchChannel(id: number, fields: Partial<Channel>) {
    const idx = channels.value.findIndex(c => c.id === id)
    if (idx !== -1) channels.value.splice(idx, 1, { ...channels.value[idx]!, ...fields })
  }

  /** Same, for the open server's own metadata (the sidebar header). */
  function patchServer(id: number, fields: Partial<Server>) {
    if (server.value?.id === id) server.value = { ...server.value, ...fields }
  }

  /** Owner only. Renames it for everybody. */
  async function renameChannel(id: number, name: string) {
    const res = await api<{ data: Channel }>(`/api/channels/${id}`, {
      method: 'PATCH',
      body: { name },
    })
    patchChannel(id, res.data)

    return res.data
  }

  /** Owner only. Deletes the channel's threads, messages and files, for everybody. */
  async function deleteChannel(id: number) {
    await api(`/api/channels/${id}`, { method: 'DELETE' })
    forgetChannel(id)
  }

  return {
    server,
    channels,
    hasMoreChannels,
    openServer,
    loadMoreChannels,
    createChannel,
    renameChannel,
    deleteChannel,
    forgetChannel,
    patchChannel,
    patchServer,
  }
}
