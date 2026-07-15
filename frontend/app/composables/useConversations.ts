import type { Conversation, User } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

/**
 * The Chats section of the sidebar: every DM and group chat you're in.
 *
 * The counterpart of useServers, and shorter than it — a chat has no invite code, no join
 * requests, and no owner who can delete everyone's history. What it has instead is a
 * `channel_id`, and that one field is why there is no useConversationMessages here: a chat
 * *is* a channel, so useMessages, useReads, useTyping, usePins, useThreads and useVoice all
 * already work on it, unchanged.
 */
export function useConversations() {
  const api = useApi()
  const { user } = useAuth()

  const conversations = useState<Conversation[]>('conversations', () => [])
  const page = useState<number>('conversations:page', () => 0) // 0 = not loaded yet
  const lastPage = useState<number>('conversations:lastPage', () => 1)
  const loading = ref(false)

  const hasMore = computed(() => page.value >= 1 && page.value < lastPage.value)
  const unreadTotal = computed(() =>
    conversations.value.reduce((sum, c) => sum + (c.unread_count ?? 0), 0),
  )

  async function fetchConversations(force = false) {
    if (page.value >= 1 && !force) return
    const res = await api<Paginated<Conversation>>('/api/conversations?page=1')
    conversations.value = res.data
    page.value = res.meta.current_page
    lastPage.value = res.meta.last_page
  }

  async function loadMore() {
    if (!hasMore.value || loading.value) return
    loading.value = true
    try {
      const res = await api<Paginated<Conversation>>(`/api/conversations?page=${page.value + 1}`)
      const seen = new Set(conversations.value.map(c => c.id))
      conversations.value = [...conversations.value, ...res.data.filter(c => !seen.has(c.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  function find(id: number) {
    return conversations.value.find(c => c.id === id) ?? null
  }

  /**
   * Put a conversation into the list, or update the one that's there.
   *
   * Upsert rather than push, because "open a DM with Ana" is idempotent on the server and
   * has to be idempotent here too: clicking it twice, or clicking it when Ana already
   * messaged you last week, must not put Ana in your sidebar twice.
   *
   * Spread over what's there rather than replacing it — a broadcast payload carries no
   * `unread_count` (it has no single asker), and overwriting the row wholesale would blank
   * the badge for everyone every time a group was renamed.
   */
  function upsert(conversation: Conversation) {
    const idx = conversations.value.findIndex(c => c.id === conversation.id)

    if (idx === -1) conversations.value = [conversation, ...conversations.value]
    else conversations.value.splice(idx, 1, { ...conversations.value[idx]!, ...conversation })
  }

  function patch(id: number, fields: Partial<Conversation>) {
    const idx = conversations.value.findIndex(c => c.id === id)
    if (idx !== -1) conversations.value.splice(idx, 1, { ...conversations.value[idx]!, ...fields })
  }

  function forget(id: number) {
    conversations.value = conversations.value.filter(c => c.id !== id)
  }

  /** Open the DM with someone — or reopen the one that was always there. */
  async function openDirect(userId: number) {
    const res = await api<{ data: Conversation }>('/api/conversations/dm', {
      method: 'POST',
      body: { user_id: userId },
    })
    upsert(res.data)

    return res.data
  }

  async function createGroup(name: string, userIds: number[]) {
    const res = await api<{ data: Conversation }>('/api/conversations/group', {
      method: 'POST',
      body: { name, user_ids: userIds },
    })
    upsert(res.data)

    return res.data
  }

  /** Owner only. Renames it for everybody in it. */
  async function renameGroup(id: number, name: string) {
    const res = await api<{ data: Conversation }>(`/api/conversations/${id}`, {
      method: 'PATCH',
      body: { name },
    })
    patch(id, { name: res.data.name })

    return res.data
  }

  async function addMembers(id: number, userIds: number[]) {
    return await api<{ data: User[] }>(`/api/conversations/${id}/members`, {
      method: 'POST',
      body: { user_ids: userIds },
    })
  }

  /** Leave a group. You can't leave a DM — the API answers 422. */
  async function leaveGroup(id: number) {
    await api(`/api/conversations/${id}/leave`, { method: 'POST' })
    forget(id)
  }

  /** People you're allowed to start a chat with: anyone you share a server with. */
  async function contacts(query = '') {
    const res = await api<{ data: User[] }>('/api/conversations/contacts', {
      query: { q: query },
    })

    return res.data
  }

  /** The chat whose channel this is — how a channel id gets back to its sidebar row. */
  function byChannel(channelId: number) {
    return conversations.value.find(c => c.channel_id === channelId) ?? null
  }

  return {
    conversations,
    hasMore,
    loading,
    unreadTotal,
    user,
    fetchConversations,
    loadMore,
    find,
    byChannel,
    upsert,
    patch,
    forget,
    openDirect,
    createGroup,
    renameGroup,
    addMembers,
    leaveGroup,
    contacts,
  }
}
