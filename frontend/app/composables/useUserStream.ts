import type { Conversation, IncomingCall, User } from '~/types'
import { useDesktopNotifications } from '~/composables/useDesktopNotifications'

/**
 * Your own stream — the one subscription that isn't about a place.
 *
 * Everything else in the app listens to somewhere you already are: a channel you have
 * open, a server you're looking at, a call you're in. That leaves a hole, and DMs fall
 * straight through it. Someone opens a DM with you: you aren't in it, so there is no
 * stream on which to tell you it now exists. Someone calls you: the ring has to arrive
 * whatever you happen to be looking at, and has to arrive even if you've never once
 * opened that conversation. Someone messages a chat you haven't clicked on: the badge has
 * to move, and the badge that needs moving is by definition on the chat you're *not*
 * subscribed to.
 *
 * So this is opened once, on login, and held for as long as you're here. It's the only
 * subscription in the app that outlives every navigation.
 */
export function useUserStream() {
  const echo: any = useNuxtApp().$echo
  const route = useRoute()
  const { user } = useAuth()
  const { conversations, upsert, patch, forget, byChannel } = useConversations()
  const { incoming, ringingFor, stopRinging } = useCall()
  const { notify } = useDesktopNotifications()
  const { channelId: callChannelId, disconnect, disconnectedByModerator } = useVoice()

  const subscribed = useState<number | null>('user-stream:id', () => null)

  /** The conversation you're looking at right now, if you're looking at one. */
  const openConversationId = computed(() => Number(route.params.conversationId) || null)

  function bumpUnread(conversationId: number, mention = false) {
    const conversation = conversations.value.find(c => c.id === conversationId)
    if (!conversation) return

    // The chat open *in front of you* is about to be marked read, so badging it would only
    // flicker. Open in a background tab is a different story — nobody is reading that, and
    // useReads only marks read once the tab is visible again. (Same rule as useUnread.)
    const looking = conversationId === openConversationId.value && document.visibilityState === 'visible'
    if (looking) return

    patch(conversationId, {
      unread_count: (conversation.unread_count ?? 0) + 1,
      mention: conversation.mention || mention,
    })
  }

  function subscribe() {
    const id = user.value?.id
    if (!echo || !id || subscribed.value === id) return

    subscribed.value = id

    echo.private(`user.${id}`)
      // A chat you're now in has appeared: someone DM'd you, or added you to a group.
      .listen('.ConversationCreated', (conversation: Conversation) => {
        upsert(conversation)
      })
      // You left a group — possibly from another tab, which is the only reason this needs
      // to be a broadcast at all.
      .listen('.ConversationRemoved', (p: { conversation_id: number }) => {
        // Before we forget it: the call has to be hung up while we can still tell that the
        // channel we're talking into belonged to this chat.
        hangUpIfInside(p.conversation_id)
        forget(p.conversation_id)

        if (openConversationId.value === p.conversation_id) navigateTo('/chats')
      })
      .listen('.ConversationUpdated', (conversation: Conversation) => {
        upsert(conversation)
      })
      // A message landed in a chat you aren't looking at.
      .listen('.ChannelActivity', (a: {
        channel_id: number
        conversation_id: number | null
        user_id: number
        mentioned_user_ids?: number[]
        mentions_all?: boolean
      }) => {
        if (a.user_id === user.value?.id || !a.conversation_id) return

        const mentionsMe = !!a.mentions_all || !!a.mentioned_user_ids?.includes(user.value?.id ?? -1)
        bumpUnread(a.conversation_id, mentionsMe)

        if (mentionsMe) {
          const conversation = conversations.value.find(c => c.id === a.conversation_id)
          if (conversation) {
            notify({
              title: conversationTitle(conversation, user.value),
              body: 'You were mentioned',
              tag: `mention-chat-${conversation.id}`,
              to: `/chats/${conversation.id}`,
            })
          }
        }
      })

      // --- the ringing phone ---

      .listen('.CallStarted', (payload: IncomingCall) => {
        upsert(payload.conversation)
        patch(payload.conversation.id, { call_active: true })

        // Already talking to someone else? Then you are not free to be interrupted, and a
        // second ring would be a second call trying to take your microphone. The chat still
        // lights up as "in a call" — you can go and join it when you're done.
        if (callChannelId.value !== null) return

        ringingFor(payload)
      })
      .listen('.CallEnded', (p: { conversation_id: number, answered: boolean }) => {
        patch(p.conversation_id, { call_active: false })

        // The whole reason CallEnded is fanned out to people who never answered: a ring
        // that outlives the call is the most annoying bug this feature could have.
        if (incoming.value?.conversation.id === p.conversation_id) stopRinging()
      })
      .listen('.CallDeclined', (p: { conversation_id: number, user: User }) => {
        // Your *other tabs*. You dismissed it on your laptop; your phone should stop too.
        if (p.user.id === user.value?.id && incoming.value?.conversation.id === p.conversation_id) {
          stopRinging()
        }
      })

      // An owner turned you out of a call. It arrives here, on your personal stream, rather
      // than on the call itself, because a call outlives the page it began on — you might be
      // reading a text channel with the mesh still up. Only hang up if it's the call you're
      // actually in; a stale event for a room you already left is nothing to act on.
      .listen('.VoiceParticipantDisconnected', (p: { channel_id: number }) => {
        if (callChannelId.value === p.channel_id) disconnectedByModerator()
      })
  }

  function unsubscribe() {
    if (!echo || !subscribed.value) return

    echo.leave(`user.${subscribed.value}`)
    subscribed.value = null
  }

  /**
   * Hang up if the chat we're in a call with just went away.
   *
   * A call deliberately outlives the page it was started from, so navigating away doesn't
   * end one — which means losing access to the chat wouldn't either, and the peer
   * connections would happily stay up in a conversation you're no longer part of.
   */
  function hangUpIfInside(conversationId: number) {
    if (callChannelId.value === null) return

    const conversation = byChannel(callChannelId.value)
    if (conversation?.id === conversationId) disconnect()
  }

  return { subscribe, unsubscribe, hangUpIfInside }
}
