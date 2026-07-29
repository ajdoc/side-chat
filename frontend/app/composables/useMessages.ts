import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, SideChat, SideChatForum, SideDeskAppId, StartedThread, Thread, Widget } from '~/types'

// Messages for one text channel, plus the real-time Reverb subscription.
export function useMessages() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const messages = ref<Message[]>([])
  const channelId = ref<number | null>(null)
  const hasMore = ref(false) // older messages exist above the loaded window
  // …and, after a jump to a search result, unloaded messages *below* it. False everywhere
  // else: an ordinary page always ends at the newest message. See jumpTo().
  const hasNewer = ref(false)
  const loadingOlder = ref(false)
  // Shared with useThreads() so the Threads list stays in sync.
  // Every channel-scoped store here is prefixed, so the split view's docked pane keeps its
  // own copies rather than fighting the main column over one. See useChannelScope.
  const scope = useChannelScope()
  const threads = useState<Thread[]>(`${scope}channel:threads`, () => [])
  // Likewise for side chats — the list panel and the timeline cards read the same state.
  const sideChats = useState<SideChat[]>(`${scope}channel:sideChats`, () => [])
  // And for the forum groups the side chat list folds under — this composable owns the
  // channel stream, so it's the one that applies `.SideChatForumsUpdated`.
  const forums = useState<SideChatForum[]>(`${scope}channel:sideChatForums`, () => [])
  // Likewise for the Pinned tab: this composable owns the channel stream, so it's the one
  // that folds a pin toggle into the shared list. See usePins().
  const { toggle: togglePinRequest, apply: applyPin } = usePins()
  // The timeline is no longer the only thing listening on `channel.{id}` — a floating window
  // or the pinned music player may still need it after we walk away. See unsubscribe().
  const { hold, release } = useEchoStream()
  // `a!<app>` opens a Side Desk app on the app-level shelf — see openDeskApp below.
  const { open: openFloating } = useFloatingWindows()

  function pushUnique(m: Message) {
    // While parked on a jump the loaded window ends mid-history, so appending a live
    // arrival would print today's message directly beneath March's with the intervening
    // months missing. It isn't lost — returning to the latest page refetches it.
    if (hasNewer.value) return
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
  /**
   * Attach (or refresh) the living-object card on the message a side chat was spun off.
   * `null` takes it off again — what a deleted post leaves behind is a plain message.
   */
  function setStartedSideChat(messageId: number | null, sideChat: SideChat | null) {
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
    // A plain load always lands at the live end, which un-does whatever window a jump left.
    hasNewer.value = false
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
  // Used to jump to a reply's original message when it's older than what's loaded — a
  // reply's target is nearly always a screen or two up, so a few pages usually finds it.
  async function ensureLoaded(id: number): Promise<boolean> {
    let guard = 0
    while (!messages.value.some(m => m.id === id) && hasMore.value && guard++ < 50) {
      if ((await loadOlder()) == null) break
    }
    return messages.value.some(m => m.id === id)
  }

  /**
   * Re-anchor the timeline on one message, wherever in history it is.
   *
   * What a search result needs, and what {@link ensureLoaded} can't give it: a hit from
   * March is thousands of messages back, and paging there would be dozens of round trips
   * to throw nearly all of it away. So the server returns a window centred on the message
   * instead (`?around=`) and the loaded range simply *becomes* that window.
   *
   * `hasNewer` is the consequence and it has to be tracked: for the first time the timeline
   * can be sitting somewhere with messages below it that aren't loaded, so "scrolled to the
   * bottom" no longer means "at the newest message". Live arrivals are dropped while it's
   * true — appending them would put today's message directly under March's.
   */
  async function jumpTo(id: number, targetChannelId?: number): Promise<boolean> {
    const target = targetChannelId ?? channelId.value
    if (target == null) return false

    // Already in the loaded window — no need to throw it away and refetch.
    if (target === channelId.value && messages.value.some(m => m.id === id)) return true

    channelId.value = target
    const res = await api<{ data: Message[], has_more: boolean, has_newer: boolean }>(
      `/api/channels/${target}/messages?around=${id}`,
    )
    messages.value = res.data
    hasMore.value = res.has_more
    hasNewer.value = res.has_newer

    return res.data.some(m => m.id === id)
  }

  /** Back to the live end of the timeline, dropping whatever window a jump left us in. */
  async function returnToLatest() {
    if (channelId.value == null) return
    await load(channelId.value)
  }

  /**
   * Post the message — as a run of them when the body is over the per-message limit, one after
   * the other so they land in the order they were written. See {@link buildMessageParts}.
   */
  async function send(body: string, replyToId?: number | null, files: File[] = [], gif?: GifResult | null, uploadIds: string[] = []) {
    if (!channelId.value) return
    // Writing is leaving: your message belongs at the live end of the conversation, so
    // typing one abandons whatever historical window a search jump had us parked in. Doing
    // it here rather than dropping the message keeps `pushUnique`'s guard from swallowing
    // the one message the user is guaranteed to be looking for.
    if (hasNewer.value) await returnToLatest()

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
    // Its pulse changed (a message, a join, a decision, a retitle, a reaction on the
    // post) — refresh the card in place.
    '.SideChatActivity': (s: SideChat) => {
      setStartedSideChat(s.message_id, s)
      upsertSideChat(s)
    },
    // The post was deleted: drop it from the Side Chats list and take the card off the
    // origin message, which goes on existing without it.
    '.SideChatDeleted': (p: { side_chat_id: number, message_id: number | null }) => {
      setStartedSideChat(p.message_id, null)
      sideChats.value = sideChats.value.filter(s => s.id !== p.side_chat_id)
    },
    /**
     * Somebody rearranged the forum groups — a heading added, renamed, reordered, removed.
     *
     * A straight replace, with no per-group permission to preserve: whether you may manage
     * groups is a fact about the *channel*, not about any one heading, so it's fetched once
     * into `channel:canManageForums` and a broadcast can't invalidate it. See
     * {@link useSideChatForums}.
     */
    '.SideChatForumsUpdated': (p: { forums: SideChatForum[] }) => {
      forums.value = p.forums
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

  return { messages, hasMore, hasNewer, loadingOlder, load, loadOlder, ensureLoaded, jumpTo, returnToLatest, send, edit, remove, removeAttachment, toggleReaction, togglePin, subscribe, unsubscribe }
}
