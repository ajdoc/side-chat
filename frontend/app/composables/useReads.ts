import type { ChannelRead, Message, User } from '~/types'

/**
 * Read receipts for one channel.
 *
 * Two halves: we tell the server how far *we've* read, and we keep a map of how far
 * everyone else has, which the timeline turns into the little "seen by" avatars.
 */
export function useReads() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { user } = useAuth()
  const { channels } = useServer()

  // user id → their marker. A map, not a list: a read is an *update* to where someone
  // is, not a new event, so the same user must never appear twice.
  const reads = ref<Map<number, ChannelRead>>(new Map())
  const channelId = ref<number | null>(null)
  // The furthest id we've told the server about, so we don't re-post the same one.
  const sentUpTo = ref(0)

  /**
   * message id → the people whose marker sits on that exact message.
   *
   * Avatars show against the last message each person read, not against every message
   * they've read — otherwise the whole backlog would be plastered with faces.
   */
  const readersByMessage = computed(() => {
    const map: Record<number, User[]> = {}

    for (const read of reads.value.values()) {
      const id = read.last_read_message_id
      // Your own marker is not news to you.
      if (id == null || read.user.id === user.value?.id) continue
      ;(map[id] ??= []).push(read.user)
    }

    return map
  })

  async function load(id: number) {
    channelId.value = id
    sentUpTo.value = 0
    reads.value = new Map()

    const res = await api<{ data: ChannelRead[] }>(`/api/channels/${id}/reads`)
    reads.value = new Map(res.data.map(r => [r.user.id, r]))
  }

  /**
   * Mark everything up to `messageId` as read.
   *
   * Called on open, on focus, and on every message that arrives while you're looking —
   * so it's mostly a no-op, and the guards below keep it from being a no-op *over the
   * network*.
   */
  async function markRead(messageId: number | null) {
    const id = channelId.value
    if (!id || messageId == null || messageId <= sentUpTo.value) return

    sentUpTo.value = messageId

    // Reflect it locally right away: the sidebar badge shouldn't wait for a round trip.
    clearUnread(id)

    try {
      await api(`/api/channels/${id}/read`, {
        method: 'POST',
        body: { message_id: messageId },
      })
    } catch {
      sentUpTo.value = 0 // let the next attempt retry rather than assume it landed
    }
  }

  /** Mark read only if the user is actually looking at the window. */
  function markReadIfVisible(messages: Message[]) {
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
    markRead(messages.at(-1)?.id ?? null)
  }

  function clearUnread(id: number) {
    const idx = channels.value.findIndex(c => c.id === id)
    if (idx !== -1) channels.value.splice(idx, 1, { ...channels.value[idx]!, unread_count: 0, mention: false })
  }

  function subscribe(id: number) {
    echo.private(`channel.${id}`)
      .listen('.ChannelReadUpdated', (r: ChannelRead) => {
        reads.value = new Map(reads.value).set(r.user.id, r)
      })
  }

  function unsubscribe(id: number) {
    // Not echo.leave() — useMessages is listening on this same channel and still needs it.
    echo.private(`channel.${id}`).stopListening('.ChannelReadUpdated')
  }

  return { reads, readersByMessage, load, markRead, markReadIfVisible, subscribe, unsubscribe }
}
