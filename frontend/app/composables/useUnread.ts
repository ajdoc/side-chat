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

  const activeChannelId = computed(() => Number(route.params.channelId) || null)

  function bump(channelId: number) {
    const idx = channels.value.findIndex(c => c.id === channelId)
    if (idx === -1) return

    const channel = channels.value[idx]!
    channels.value.splice(idx, 1, { ...channel, unread_count: (channel.unread_count ?? 0) + 1 })
  }

  function subscribe(serverId: number) {
    if (!echo) return

    echo.private(`server.${serverId}`)
      .listen('.ChannelActivity', (a: { channel_id: number, user_id: number }) => {
        if (a.user_id === user.value?.id) return

        // The channel open *in front of you* is about to be marked read, so badging it
        // would only flicker. Open in a background tab is a different story — nobody is
        // reading that, and useReads only marks read once the tab is visible again.
        const looking = a.channel_id === activeChannelId.value && document.visibilityState === 'visible'
        if (looking) return

        bump(a.channel_id)
      })
  }

  function unsubscribe(serverId: number) {
    // Not echo.leave() — useJoinRequests shares this channel and owns tearing it down.
    echo?.private(`server.${serverId}`).stopListening('.ChannelActivity')
  }

  return { subscribe, unsubscribe }
}
