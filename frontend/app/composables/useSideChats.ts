import type { SideChat } from '~/types'

/**
 * Side chats that belong to the open channel. Shared state ('channel:sideChats') so the
 * channel's real-time listener can keep each card's counts and roster fresh while the panel
 * and the timeline cards render them.
 */
export function useSideChats() {
  const api = useApi()
  const sideChats = useState<SideChat[]>('channel:sideChats', () => [])

  async function loadSideChats(channelId: number) {
    const res = await api<{ data: SideChat[] }>(`/api/channels/${channelId}/side-chats`)
    sideChats.value = res.data
  }

  async function createSideChat(channelId: number, payload: { name: string, message_id?: number | null }) {
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

  return { sideChats, loadSideChats, createSideChat, join, leave, addParticipants }
}
