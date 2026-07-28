import { useLocalStorage } from '@vueuse/core'
import type { Channel, ChannelType } from '~/types'

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

  /**
   * The channel-type sections (Text / Voice / Side Spaces) inside an unfolded server.
   *
   * Stored as the *collapsed* set, not the expanded one, and keyed `serverId:type`. Both
   * halves of that are deliberate, and both are the lesson of the server-fold bug this file
   * already carries a note about:
   *
   *  - Keyed by server, so folding #general's Text section shut in one server doesn't fold
   *    it in the next. State that isn't keyed by what it describes is state that leaks the
   *    moment you navigate.
   *  - Collapsed-set rather than expanded-set, so *absence means open*. A server you've
   *    never visited, a type that appears for the first time when somebody adds the server's
   *    first voice channel, a browser with a cleared localStorage — all default to showing
   *    their channels. An expanded-set would default every one of those to hidden, which is
   *    a channel list that appears empty.
   *
   * And nothing here is touched by the route: no watcher opens or closes a section when you
   * switch servers, so the shape you left a server in is the shape you come back to.
   */
  const collapsedSections = useLocalStorage<string[]>('sidebar:collapsedSections', [])

  const sectionKey = (serverId: number, type: ChannelType) => `${serverId}:${type}`

  function isSectionOpen(serverId: number, type: ChannelType) {
    return !collapsedSections.value.includes(sectionKey(serverId, type))
  }

  function toggleSection(serverId: number, type: ChannelType) {
    const key = sectionKey(serverId, type)
    collapsedSections.value = collapsedSections.value.includes(key)
      ? collapsedSections.value.filter(k => k !== key)
      : [...collapsedSections.value, key]
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
    collapsedSections,
    isSectionOpen,
    toggleSection,
  }
}
