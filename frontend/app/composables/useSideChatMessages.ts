import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, SideChat } from '~/types'

/**
 * One side chat: its metadata (roster, counts), its messages, and the real-time
 * subscription (sidechat.{id}). Closely mirrors useThreadMessages — a side chat is a
 * thread with a guest list — with two additions: the live `sideChat` header (kept fresh by
 * SideChatActivity) and `toggleDecision`, the ✅ that a thread has no equivalent of.
 */
export function useSideChatMessages() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const sideChat = ref<SideChat | null>(null)
  const messages = ref<Message[]>([])
  const hasMore = ref(false)
  const loadingOlder = ref(false)
  // The standing highlights card: decisions + pinned messages, which may sit outside the
  // loaded message window, so they're fetched on their own and kept fresh over the stream.
  const highlights = ref<{ decisions: Message[], pinned: Message[] }>({ decisions: [], pinned: [] })
  const currentId = ref<number | null>(null)
  // Set when the side chat is deleted out from under us — the panel watches it and leaves.
  // Mirrors useThreadMessages' `gone`, for the same reason: the composable doesn't own the
  // URL, so it reports rather than navigates.
  const gone = ref(false)

  function pushUnique(m: Message) {
    if (!messages.value.some(x => x.id === m.id)) messages.value = [...messages.value, m]
  }
  function replaceMessage(m: Message) {
    const idx = messages.value.findIndex(x => x.id === m.id)
    if (idx !== -1) messages.value.splice(idx, 1, { ...messages.value[idx]!, ...m })
  }
  function removeMessage(id: number) {
    messages.value = messages.value.filter(m => m.id !== id)
  }
  function patchMessage(id: number, patch: Partial<Message>) {
    const idx = messages.value.findIndex(m => m.id === id)
    if (idx !== -1) messages.value.splice(idx, 1, { ...messages.value[idx]!, ...patch })
  }

  async function loadHighlights(id: number) {
    highlights.value = await api<{ decisions: Message[], pinned: Message[] }>(`/api/side-chats/${id}/highlights`)
  }

  async function loadSideChat(id: number) {
    currentId.value = id
    gone.value = false // opening a live side chat clears a previous one's tombstone
    const [s, m] = await Promise.all([
      api<{ data: SideChat }>(`/api/side-chats/${id}`),
      api<{ data: Message[], has_more: boolean }>(`/api/side-chats/${id}/messages`),
      loadHighlights(id),
    ])
    sideChat.value = s.data
    messages.value = m.data
    hasMore.value = m.has_more
  }

  /** Refetch the highlights card if we still have a side chat open (after a pin/decision/delete). */
  function refreshHighlights() {
    if (currentId.value) loadHighlights(currentId.value)
  }

  async function loadOlder(): Promise<number | null> {
    const sid = sideChat.value?.id
    if (!sid || !hasMore.value || loadingOlder.value || !messages.value.length) return null
    loadingOlder.value = true
    const anchorId = messages.value[0]!.id
    try {
      const res = await api<{ data: Message[], has_more: boolean }>(`/api/side-chats/${sid}/messages?before=${anchorId}`)
      const seen = new Set(messages.value.map(m => m.id))
      messages.value = [...res.data.filter(m => !seen.has(m.id)), ...messages.value]
      hasMore.value = res.has_more
      return anchorId
    } finally {
      loadingOlder.value = false
    }
  }

  async function ensureLoaded(id: number): Promise<boolean> {
    let guard = 0
    while (!messages.value.some(m => m.id === id) && hasMore.value && guard++ < 50) {
      if ((await loadOlder()) == null) break
    }
    return messages.value.some(m => m.id === id)
  }

  /**
   * A reply, split into a run of them if it's over the per-message limit — see useMessages().
   *
   * `repliesToPost` marks it as addressed at the side chat's *post* rather than at another
   * message: the forum's top-level reply, which renders a chip naming the post's title.
   */
  async function send(sideChatId: number, body: string, replyToId?: number | null, files: File[] = [], gif?: GifResult | null, uploadIds: string[] = [], repliesToPost = false) {
    for (const payload of buildMessageParts({ body, replyToId, repliesToPost, files, gif, uploadIds })) {
      const res = await api<{ data: Message }>(`/api/side-chats/${sideChatId}/messages`, {
        method: 'POST',
        body: payload as any,
        headers: { 'X-Socket-ID': echo?.socketId() ?? '' },
      })
      pushUnique(res.data)
    }
  }

  async function edit(id: number, body: string | null, files: File[] = [], removeAttachmentIds: number[] = []) {
    const multipart = files.length > 0 || removeAttachmentIds.length > 0
    const payload = buildMessagePayload({
      body,
      files,
      removeAttachmentIds,
      ...(multipart ? { method: 'PATCH' as const } : {}),
    })
    const res = await api<{ data: Message }>(`/api/messages/${id}`, {
      method: multipart ? 'POST' : 'PATCH',
      body: payload as any,
    })
    replaceMessage(res.data)
  }

  async function toggleReaction(messageId: number, emoji: string) {
    const res = await api<{ data: Message }>(`/api/messages/${messageId}/reactions`, {
      method: 'POST',
      body: { emoji },
    })
    replaceMessage(res.data)
  }

  /** Pin a reply, or unpin it — shows up in the channel's Pinned tab like any pin. */
  async function togglePin(messageId: number) {
    replaceMessage(await usePins().toggle(messageId))
  }

  /** Record this message as a decision, or take the mark back (the ✅ on the card). */
  async function toggleDecision(messageId: number) {
    const res = await api<{ data: Message }>(`/api/messages/${messageId}/decision`, { method: 'POST' })
    replaceMessage(res.data)
  }

  async function remove(id: number) {
    await api(`/api/messages/${id}`, { method: 'DELETE' })
    removeMessage(id)
  }

  function subscribe(id: number) {
    echo.private(`sidechat.${id}`)
      .listen('.MessageSent', (m: Message) => pushUnique(m))
      // Covers decision toggles too (the ✅) — refresh the highlights card when one lands.
      .listen('.MessageUpdated', (m: Message) => { replaceMessage(m); refreshHighlights() })
      .listen('.MessageDeleted', (p: { id: number }) => { removeMessage(p.id); refreshHighlights() })
      .listen('.ReactionToggled', (p: { message_id: number, reactions: Reaction[] }) => {
        patchMessage(p.message_id, { reactions: p.reactions })
      })
      .listen('.CommentPosted', (p: { message_id: number, comments: CommentSummary[] }) => {
        patchMessage(p.message_id, { comments: p.comments })
      })
      .listen('.MessagePreviewsUpdated', (p: { message_id: number, link_previews: LinkPreview[] }) => {
        patchMessage(p.message_id, { link_previews: p.link_previews })
      })
      // Only the local list — the channel's Pinned tab is kept right off the channel stream,
      // which is also open (the panel lives inside the channel).
      .listen('.MessagePinToggled', (p: { pinned: boolean, message: Message }) => {
        patchMessage(p.message.id, { pinned: p.pinned, pinned_at: p.message.pinned_at })
        refreshHighlights()
      })
      // Roster/counts changed (someone joined, a decision was recorded, the post was
      // retitled, retagged or reacted to) — refresh the header from the whole card.
      .listen('.SideChatActivity', (s: SideChat) => { sideChat.value = s })
      // The post was deleted out from under us. Flagged rather than acted on, the same way
      // a deleted thread is: the panel owns the URL and decides how to leave.
      .listen('.SideChatDeleted', () => { gone.value = true })
      // A thread was started off one of this side chat's messages — drop its indicator on
      // the message so the Chat tab shows "view thread" live, not only after a reload.
      .listen('.ThreadCreated', (t: { id: number, message_id: number | null, name: string, replies_count?: number }) => {
        if (t.message_id != null) {
          patchMessage(t.message_id, { started_thread: { id: t.id, name: t.name, replies_count: t.replies_count ?? 0 } })
        }
        // Keep the workspace's Threads badge live without waiting for a reload.
        if (sideChat.value) sideChat.value.threads_count = (sideChat.value.threads_count ?? 0) + 1
      })
  }

  function unsubscribe(id: number) {
    echo.leave(`sidechat.${id}`)
  }

  return {
    sideChat, messages, highlights, gone, hasMore, loadingOlder,
    loadSideChat, loadOlder, ensureLoaded,
    send, edit, remove, toggleReaction, togglePin, toggleDecision,
    subscribe, unsubscribe,
  }
}
