import type { Conversation } from '~/types'

/**
 * The chat you currently have open — the counterpart of useServer.
 *
 * Much smaller than useServer, and the reason is the whole design: there is no channel
 * list to fetch, because a conversation *has* one channel and it came with the payload.
 * Everything below the header — the timeline, the composer, threads, pins, read receipts,
 * typing, the call — is driven off `channel_id` by the very same composables a server
 * channel uses, and none of them know or care that they're in a DM.
 *
 * So all this does is: hold the open conversation, and keep its call roster live.
 */
export function useConversation() {
  const api = useApi()
  const { upsert, patch } = useConversations()
  const roster = useVoiceRoster()

  const conversation = useState<Conversation | null>('active-conversation', () => null)
  // The id most recently asked for, so a response that lands after the user has already
  // switched to another chat can be discarded rather than clobbering it. (Same guard, and
  // the same bug, as useServer::openServer.)
  const requestedId = useState<number | null>('active-conversation:requestedId', () => null)

  async function openConversation(id: number) {
    if (conversation.value?.id === id) return

    if (requestedId.value) roster.unsubscribeConversation(requestedId.value)

    requestedId.value = id
    conversation.value = null

    const res = await api<{ data: Conversation }>(`/api/conversations/${id}`)

    if (requestedId.value !== id) return // superseded by a newer switch

    conversation.value = res.data
    // Keep the sidebar row and the open chat as one object, so a rename or a new member
    // shows up in both without two round trips.
    upsert(res.data)

    await roster.loadForConversation(id, res.data.channel_id)
    roster.subscribeConversation(id)
    // What people are called *in this chat* — see useNicknames.
    void useNicknames().open({ kind: 'conversation', id })
  }

  function closeConversation(id: number) {
    roster.unsubscribeConversation(id)
    useNicknames().close()
    if (requestedId.value === id) requestedId.value = null
  }

  /** Opening a chat is reading it — the badge goes, here and in the sidebar. */
  function clearUnread(id: number) {
    patch(id, { unread_count: 0, mention: false })
  }

  return { conversation, openConversation, closeConversation, clearUnread }
}
