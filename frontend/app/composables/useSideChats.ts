import type { SideChat } from '~/types'

/**
 * Side chats that belong to the open channel. Shared state (scoped per column) so the
 * channel's real-time listener can keep each card's counts and roster fresh while the panel
 * and the timeline cards render them.
 */
export function useSideChats() {
  const api = useApi()
  // Prefixed so a docked split pane keeps its own list. See useChannelScope.
  const scope = useChannelScope()
  const sideChats = useState<SideChat[]>(`${scope}channel:sideChats`, () => [])

  async function loadSideChats(channelId: number) {
    const res = await api<{ data: SideChat[] }>(`/api/channels/${channelId}/side-chats`)
    sideChats.value = res.data
  }

  async function createSideChat(channelId: number, payload: { name: string, message_id?: number | null, tags?: string[], side_chat_forum_id?: number | null }) {
    const res = await api<{ data: SideChat }>(`/api/channels/${channelId}/side-chats`, {
      method: 'POST',
      body: payload,
    })
    return res.data
  }

  /** Join the roster — what [Join] on a card does. Returns the refreshed side chat. */
  async function join(sideChatId: number) {
    const res = await api<{ data: SideChat }>(`/api/side-chats/${sideChatId}/join`, { method: 'POST' })
    return res.data
  }

  async function leave(sideChatId: number) {
    const res = await api<{ data: SideChat }>(`/api/side-chats/${sideChatId}/leave`, { method: 'POST' })
    return res.data
  }

  /** Bring other channel members onto the roster. Returns the refreshed side chat. */
  async function addParticipants(sideChatId: number, userIds: number[]) {
    const res = await api<{ data: SideChat }>(`/api/side-chats/${sideChatId}/participants`, {
      method: 'POST',
      body: { user_ids: userIds },
    })
    return res.data
  }

  /**
   * Retitle, retag and/or refile a post. Sending `tags` at all replaces the whole set,
   * including with an empty array — that's how you clear them — so the caller passes it
   * only when the tags are actually part of this edit.
   *
   * `side_chat_forum_id` works the same way one rung down: sending `null` files the post
   * back under Uncategorised, so it must be *omitted*, not nulled, to leave the group alone.
   */
  async function updateSideChat(sideChatId: number, payload: { name?: string, tags?: string[], side_chat_forum_id?: number | null }) {
    const res = await api<{ data: SideChat }>(`/api/side-chats/${sideChatId}`, {
      method: 'PATCH',
      body: payload,
    })
    upsert(res.data)
    return res.data
  }

  /** Delete a post and everything in it. `.SideChatDeleted` tells every other client. */
  async function removeSideChat(sideChatId: number) {
    await api(`/api/side-chats/${sideChatId}`, { method: 'DELETE' })
    sideChats.value = sideChats.value.filter(s => s.id !== sideChatId)
  }

  /**
   * React to the *post* — the forum list's chips, not a reaction on any message inside it.
   * Open to anyone in the channel: a list you'd have to join a post to vote on isn't a list.
   */
  async function react(sideChatId: number, emoji: string) {
    const res = await api<{ data: SideChat }>(`/api/side-chats/${sideChatId}/reactions`, {
      method: 'POST',
      body: { emoji },
    })
    upsert(res.data)
    return res.data
  }

  /** Fold a fresh copy into the list. The broadcast does this too; this just beats it home. */
  function upsert(next: SideChat) {
    sideChats.value = sideChats.value.map(s => (s.id === next.id ? { ...s, ...next } : s))
  }

  /**
   * Every tag in use in this channel, with how many posts carry it — the filter row above
   * the list. Derived rather than fetched: the list is already here in full, and a
   * separate endpoint would be a second answer that could disagree with the first.
   */
  const tagCounts = computed(() => {
    const counts = new Map<string, number>()
    for (const chat of sideChats.value) {
      for (const tag of chat.tags ?? []) counts.set(tag, (counts.get(tag) ?? 0) + 1)
    }
    return [...counts.entries()]
      .map(([tag, count]) => ({ tag, count }))
      .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag))
  })

  return { sideChats, tagCounts, loadSideChats, createSideChat, updateSideChat, removeSideChat, react, join, leave, addParticipants }
}
