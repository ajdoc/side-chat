import type { NotifyLevel } from '~/types'

/**
 * Writing "how loud is this place" back to the server, and keeping the sidebar in step.
 *
 * The local patch matters as much as the request: the channel list carries `notify_level`
 * and `muted_until` so alerts can be decided without a round trip (see useNotifyPolicy),
 * so a setting that only landed on the server would leave this session still pinging for
 * a channel the user just muted.
 */
export function useNotificationSettings() {
  const api = useApi()
  const { patchChannel } = useServer()
  const { patch: patchConversation } = useConversations()

  interface Settings {
    notify_level: NotifyLevel | null
    muted_until: string | null
    effective_level: NotifyLevel
  }

  /**
   * @param body Only the keys present are written. Sending `notify_level` alone leaves an
   *   existing mute alone, and vice versa — the server distinguishes "absent" from "null",
   *   and null means *clear this*, so the two must not be conflated on the way out either.
   */
  async function save(
    target: { channelId?: number | null, conversationId?: number | null },
    body: { notify_level?: NotifyLevel | null, mute_minutes?: number | null },
  ): Promise<Settings | null> {
    const path = target.conversationId
      ? `/api/conversations/${target.conversationId}/notifications`
      : `/api/channels/${target.channelId}/notifications`

    const res = await api<Settings>(path, { method: 'PUT', body })

    if (target.conversationId) {
      patchConversation(target.conversationId, {
        notify_level: res.notify_level,
        muted_until: res.muted_until,
      })
    }
    else if (target.channelId) {
      patchChannel(target.channelId, {
        notify_level: res.notify_level,
        muted_until: res.muted_until,
      })
    }

    return res
  }

  const setLevel = (target: Parameters<typeof save>[0], level: NotifyLevel | null) =>
    save(target, { notify_level: level })

  /** Minutes of quiet, or null to lift it now. */
  const mute = (target: Parameters<typeof save>[0], minutes: number | null) =>
    save(target, { mute_minutes: minutes })

  return { save, setLevel, mute }
}
