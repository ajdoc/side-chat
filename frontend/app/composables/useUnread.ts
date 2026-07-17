import { useDesktopNotifications } from '~/composables/useDesktopNotifications'

/**
 * Live unread badges in the channel sidebar.
 *
 * The counts arrive with the channel list; keeping them current is the problem. You're
 * only subscribed to the channel you're *looking at*, so a message in any other channel
 * would never reach this client — which is exactly the channel a badge is for. Hence
 * ChannelActivity, a bodyless ping on the server-wide stream.
 */
export function useUnread() {
  const echo: any = useNuxtApp().$echo
  const route = useRoute()
  const { user } = useAuth()
  const { channels } = useServer()
  const { notify } = useDesktopNotifications()

  const activeChannelId = computed(() => Number(route.params.channelId) || null)

  function bump(channelId: number, mention = false) {
    const idx = channels.value.findIndex(c => c.id === channelId)
    if (idx === -1) return

    const channel = channels.value[idx]!
    channels.value.splice(idx, 1, {
      ...channel,
      unread_count: (channel.unread_count ?? 0) + 1,
      // Sticky: once a channel holds a mention, it keeps the louder badge until you read it.
      mention: channel.mention || mention,
    })
  }

  function subscribe(serverId: number) {
    if (!echo) return

    echo.private(`server.${serverId}`)
      .listen('.ChannelActivity', (a: {
        channel_id: number
        user_id: number
        mentioned_user_ids?: number[]
        mentions_all?: boolean
      }) => {
        if (a.user_id === user.value?.id) return

        // The channel open *in front of you* is about to be marked read, so badging it
        // would only flicker. Open in a background tab is a different story — nobody is
        // reading that, and useReads only marks read once the tab is visible again.
        const looking = a.channel_id === activeChannelId.value && document.visibilityState === 'visible'
        if (looking) return

        const mentionsMe = !!a.mentions_all || !!a.mentioned_user_ids?.includes(user.value?.id ?? -1)
        bump(a.channel_id, mentionsMe)

        // A mention in a channel you're not reading is worth a system notification (notify()
        // itself stays quiet unless the tab is in the background).
        if (mentionsMe) {
          const channel = channels.value.find(c => c.id === a.channel_id)
          if (channel) {
            notify({
              title: `#${channel.name}`,
              body: 'You were mentioned',
              tag: `mention-channel-${channel.id}`,
              to: `/servers/${channel.server_id}/channels/${channel.id}`,
            })
          }
        }
      })
  }

  function unsubscribe(serverId: number) {
    // Not echo.leave() — useJoinRequests shares this channel and owns tearing it down.
    echo?.private(`server.${serverId}`).stopListening('.ChannelActivity')
  }

  return { subscribe, unsubscribe }
}
