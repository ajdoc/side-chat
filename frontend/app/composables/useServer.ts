import type { Channel, ChannelType, Server, SideDeskAppId } from '~/types'

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

  /**
   * Re-ask for the channel list of a server that's already open.
   *
   * `openServer` can't do this — it early-returns on the server it's already showing, and
   * it also re-seats every subscription, which is exactly what you don't want in the
   * middle of one. Used when access changes: the list is answered per viewer, so
   * refetching is what makes a newly-private channel disappear (or a newly-granted one
   * appear) without any client being told who else is on the list.
   */
  async function refreshChannels(id: number) {
    if (requestedId.value !== id) return
    const res = await api<Paginated<Channel>>(`/api/servers/${id}/channels?page=1`)
    if (requestedId.value !== id) return // superseded mid-fetch
    // Keep each row's per-viewer bits (the unread badge), which the list carries but a
    // re-fetch mid-session shouldn't be trusted to have re-counted identically.
    const previous = new Map(channels.value.map(c => [c.id, c]))
    channels.value = res.data.map(c => ({ ...previous.get(c.id), ...c }))
    chPage.value = res.meta.current_page
    chLast.value = res.meta.last_page
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

  /** `preset` names the starting room, and is required for — and only for — a Side Space. */
  async function createChannel(serverId: number, payload: { name: string, type: ChannelType, preset?: string, app_id?: SideDeskAppId }) {
    const res = await api<{ data: Channel }>(`/api/servers/${serverId}/channels`, {
      method: 'POST',
      body: payload,
    })
    channels.value = [...channels.value, res.data]
    return res.data
  }

  /**
   * Every channel in the tree — containers and the discussions under them — as one list.
   *
   * `channels` is a tree now, but most of the app addresses a channel by id and doesn't care
   * which level it sits on: a route parameter, a split pane, a voice bar, a search result. They
   * all look here rather than each learning to walk two levels.
   */
  const flatChannels = computed<Channel[]>(() =>
    channels.value.flatMap(c => [c, ...(c.discussions ?? [])]))

  /** A channel or a discussion, by id. Null when it isn't in the open server's tree. */
  function findChannel(id: number | null): Channel | null {
    if (!id) return null

    return flatChannels.value.find(c => c.id === id) ?? null
  }

  /**
   * The discussion a channel id should actually open.
   *
   * Handed a discussion, that discussion. Handed a container, the one you've pinned as your
   * default, or its first discussion if you haven't — a container holds no timeline, so opening
   * one has to mean opening something inside it. Every route into a channel goes through here,
   * which is why "click the channel, land where I asked to land" needs no special case
   * anywhere else.
   *
   * The default falls back rather than fails: a discussion you pinned and somebody has since
   * deleted lands you in the first one, not nowhere.
   */
  function resolveDiscussion(channel: Channel | null): Channel | null {
    if (!channel) return null
    if (channel.parent_id) return channel

    const preferred = channel.discussions?.find(d => d.id === channel.default_child_id)

    return preferred ?? channel.discussions?.[0] ?? null
  }

  /** Take a channel or discussion out of the sidebar — deleted here, or by someone else. */
  function forgetChannel(id: number) {
    channels.value = channels.value
      .filter(c => c.id !== id)
      .map(c => c.discussions?.some(d => d.id === id)
        ? { ...c, discussions: c.discussions.filter(d => d.id !== id) }
        : c)

    rollUpUnread()
  }

  /**
   * A container's badge is the sum of its discussions', recomputed whenever one of them moves.
   *
   * The server does this when it builds the list; this keeps it true between fetches, as
   * messages arrive and channels are read. Without it a container's badge would be a number
   * that was right once, which is worse than no number at all.
   */
  function rollUpUnread() {
    channels.value = channels.value.map(c => c.discussions
      ? { ...c, unread_count: c.discussions.reduce((n, d) => n + (d.unread_count ?? 0), 0), mention: c.discussions.some(d => d.mention) }
      : c)
  }

  /**
   * Patch a channel in the sidebar, from our own rename or somebody else's broadcast.
   *
   * Spread over what's already there rather than replacing it: `unread_count` is a fact
   * about *this* viewer, so a payload broadcast to every member can't carry one — and
   * overwriting the row wholesale would blank the badge for everyone on every rename.
   */
  function patchChannel(id: number, fields: Partial<Channel>) {
    // Either level: the id may name a container or one of its discussions, and the callers —
    // a rename broadcast, an unread bump, a read receipt — don't know which they hold.
    channels.value = channels.value.map((c) => {
      if (c.id === id) return { ...c, ...fields }
      if (!c.discussions?.some(d => d.id === id)) return c

      return { ...c, discussions: c.discussions.map(d => d.id === id ? { ...d, ...fields } : d) }
    })

    rollUpUnread()
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
    flatChannels,
    findChannel,
    resolveDiscussion,
    hasMoreChannels,
    openServer,
    refreshChannels,
    loadMoreChannels,
    createChannel,
    renameChannel,
    deleteChannel,
    forgetChannel,
    patchChannel,
    patchServer,
  }
}
