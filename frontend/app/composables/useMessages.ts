import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, SideChat, StartedThread, Thread, Widget } from '~/types'

// Messages for one text channel, plus the real-time Reverb subscription.
export function useMessages() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const messages = ref<Message[]>([])
  const channelId = ref<number | null>(null)
  const hasMore = ref(false) // older messages exist above the loaded window
  const loadingOlder = ref(false)
  // Shared with useThreads() so the Threads list stays in sync.
  const threads = useState<Thread[]>('channel:threads', () => [])
  // Likewise for side chats — the list panel and the timeline cards read the same state.
  const sideChats = useState<SideChat[]>('channel:sideChats', () => [])
  // Likewise for the Pinned tab: this composable owns the channel stream, so it's the one
  // that folds a pin toggle into the shared list. See usePins().
  const { toggle: togglePinRequest, apply: applyPin } = usePins()
  // The docked music player listens on this same channel and outlives the timeline — see
  // unsubscribe() for why it has to be handed its subscription back.
  const { rejoin: rejoinPinnedMusic } = useMusicPin()

  function pushUnique(m: Message) {
    if (!messages.value.some(x => x.id === m.id)) {
      messages.value = [...messages.value, m]
    }
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
  /**
   * A widget's state moved — refresh every card rendering it. One widget can have several
   * cards in the timeline (each `m!queue`/`k!list` drops a fresh one); they all show the
   * same live state, so we patch all of them by matching `widget.id`.
   */
  function patchWidget(widget: Widget) {
    messages.value = messages.value.map(m =>
      m.widget?.id === widget.id ? { ...m, widget } : m,
    )
  }
  /**
   * A widget moved, but its state is too big to ride the socket (Pusher's 10KB event cap),
   * so the broadcast is a reference only. Pull the fresh state and fold it into every card.
   */
  async function refreshWidget(widgetId: number) {
    try {
      const res = await api<{ data: Widget }>(`/api/widgets/${widgetId}`)
      patchWidget(res.data)
    }
    catch {
      // A transient miss just leaves the card on its last state; a reload refetches it.
    }
  }
  function setStartedThread(messageId: number | null, summary: StartedThread | null) {
    if (!messageId) return
    const idx = messages.value.findIndex(m => m.id === messageId)
    if (idx !== -1) messages.value.splice(idx, 1, { ...messages.value[idx]!, started_thread: summary })
  }
  function bumpThreadCount(threadId: number, repliesCount: number, name?: string) {
    const idx = threads.value.findIndex(t => t.id === threadId)
    if (idx !== -1) {
      threads.value.splice(idx, 1, { ...threads.value[idx]!, replies_count: repliesCount, ...(name ? { name } : {}) })
    }
  }
  /** Attach (or refresh) the living-object card on the message a side chat was spun off. */
  function setStartedSideChat(messageId: number | null, sideChat: SideChat) {
    if (!messageId) return
    const idx = messages.value.findIndex(m => m.id === messageId)
    if (idx !== -1) messages.value.splice(idx, 1, { ...messages.value[idx]!, started_side_chat: sideChat })
  }
  /** Keep the shared side-chats list in sync (upsert, newest first). */
  function upsertSideChat(sideChat: SideChat) {
    const idx = sideChats.value.findIndex(s => s.id === sideChat.id)
    if (idx !== -1) sideChats.value.splice(idx, 1, { ...sideChats.value[idx]!, ...sideChat })
    else sideChats.value = [sideChat, ...sideChats.value]
  }

  async function load(id: number) {
    channelId.value = id
    const res = await api<{ data: Message[], has_more: boolean }>(`/api/channels/${id}/messages`)
    messages.value = res.data
    hasMore.value = res.has_more
  }

  // Prepend the previous 200 messages. Returns the id of the message that was
  // previously at the top, so the view can keep it in place after prepending.
  async function loadOlder(): Promise<number | null> {
    if (!channelId.value || !hasMore.value || loadingOlder.value || !messages.value.length) return null
    loadingOlder.value = true
    const anchorId = messages.value[0]!.id
    try {
      const res = await api<{ data: Message[], has_more: boolean }>(
        `/api/channels/${channelId.value}/messages?before=${anchorId}`,
      )
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

  async function send(body: string, replyToId?: number | null, files: File[] = [], gif?: GifResult | null, uploadIds: string[] = []) {
    if (!channelId.value) return
    const payload = buildMessagePayload({ body, replyToId, files, gif, uploadIds })
    const res = await api<{ data: Message }>(`/api/channels/${channelId.value}/messages`, {
      method: 'POST',
      body: payload as any,
      headers: { 'X-Socket-ID': echo?.socketId() ?? '' },
    })
    pushUnique(res.data)
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
      // PHP cannot parse a multipart body on PATCH, so we POST with method spoofing.
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

  /** Pin the message, or unpin it if it's already pinned. */
  async function togglePin(messageId: number) {
    replaceMessage(await togglePinRequest(messageId))
  }

  /** Delete a single attachment (and its file); returns the refreshed message. */
  async function removeAttachment(attachmentId: number) {
    const res = await api<{ data: Message }>(`/api/attachments/${attachmentId}`, { method: 'DELETE' })
    replaceMessage(res.data)
  }

  async function remove(id: number) {
    await api(`/api/messages/${id}`, { method: 'DELETE' })
    removeMessage(id)
  }

  function subscribe(id: number) {
    echo.private(`channel.${id}`)
      .listen('.MessageSent', (m: Message) => {
        pushUnique(m)
        // A widget card arrives as a reference (no state) — pull its live state in.
        if (m.type === 'widget' && m.widget && m.widget.state == null) refreshWidget(m.widget.id)
      })
      .listen('.MessageUpdated', (m: Message) => replaceMessage(m))
      .listen('.MessageDeleted', (p: { id: number }) => removeMessage(p.id))
      .listen('.ReactionToggled', (p: { message_id: number, reactions: Reaction[] }) => {
        patchMessage(p.message_id, { reactions: p.reactions })
      })
      // A comment ("word-reaction") was posted or removed — refresh the chips. We receive
      // our own broadcast too, so this is also how the actor's chips update.
      .listen('.CommentPosted', (p: { message_id: number, comments: CommentSummary[] }) => {
        patchMessage(p.message_id, { comments: p.comments })
      })
      // A link finished unfurling on the queue — drop the card in under the message.
      .listen('.MessagePreviewsUpdated', (p: { message_id: number, link_previews: LinkPreview[] }) => {
        patchMessage(p.message_id, { link_previews: p.link_previews })
      })
      // A widget moved (track changed, card crossed a column). The broadcast is a reference
      // only, so fetch the fresh state and re-sync every card of it.
      .listen('.WidgetUpdated', (ref: { id: number }) => refreshWidget(ref.id))
      // Someone pinned or unpinned something. Patch the timeline (the pin icon) and the
      // Pinned tab. The message may live in a thread we've never opened, which is why the
      // event carries the whole thing rather than an id — patchMessage simply won't match.
      .listen('.MessagePinToggled', (p: { pinned: boolean, message: Message }) => {
        patchMessage(p.message.id, { pinned: p.pinned, pinned_at: p.message.pinned_at })
        applyPin(p.pinned, p.message)
      })
      .listen('.ThreadCreated', (t: Thread) => {
        setStartedThread(t.message_id, { id: t.id, name: t.name, replies_count: t.replies_count ?? 0 })
        if (!threads.value.some(x => x.id === t.id)) threads.value = [t, ...threads.value]
      })
      .listen('.ThreadActivity', (a: { thread_id: number, message_id: number | null, name: string, replies_count: number }) => {
        setStartedThread(a.message_id, { id: a.thread_id, name: a.name, replies_count: a.replies_count })
        bumpThreadCount(a.thread_id, a.replies_count)
      })
      // Thread title changed (parent message was edited).
      .listen('.ThreadUpdated', (a: { thread_id: number, message_id: number | null, name: string, replies_count: number }) => {
        setStartedThread(a.message_id, { id: a.thread_id, name: a.name, replies_count: a.replies_count })
        bumpThreadCount(a.thread_id, a.replies_count, a.name)
      })
      // Thread removed (parent message deleted): drop the indicator + list entry.
      .listen('.ThreadDeleted', (a: { thread_id: number, message_id: number | null }) => {
        setStartedThread(a.message_id, null)
        threads.value = threads.value.filter(t => t.id !== a.thread_id)
      })
      // A side chat was spun up — drop its living-object card onto the origin message.
      .listen('.SideChatCreated', (s: SideChat) => {
        setStartedSideChat(s.message_id, s)
        upsertSideChat(s)
      })
      // Its pulse changed (a message, a join, a decision) — refresh the card in place.
      .listen('.SideChatActivity', (s: SideChat) => {
        setStartedSideChat(s.message_id, s)
        upsertSideChat(s)
      })
  }

  function unsubscribe(id: number) {
    echo.leave(`channel.${id}`)
    // `leave` drops the *whole* channel, including listeners this composable never put
    // there. A pinned music player keeps listening here long after its timeline closes —
    // without this it would go deaf on the way out, freeze on its last state, and look for
    // all the world like its buttons had stopped working. No-op when nothing is pinned here.
    rejoinPinnedMusic(id)
  }

  return { messages, hasMore, loadingOlder, load, loadOlder, ensureLoaded, send, edit, remove, removeAttachment, toggleReaction, togglePin, subscribe, unsubscribe }
}
