import type { NotifyDefaults, NotifyLevel, NotifyTarget } from '~/lib/notifyPolicy'
import { admits, DEFAULT_LEVELS, isMuted, resolveLevel } from '~/lib/notifyPolicy'

export type { NotifyTarget }

/**
 * The app's view of "how loud is this place", over the pure rules in lib/notifyPolicy.
 *
 * All this adds is where the data comes from: the signed-in user's defaults, and the parent
 * channel a discussion inherits from. The rules themselves live in the library so they can
 * be tested — and so there is one obvious file to keep in step with the server's copy.
 *
 * Why the client resolves this at all, rather than asking: the server already decides who
 * gets a *push*, but a desktop alert is decided here, on a message that has just arrived
 * over the websocket. Asking the API per message would put a round trip on the hot path of
 * every message in every channel. So the levels ride along on the channel list instead.
 */
export function useNotifyPolicy() {
  const { user } = useAuth()
  const { findChannel } = useServer()

  const defaults = computed<NotifyDefaults>(() => ({
    channel: user.value?.notify_channel_default ?? DEFAULT_LEVELS.channel,
    dm: user.value?.notify_dm_default ?? DEFAULT_LEVELS.dm,
  }))

  function levelFor(target: NotifyTarget | null | undefined): NotifyLevel {
    const parent = target?.parent_id ? findChannel(target.parent_id) : null

    return resolveLevel(target, parent, defaults.value)
  }

  /** Should an arriving message of this kind raise an alert here? */
  function shouldAlert(target: NotifyTarget | null | undefined, isMention: boolean): boolean {
    return admits(levelFor(target), isMention)
  }

  return { levelFor, shouldAlert, muted: (t: NotifyTarget | null | undefined) => isMuted(t) }
}
