import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, Thread } from '~/types'

// One thread: its metadata, messages, and the real-time subscription (thread.{id}).
export function useThreadMessages() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const thread = ref<Thread | null>(null)
  const messages = ref<Message[]>([])
  const gone = ref(false) // set when the thread is deleted out from under us
  const hasMore = ref(false)
  const loadingOlder = ref(false)

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
  /** Patch one field of one message in place, if we still have it loaded. */
  function patchMessage(id: number, patch: Partial<Message>) {
    const idx = messages.value.findIndex(m => m.id === id)
    if (idx !== -1) messages.value.splice(idx, 1, { ...messages.value[idx]!, ...patch })
  }

  async function loadThread(id: number) {
    gone.value = false
    const [t, m] = await Promise.all([
      api<{ data: Thread }>(`/api/threads/${id}`),
      api<{ data: Message[], has_more: boolean }>(`/api/threads/${id}/messages`),
    ])
    thread.value = t.data
    messages.value = m.data
    hasMore.value = m.has_more
  }

  async function loadOlder(): Promise<number | null> {
    const tid = thread.value?.id
    if (!tid || !hasMore.value || loadingOlder.value || !messages.value.length) return null
    loadingOlder.value = true
    const anchorId = messages.value[0]!.id
    try {
      const res = await api<{ data: Message[], has_more: boolean }>(`/api/threads/${tid}/messages?before=${anchorId}`)
      const seen = new Set(messages.value.map(m => m.id))
      messages.value = [...res.data.filter(m => !seen.has(m.id)), ...messages.value]
      hasMore.value = res.has_more
      return anchorId
    } finally {
      loadingOlder.value = false
    }
  }

  // Page backward until `id` shows up in the loaded window (or history runs out).
  // Used to jump to a reply's original message when it's older than what's loaded.
  async function ensureLoaded(id: number): Promise<boolean> {
    let guard = 0
    while (!messages.value.some(m => m.id === id) && hasMore.value && guard++ < 50) {
      if ((await loadOlder()) == null) break
    }
    return messages.value.some(m => m.id === id)
  }

  /** A reply, split into a run of them if it's over the per-message limit — see useMessages(). */
  async function send(threadId: number, body: string, replyToId?: number | null, files: File[] = [], gif?: GifResult | null, uploadIds: string[] = []) {
    for (const payload of buildMessageParts({ body, replyToId, files, gif, uploadIds })) {
      const res = await api<{ data: Message }>(`/api/threads/${threadId}/messages`, {
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

  /** Add the reaction, or take it back if it's already yours. */
  async function toggleReaction(messageId: number, emoji: string) {
    const res = await api<{ data: Message }>(`/api/messages/${messageId}/reactions`, {
      method: 'POST',
      body: { emoji },
    })
    replaceMessage(res.data)
  }

  async function removeAttachment(attachmentId: number) {
    const res = await api<{ data: Message }>(`/api/attachments/${attachmentId}`, { method: 'DELETE' })
    replaceMessage(res.data)
  }

  async function remove(id: number) {
    await api(`/api/messages/${id}`, { method: 'DELETE' })
    removeMessage(id)
  }

  /**
   * Pin a reply, or unpin it. A pinned thread message shows up in the *channel's* Pinned
   * tab — usePins() owns that list, and the broadcast keeps it right for everyone else.
   */
  async function togglePin(messageId: number) {
    replaceMessage(await usePins().toggle(messageId))
  }

  function subscribe(id: number) {
    echo.private(`thread.${id}`)
      .listen('.MessageSent', (m: Message) => pushUnique(m))
      .listen('.MessageUpdated', (m: Message) => replaceMessage(m))
      .listen('.MessageDeleted', (p: { id: number }) => removeMessage(p.id))
      .listen('.ReactionToggled', (p: { message_id: number, reactions: Reaction[] }) => {
        patchMessage(p.message_id, { reactions: p.reactions })
      })
      .listen('.CommentPosted', (p: { message_id: number, comments: CommentSummary[] }) => {
        patchMessage(p.message_id, { comments: p.comments })
      })
      .listen('.MessagePreviewsUpdated', (p: { message_id: number, link_previews: LinkPreview[] }) => {
        patchMessage(p.message_id, { link_previews: p.link_previews })
      })
      // Only the local list — the Pinned tab is updated off the *channel* stream, which is
      // also open (the thread panel lives inside the channel), and doing it in both places
      // would apply the same pin twice.
      .listen('.MessagePinToggled', (p: { pinned: boolean, message: Message }) => {
        patchMessage(p.message.id, { pinned: p.pinned, pinned_at: p.message.pinned_at })
      })
      .listen('.ThreadUpdated', (a: { name: string }) => {
        if (thread.value) thread.value = { ...thread.value, name: a.name }
      })
      .listen('.ThreadDeleted', () => { gone.value = true })
  }

  function unsubscribe(id: number) {
    echo.leave(`thread.${id}`)
  }

  return { thread, messages, gone, hasMore, loadingOlder, loadThread, loadOlder, ensureLoaded, send, edit, remove, removeAttachment, toggleReaction, togglePin, subscribe, unsubscribe }
}
