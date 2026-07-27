import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, SideChat, SideDeskAppId, StartedThread, Thread, Widget } from '~/types'

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
  // The timeline is no longer the only thing listening on `channel.{id}` — a floating window
  // or the pinned music player may still need it after we walk away. See unsubscribe().
  const { hold, release } = useEchoStream()
  // `a!<app>` opens a Side Desk app on the app-level shelf — see openDeskApp below.
  const { open: openFloating } = useFloatingWindows()

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

  /**
   * Post the message — as a run of them when the body is over the per-message limit, one after
   * the other so they land in the order they were written. See {@link buildMessageParts}.
   */
  async function send(body: string, replyToId?: number | null, files: File[] = [], gif?: GifResult | null, uploadIds: string[] = []) {
    if (!channelId.value) return
    for (const payload of buildMessageParts({ body, replyToId, files, gif, uploadIds })) {
      const res = await api<{ data: Message }>(`/api/channels/${channelId.value}/messages`, {
        method: 'POST',
        body: payload as any,
        headers: { 'X-Socket-ID': echo?.socketId() ?? '' },
      })
      pushUnique(res.data)
      // `a!board`, `a!notes`, … answer with an ephemeral note carrying the app to open. The
      // launch is deliberately client-side and sender-only: the note says what happened, and
      // the window it opens is one of *yours*, on the shelf that follows you around the app.
      if (res.data.open_app) openDeskApp(res.data.open_app)
    }
  }

  /** Pop a Side Desk app of *this* channel into a floating window. See FloatingSurfaceContent. */
  function openDeskApp(app: SideDeskAppId) {
    const id = channelId.value
    if (id == null) return

    // A widget app has a card of its own to open; only the surface apps need the window. The
    // server only ever sends surface ids, but the guard keeps this honest if that changes.
    if (isWidgetApp(app)) return

    openFloating({
      kind: 'surface',
      app,
      basePath: `/api/channels/${id}`,
      streamName: `channel.${id}`,
      // The timeline is only reachable by members of the channel, and every member may author
      // on its desk — the same reason SideDeskPanel hard-codes `can-edit`.
      canEdit: true,
      title: deskApp(app)?.label ?? 'App',
    })
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

  /**
   * The timeline's own handlers, held so they can be taken off again one by one.
   *
   * They used to be anonymous, because unsubscribing meant `echo.leave` and the whole
   * subscription went with them. It doesn't any more — a floating window may keep the channel
   * open after we're gone — so leaving these attached would have a closed timeline's callbacks
   * still firing into refs nobody is rendering, and a re-opened one listening twice.
   */
  const handlers: Record<string, (payload: any) => void> = {
    '.MessageSent': (m: Message) => {
      pushUnique(m)
      // A widget card arrives as a reference (no state) — pull its live state in.
      if (m.type === 'widget' && m.widget && m.widget.state == null) refreshWidget(m.widget.id)
    },
    '.MessageUpdated': (m: Message) => replaceMessage(m),
    '.MessageDeleted': (p: { id: number }) => removeMessage(p.id),
    '.ReactionToggled': (p: { message_id: number, reactions: Reaction[] }) => {
      patchMessage(p.message_id, { reactions: p.reactions })
    },
    // A comment ("word-reaction") was posted or removed — refresh the chips. We receive
    // our own broadcast too, so this is also how the actor's chips update.
    '.CommentPosted': (p: { message_id: number, comments: CommentSummary[] }) => {
      patchMessage(p.message_id, { comments: p.comments })
    },
    // A link finished unfurling on the queue — drop the card in under the message.
    '.MessagePreviewsUpdated': (p: { message_id: number, link_previews: LinkPreview[] }) => {
      patchMessage(p.message_id, { link_previews: p.link_previews })
    },
    // A widget moved (track changed, card crossed a column). The broadcast is a reference
    // only, so fetch the fresh state and re-sync every card of it.
    '.WidgetUpdated': (ref: { id: number }) => refreshWidget(ref.id),
    // Someone pinned or unpinned something. Patch the timeline (the pin icon) and the
    // Pinned tab. The message may live in a thread we've never opened, which is why the
    // event carries the whole thing rather than an id — patchMessage simply won't match.
    '.MessagePinToggled': (p: { pinned: boolean, message: Message }) => {
      patchMessage(p.message.id, { pinned: p.pinned, pinned_at: p.message.pinned_at })
      applyPin(p.pinned, p.message)
    },
    '.ThreadCreated': (t: Thread) => {
      setStartedThread(t.message_id, { id: t.id, name: t.name, replies_count: t.replies_count ?? 0 })
      if (!threads.value.some(x => x.id === t.id)) threads.value = [t, ...threads.value]
    },
    '.ThreadActivity': (a: { thread_id: number, message_id: number | null, name: string, replies_count: number }) => {
      setStartedThread(a.message_id, { id: a.thread_id, name: a.name, replies_count: a.replies_count })
      bumpThreadCount(a.thread_id, a.replies_count)
    },
    // Thread title changed (parent message was edited).
    '.ThreadUpdated': (a: { thread_id: number, message_id: number | null, name: string, replies_count: number }) => {
      setStartedThread(a.message_id, { id: a.thread_id, name: a.name, replies_count: a.replies_count })
      bumpThreadCount(a.thread_id, a.replies_count, a.name)
    },
    // Thread removed (parent message deleted): drop the indicator + list entry.
    '.ThreadDeleted': (a: { thread_id: number, message_id: number | null }) => {
      setStartedThread(a.message_id, null)
      threads.value = threads.value.filter(t => t.id !== a.thread_id)
    },
    // A side chat was spun up — drop its living-object card onto the origin message.
    '.SideChatCreated': (s: SideChat) => {
      setStartedSideChat(s.message_id, s)
      upsertSideChat(s)
    },
    // Its pulse changed (a message, a join, a decision) — refresh the card in place.
    '.SideChatActivity': (s: SideChat) => {
      setStartedSideChat(s.message_id, s)
      upsertSideChat(s)
    },
  }

  function subscribe(id: number) {
    const channel = hold(`channel.${id}`)
    if (!channel) return

    for (const [event, handler] of Object.entries(handlers)) channel.listen(event, handler)
  }

  /**
   * Let go of the channel stream — which only actually leaves it if nobody else is holding it.
   *
   * `echo.leave` drops the *whole* channel, every listener on it, including ones this
   * composable never put there. A floating widget, a floating conversation, a floating board
   * and the pinned music player all listen here and all deliberately outlive the timeline, so
   * leaving outright made every one of them go quietly deaf the moment you changed channel.
   * See {@link useEchoStream}, which counts the holders and leaves for the last one out.
   */
  function unsubscribe(id: number) {
    const channel = echo?.private(`channel.${id}`)

    if (channel) {
      for (const [event, handler] of Object.entries(handlers)) channel.stopListening(event, handler)
    }

    release(`channel.${id}`)
  }

  return { messages, hasMore, loadingOlder, load, loadOlder, ensureLoaded, send, edit, remove, removeAttachment, toggleReaction, togglePin, subscribe, unsubscribe }
}
