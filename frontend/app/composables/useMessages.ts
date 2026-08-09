import type { CommentSummary, GifResult, LinkPreview, Message, Reaction, SideChat, SideChatForum, SideDeskAppId, StartedThread, Thread, Widget } from '~/types'
import type { SealedFile } from '~/lib/crypto/envelope'
import { encryptFile, generateFileKey } from '~/lib/crypto/attachment'
import { toBase64 } from '~/lib/crypto/primitives'

/**
 * A page of a channel's timeline.
 *
 * `encryption` describes the channel *now* — what the next message sent would be — and says
 * nothing about the messages in `data`, each of which carries its own flag. See the two
 * different questions in {@link useMessageCrypto}.
 */
interface MessagePage {
  data: Message[]
  has_more: boolean
  has_newer?: boolean
  encryption?: { encrypted: boolean, epoch: number, files?: boolean }
}

/**
 * Encrypt a batch of files, keeping keys and metadata in the same order as the files.
 *
 * Order is the only thing linking a key to its file. The server stores attachments in the
 * order they were posted and hands them back the same way, so `meta[i]` describes
 * `attachments[i]` — there is no id to match on, because the keys are sealed before any row
 * exists and the server must never learn which is which anyway.
 */
async function sealFiles(files: File[]): Promise<{ files: File[], meta: SealedFile[] }> {
  const sealed: File[] = []
  const meta: SealedFile[] = []

  for (const file of files) {
    const { key, raw } = await generateFileKey()

    sealed.push(await encryptFile(file, key))
    meta.push({ n: file.name, m: file.type || 'application/octet-stream', k: toBase64(raw) })
  }

  return { files: sealed, meta }
}

/**
 * The text out of a built payload, whichever shape it took.
 *
 * Encryption happens after chunking, so it has to read back the body the builder just put in
 * — and that is a form field when there are files and a plain property when there aren't.
 */
function bodyOf(payload: FormData | Record<string, unknown>): string | null {
  if (isFormData(payload)) return (payload.get('body') as string | null) ?? null

  return (payload.body as string | null | undefined) ?? null
}

/**
 * Somebody just added a reaction, worked out from the two lists either side of the change.
 *
 * The broadcast says what the reactions *are*, not what happened to them, which is right for a
 * chip row that only ever needs the total. Anything that wants to react to the *act* — a Side
 * Space popping the emoji over the reactor's head, say — has to spot the difference itself, and
 * doing it once here is better than every listener re-deriving it.
 */
export interface ReactionAdded {
  channelId: number
  messageId: number
  userId: number
  emoji: string
}

/*
 * Module scope, unlike everything else in this file, because `useMessages()` hands out fresh
 * refs on every call and a listener registered against one instance would be invisible to the
 * instance that owns the subscription. Same reasoning as the room-event listeners in
 * useSpacePresence.
 */
const reactionListeners = new Set<(event: ReactionAdded) => void>()

/**
 * Watch reactions land. Returns its own undo.
 *
 * Additions only. A reaction taken back is a correction, and there's nothing to show for it —
 * an emote that un-happened is not an event anybody in a room would have noticed.
 */
export function onReactionAdded(listener: (event: ReactionAdded) => void): () => void {
  reactionListeners.add(listener)

  return () => reactionListeners.delete(listener)
}

/** Who is in `next` that wasn't in `before`, for each emoji. Usually exactly one person. */
function newReactors(before: Reaction[], next: Reaction[]): Array<{ userId: number, emoji: string }> {
  const added: Array<{ userId: number, emoji: string }> = []

  for (const row of next) {
    const had = new Set((before.find(r => r.emoji === row.emoji)?.users ?? []).map(u => u.id))

    for (const u of row.users) {
      if (!had.has(u.id)) added.push({ userId: u.id, emoji: row.emoji })
    }
  }

  return added
}

// Messages for one text channel, plus the real-time Reverb subscription.
export function useMessages() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const messages = ref<Message[]>([])
  const channelId = ref<number | null>(null)
  /**
   * Whether the *channel* is encrypted right now, and which era it is in.
   *
   * About sending only. Reading asks each message, because a timeline is striped: these two
   * describe what the next message will be, never what the ones already on screen are.
   */
  const encrypted = ref(false)
  const epoch = ref(0)
  /**
   * Whether *files* are sealed as well as messages.
   *
   * A deployment setting rather than a per-channel one — see config/uploads.php. Off means
   * message text is still end-to-end encrypted and attachments are not, which is a real
   * weakening and is said out loud in the composer and the encryption dialog rather than
   * left for somebody to discover.
   */
  const encryptFiles = ref(true)
  const encryption = useEncryption()
  const crypto = useMessageCrypto()
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

  /**
   * Open a channel's timeline.
   *
   * The encryption state comes back *with* the page rather than from the caller. Every caller
   * has a channel id and only some have the channel — a popped-out conversation window is
   * opened by id alone — and a composer that guessed "not encrypted" because nobody told it
   * would post in the clear under a padlock. One authoritative source, from the server, on
   * the request the timeline was already making.
   */
  async function load(id: number) {
    channelId.value = id

    const res = await api<MessagePage>(`/api/channels/${id}/messages`)

    encrypted.value = res.encryption?.encrypted ?? false
    epoch.value = res.encryption?.epoch ?? 0
    encryptFiles.value = res.encryption?.files ?? true

    // Collect whatever sender keys were addressed to this device before rendering. Doing it
    // after would draw a screen of "can't read this" and then quietly fix itself, which reads
    // as the encryption being broken.
    if (epoch.value > 0) {
      await collectKeys(id)
      // …and make sure anybody who has joined since gets *our* chain. Opening the channel is
      // enough; nobody should have to send a message to un-deafen a new device.
      void encryption.announcePresence(id, epoch.value).catch(() => {})
    }

    messages.value = await crypto.decryptAll(res.data)
    hasMore.value = res.has_more
    // A plain load always lands at the live end, which un-does whatever window a jump left.
    hasNewer.value = false
  }

  /**
   * Fetch and unwrap this channel's sender keys, tolerating failure.
   *
   * Never allowed to stop a channel opening. If the key exchange is down, the right outcome
   * is a timeline whose encrypted rows say they can't be read — not a blank screen where a
   * conversation should be, including the plaintext half that has nothing to do with keys.
   */
  async function collectKeys(id: number) {
    try {
      await encryption.collectInbox(id)
    } catch {
      // Reported per message by the "can't read this" state, which is where a person can
      // actually act on it.
    }
  }

  /**
   * Try the unreadable messages again, now that more keys have arrived.
   *
   * Without this, a key that turns up while the timeline is on screen changes nothing until
   * something else reloads the page — the rows stay as "can't read this" even though they
   * now can be. That is the exact shape of the complaint the whole distribution fix is
   * about, and leaving it out would only move the reload one step later.
   *
   * Only the failed rows are retried. A page of successfully decrypted messages is not worth
   * redoing, and re-deriving them would churn every chain on screen for no change.
   */
  async function retryUndecrypted() {
    if (!messages.value.some(m => m.decryption === 'failed')) return

    messages.value = await Promise.all(
      messages.value.map(m => (m.decryption === 'failed' ? crypto.decryptIncoming(m) : m)),
    )
  }

  // Prepend the previous 200 messages. Returns the id of the message that was
  // previously at the top, so the view can keep it in place after prepending.
  async function loadOlder(): Promise<number | null> {
    if (!channelId.value || !hasMore.value || loadingOlder.value || !messages.value.length) return null
    loadingOlder.value = true
    const anchorId = messages.value[0]!.id
    try {
      const res = await api<MessagePage>(
        `/api/channels/${channelId.value}/messages?before=${anchorId}`,
      )
      const seen = new Set(messages.value.map(m => m.id))
      const older = await crypto.decryptAll(res.data.filter(m => !seen.has(m.id)))
      messages.value = [...older, ...messages.value]
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
    const res = await api<MessagePage>(
      `/api/channels/${target}/messages?around=${id}`,
    )
    // A jump can land anywhere — a search result from a channel this device has never opened —
    // so collect keys whenever the window actually contains ciphertext, rather than trying to
    // infer it from where we came from.
    if (res.data.some(m => m.encrypted)) await collectKeys(target)

    messages.value = await crypto.decryptAll(res.data)
    hasMore.value = res.has_more
    // Only the `?around=` page carries it; the type makes it optional for the other two.
    hasNewer.value = res.has_newer ?? false

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
  async function send(
    body: string,
    replyToId?: number | null,
    files: File[] = [],
    gif?: GifResult | null,
    uploadIds: string[] = [],
    /**
     * Keys for files the composer already sealed and staged.
     *
     * Anything over CHUNK_THRESHOLD starts uploading the moment it is picked, so it cannot be
     * encrypted here — by the time a send happens its bytes are already on the server. The
     * composer seals those itself and passes the keys along; see MessageComposer.stage().
     */
    uploadMeta: SealedFile[] = [],
  ) {
    if (!channelId.value) return
    // Writing is leaving: your message belongs at the live end of the conversation, so
    // typing one abandons whatever historical window a search jump had us parked in. Doing
    // it here rather than dropping the message keeps `pushUnique`'s guard from swallowing
    // the one message the user is guaranteed to be looking for.
    if (hasNewer.value) await returnToLatest()

    /*
     * Encrypt the files before anything is built, because doing so replaces them.
     *
     * Each file gets its own random key, its bytes are sealed, and the key travels with the
     * real name and type inside the message envelope. What reaches the server is an opaque
     * blob called "encrypted" — see AttachmentService::describe for the other half.
     *
     * A GIF is left alone and cannot be otherwise: it is a reference to somebody else's CDN,
     * not bytes we hold. Picking one in an encrypted channel tells that CDN, and anybody
     * watching, what was sent. The composer should say so — see the note in ChannelView.
     */
    const outgoing = encrypted.value && encryptFiles.value
      ? await sealFiles(files)
      : { files, meta: [] as SealedFile[] }

    /*
     * Direct files first, then staged uploads — the order the server attaches them in.
     *
     * AttachmentService runs storeFor() before attachUploads(), and a key is matched to its
     * file by position alone. Getting this order wrong doesn't fail loudly: it hands each
     * file the next one's key, and every attachment on the message becomes undecryptable
     * rubbish with a plausible-looking name.
     */
    const meta = [...outgoing.meta, ...uploadMeta]

    const parts = buildMessageParts({ body, replyToId, files: outgoing.files, gif, uploadIds })

    for (const [index, part] of parts.entries()) {
      /*
       * Encrypt after chunking, and refuse rather than fall back.
       *
       * If the chain can't be built or handed out — the key exchange is down, no device
       * would verify — this throws and the composer surfaces it. Posting in the clear
       * instead would be worse than any error: the padlock said otherwise, and the person
       * typing would never know. See useMessageCrypto.
       *
       * The file keys go on the *last* part, because that is where buildMessageParts puts
       * the files themselves. On the first part they would describe attachments three
       * messages further down; on every part they would ship the same keys repeatedly.
       */
      const carriesFiles = index === parts.length - 1

      const payload = encrypted.value
        ? setPayloadBody(
            part,
            await crypto.encryptOutgoing(
              channelId.value,
              epoch.value,
              bodyOf(part),
              carriesFiles ? meta : [],
            ),
          )
        : part

      const res = await api<{ data: Message }>(`/api/channels/${channelId.value}/messages`, {
        method: 'POST',
        body: payload as any,
        headers: { 'X-Socket-ID': echo?.socketId() ?? '' },
      })
      // Our own message comes back as the ciphertext we sent, so it goes through the same
      // door as everybody else's rather than being special-cased into the timeline.
      pushUnique(await crypto.decryptIncoming(res.data))
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
    const built = buildMessagePayload({
      body,
      files,
      removeAttachmentIds,
      ...(multipart ? { method: 'PATCH' as const } : {}),
    })

    /*
     * An edit re-encrypts under the *message's* era, not the channel's.
     *
     * Those differ whenever encryption has been toggled since — editing a message from era 1
     * while the channel sits in era 2 must produce era-1 ciphertext, or the edit lands in a
     * chain the readers of that message never had. A message from a plaintext run stays
     * plaintext for the same reason: what a message is was decided when it was sent.
     */
    const target = messages.value.find(m => m.id === id)
    const payload = target?.encrypted && target.epoch != null
      ? setPayloadBody(built, await crypto.encryptOutgoing(target.channel_id, target.epoch, body))
      : built

    const res = await api<{ data: Message }>(`/api/messages/${id}`, {
      // PHP cannot parse a multipart body on PATCH, so we POST with method spoofing.
      method: multipart ? 'POST' : 'PATCH',
      body: payload as any,
    })
    replaceMessage(await crypto.decryptIncoming(res.data))
  }

  /** Add the reaction, or take it back if it's already yours. */
  async function toggleReaction(messageId: number, emoji: string) {
    const before = messages.value.find(m => m.id === messageId)?.reactions ?? []

    const res = await api<{ data: Message }>(`/api/messages/${messageId}/reactions`, {
      method: 'POST',
      body: { emoji },
    })
    replaceMessage(res.data)

    /*
     * Your own reaction is announced from here rather than left to the broadcast.
     *
     * The response has already been written into the timeline by the time your own
     * `.ReactionToggled` arrives, so the diff there finds nothing changed and says nothing —
     * which is exactly right for the chip row and exactly wrong for you, the one person who
     * definitely just reacted. So the actor's own event comes off the response.
     */
    const channel = channelId.value
    if (channel === null) return

    for (const added of newReactors(before, res.data.reactions ?? [])) {
      for (const listen of reactionListeners) {
        listen({ channelId: channel, messageId, userId: added.userId, emoji: added.emoji })
      }
    }
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
      // Decryption is async and a handler isn't, so the push happens in the `then`. Ordering
      // still holds: `pushUnique` appends by arrival and drops anything already there, so a
      // message that lost a race with its own re-render doesn't double up.
      void crypto.decryptIncoming(m).then((decrypted) => {
        pushUnique(decrypted)
        // A widget card arrives as a reference (no state) — pull its live state in.
        if (m.type === 'widget' && m.widget && m.widget.state == null) refreshWidget(m.widget.id)
      })
    },
    '.MessageUpdated': (m: Message) => void crypto.decryptIncoming(m).then(replaceMessage),
    /**
     * Somebody flipped the padlock while we were looking at the channel.
     *
     * The composer has to change behaviour mid-conversation — a client that kept sending
     * plaintext into a channel that had just been locked is the worst failure this feature
     * has. Collecting keys immediately means the first message of the new era is readable
     * when it lands rather than a moment later.
     */
    '.ChannelEncryptionToggled': (p: { encrypted: boolean, encryption_epoch: number }) => {
      encrypted.value = p.encrypted
      epoch.value = p.encryption_epoch
      if (p.encrypted && channelId.value !== null) void collectKeys(channelId.value)
    },
    /**
     * Somebody has left sender keys in this channel's post box.
     *
     * The event carries nothing readable — every blob in the inbox is sealed to one device —
     * so the only useful response is to go and look. This is what lets a device that joined
     * mid-conversation start reading without a reload, and what makes a message sent to it
     * legible the moment its sender notices it exists.
     */
    '.SenderKeysDistributed': () => {
      const id = channelId.value
      if (id === null) return

      void collectKeys(id).then(retryUndecrypted)
    },
    '.MessageDeleted': (p: { id: number }) => removeMessage(p.id),
    '.ReactionToggled': (p: { message_id: number, reactions: Reaction[] }) => {
      // Diffed before the patch, since the patch is what destroys the "before".
      const before = messages.value.find(m => m.id === p.message_id)?.reactions ?? []

      patchMessage(p.message_id, { reactions: p.reactions })

      const channel = channelId.value
      if (channel === null) return

      for (const { userId, emoji } of newReactors(before, p.reactions)) {
        for (const listen of reactionListeners) listen({ channelId: channel, messageId: p.message_id, userId, emoji })
      }
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

  return { messages, hasMore, hasNewer, loadingOlder, encrypted, encryptFiles, epoch, load, loadOlder, ensureLoaded, jumpTo, returnToLatest, send, edit, remove, removeAttachment, toggleReaction, togglePin, subscribe, unsubscribe }
}
