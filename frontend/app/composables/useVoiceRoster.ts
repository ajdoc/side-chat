import type { VoiceParticipant } from '~/types'

/**
 * Who is in each voice channel, for the people who *aren't* in any of them — the little
 * row of faces under a voice channel in the sidebar.
 *
 * This is deliberately not the same machinery the call itself runs on. The call is held
 * together by the presence channel `voice.{id}`, and presence has a catch: the only way
 * to observe who is in a room is to be in it. Subscribing the sidebar to eight presence
 * channels to find out who's talking would put you in eight calls at once.
 *
 * So the backend keeps rows, and pushes VoiceStateUpdated on the server-wide stream that
 * every member is already listening to (the same one that carries join requests and
 * unread pings). Slower than presence, by an HTTP round trip — and nothing here is on the
 * path of anybody's audio, so that doesn't matter.
 *
 * Note that useVoice does *not* read from this: once you're in a call, presence and
 * whispers tell you everything sooner and more reliably than a broadcast can.
 */
export function useVoiceRoster() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  // channel id → who's in it. Channels nobody is in simply aren't here.
  const roster = useState<Record<number, VoiceParticipant[]>>('voice:roster', () => ({}))

  function participantsIn(channelId: number): VoiceParticipant[] {
    return roster.value[channelId] ?? []
  }

  async function load(serverId: number) {
    try {
      const res = await api<{ data: Record<number, VoiceParticipant[]> }>(`/api/servers/${serverId}/voice`)
      roster.value = res.data
    } catch {
      roster.value = {}
    }
  }

  /** The same thing for a chat: who's in its call, before you join it. */
  async function loadForConversation(conversationId: number, channelId: number) {
    try {
      const res = await api<{ data: VoiceParticipant[] }>(`/api/conversations/${conversationId}/voice`)
      apply(channelId, res.data)
    } catch {
      apply(channelId, [])
    }
  }

  /** An empty room is *absent*, not present-and-empty — the sidebar asks by truthiness. */
  function apply(channelId: number, participants: VoiceParticipant[]) {
    const next = { ...roster.value }

    if (participants.length) next[channelId] = participants
    else delete next[channelId]

    roster.value = next
  }

  function onVoiceState(payload: { channel_id: number, participants: VoiceParticipant[] }) {
    apply(payload.channel_id, payload.participants)
  }

  function subscribe(serverId: number) {
    if (!echo) return

    echo.private(`server.${serverId}`).listen('.VoiceStateUpdated', onVoiceState)
  }

  function unsubscribe(serverId: number) {
    // Not echo.leave() — useJoinRequests shares this channel and owns tearing it down.
    echo?.private(`server.${serverId}`).stopListening('.VoiceStateUpdated')
  }

  /**
   * A chat's call roster rides `conversation.{id}` — the chat's own stream, subscribed to
   * only while you're reading it.
   *
   * Which is enough, and deliberately so: the only thing this drives is the row of faces
   * above a chat's timeline, which nobody can see unless they're looking at it. Whether a
   * chat you *aren't* looking at has a call going is a much cheaper question, answered by
   * the `call_active` flag on the conversation and kept live by CallStarted/CallEnded — no
   * roster required, and no subscription to every chat you've ever had.
   */
  function subscribeConversation(conversationId: number) {
    if (!echo) return

    echo.private(`conversation.${conversationId}`).listen('.VoiceStateUpdated', onVoiceState)
  }

  function unsubscribeConversation(conversationId: number) {
    echo?.leave(`conversation.${conversationId}`)
  }

  return {
    roster,
    participantsIn,
    load,
    loadForConversation,
    subscribe,
    unsubscribe,
    subscribeConversation,
    unsubscribeConversation,
  }
}
