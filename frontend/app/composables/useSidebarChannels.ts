import { useLocalStorage } from '@vueuse/core'
import type { Channel } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

/**
 * The sidebar's multi-server channel tree.
 *
 * {@link useServer} holds the *one* server you're standing in — its channels stay live
 * (unread badges, renames, voice occupants). But the sidebar keeps several servers unfolded
 * at once now, and the ones you aren't standing in still have to draw their channels. So
 * this caches channels per server and remembers which servers are unfolded — persisted, so
 * the shape of your sidebar survives a reload rather than snapping back to one.
 *
 * The active server's slice is kept warm here from useServer (the layout mirrors it in), so
 * that the instant you step off a server its channels are already cached and don't blink out.
 */
export function useSidebarChannels() {
  const api = useApi()
  // channelId lists keyed by server id — the drawn tree for every unfolded server.
  const channelsByServer = useState<Record<number, Channel[]>>('sidebar:channels', () => ({}))
  // Which servers are unfolded. A plain array (not a Set) so it serialises to localStorage.
  const expandedIds = useLocalStorage<number[]>('sidebar:expandedServers', [])
  const loadingIds = useState<Set<number>>('sidebar:channelsLoading', () => new Set())

  function isExpanded(serverId: number) {
    return expandedIds.value.includes(serverId)
  }
  function expand(serverId: number) {
    if (!expandedIds.value.includes(serverId)) expandedIds.value = [...expandedIds.value, serverId]
  }
  function collapse(serverId: number) {
    expandedIds.value = expandedIds.value.filter(id => id !== serverId)
  }
  function isLoading(serverId: number) {
    return loadingIds.value.has(serverId)
  }

  /** Fetch a server's channels into the cache (first page — 200 — is the whole tree here). */
  async function loadChannels(serverId: number, force = false) {
    if (isLoading(serverId)) return
    if (!force && channelsByServer.value[serverId]) return
    loadingIds.value = new Set(loadingIds.value).add(serverId)
    try {
      const res = await api<Paginated<Channel>>(`/api/servers/${serverId}/channels?page=1`)
      channelsByServer.value = { ...channelsByServer.value, [serverId]: res.data }
    }
    catch {
      // Left the server, or a transient miss — leave what we had; reopening retries.
    }
    finally {
      const next = new Set(loadingIds.value)
      next.delete(serverId)
      loadingIds.value = next
    }
  }

  /** Keep the active server's cached slice in step with its live channels (see the layout). */
  function cache(serverId: number, channels: Channel[]) {
    channelsByServer.value = { ...channelsByServer.value, [serverId]: channels.slice() }
  }

  /**
   * Fold a server open or shut. `active` says it's the server you're already viewing, whose
   * channels useServer is loading anyway — so unfolding it needs no fetch of its own.
   */
  async function toggle(serverId: number, opts: { active?: boolean } = {}) {
    if (isExpanded(serverId)) {
      collapse(serverId)
      return
    }
    expand(serverId)
    if (!opts.active) await loadChannels(serverId)
  }

  function channelsFor(serverId: number): Channel[] {
    return channelsByServer.value[serverId] ?? []
  }

  return {
    channelsByServer,
    expandedIds,
    isExpanded,
    isLoading,
    expand,
    collapse,
    toggle,
    loadChannels,
    cache,
    channelsFor,
  }
}
