/**
 * Deletions, as they happen to *other* people's screens.
 *
 * Someone with a channel open when it's deleted is the case worth caring about: without
 * this they keep an open subscription to a stream with nothing behind it, a sidebar row
 * that 404s on click, and a composer that posts into a channel that no longer exists.
 *
 * Rides the server-wide stream, because that's the only one everybody has open — you are
 * subscribed to `channel.{id}` only for the channel you're looking at, which is precisely
 * the channel a deletion notice would fail to reach for everyone else.
 */
export function useServerLifecycle() {
  const echo: any = useNuxtApp().$echo
  const route = useRoute()
  const { user } = useAuth()
  const { channels, forgetChannel, patchChannel, patchServer } = useServer()
  const { forget: forgetServer, patch: patchServerInRail } = useServers()
  const { channelId: callChannelId, disconnect } = useVoice()

  /**
   * Hang up if the call we're in was in the server that just went away.
   *
   * A call deliberately outlives the page it was started from (that's the whole point of
   * VoiceBar), so neither navigating away nor losing the server ends one on its own — the
   * peer connections would happily stay up in a server this user is no longer in.
   */
  function hangUpIfInside(serverId: number) {
    if (callChannelId.value === null) return
    if (Number(route.params.serverId) !== serverId) return
    if (!channels.value.some(c => c.id === callChannelId.value)) return

    disconnect()
  }

  function subscribe(serverId: number) {
    if (!echo) return

    echo.private(`server.${serverId}`)
      // A rename is only ever the name — patched in, never swapped in wholesale. The
      // broadcast has no single asker, so it can't carry the per-viewer bits (a channel's
      // unread_count, a server's is_owner) and must not be allowed to overwrite them.
      .listen('.ChannelUpdated', (c: { id: number, name: string }) => {
        patchChannel(c.id, { name: c.name })
      })
      .listen('.ServerUpdated', (p: { server_id: number, name: string }) => {
        patchServer(p.server_id, { name: p.name })
        patchServerInRail(p.server_id, { name: p.name })
      })
      .listen('.ChannelDeleted', (p: { channel_id: number }) => {
        forgetChannel(p.channel_id)

        // A call deliberately outlives the page it was started from, so navigating away is
        // not enough to end one — hang up explicitly, or they'd be left talking into a
        // channel that no longer exists.
        if (callChannelId.value === p.channel_id) disconnect()

        // Standing in the room that was just demolished: step out to the server, rather
        // than leave them looking at a timeline that can no longer be sent to.
        if (Number(route.params.channelId) === p.channel_id) {
          navigateTo(`/servers/${serverId}`)
        }
      })
      .listen('.ServerDeleted', (p: { server_id: number }) => {
        hangUpIfInside(p.server_id)
        forgetServer(p.server_id)

        if (Number(route.params.serverId) === p.server_id) {
          navigateTo('/')
        }
      })
      .listen('.MemberLeft', (p: { server_id: number, user_id: number }) => {
        // Only interesting when it's us — we left from another tab. Everyone else's
        // departure is already reflected by the roster events it triggers.
        if (p.user_id !== user.value?.id) return

        hangUpIfInside(p.server_id)
        forgetServer(p.server_id)
        if (Number(route.params.serverId) === p.server_id) navigateTo('/')
      })
  }

  function unsubscribe(serverId: number) {
    // Not echo.leave() — useJoinRequests shares this channel and owns tearing it down.
    echo?.private(`server.${serverId}`)
      .stopListening('.ChannelUpdated')
      .stopListening('.ServerUpdated')
      .stopListening('.ChannelDeleted')
      .stopListening('.ServerDeleted')
      .stopListening('.MemberLeft')
  }

  return { subscribe, unsubscribe }
}
