import type { InjectionKey, Ref } from 'vue'
import type { ChannelMember, MemberBadge } from '~/types'

/**
 * The channel roster — everyone who can be @mentioned here.
 *
 * Two consumers, one fetch: the composer's `@` autocomplete offers these names, and the
 * timeline renders `@Name` in a sent message as a chip by matching against them. The list
 * is small (a chat's handful, a server's members) and rarely changes mid-session, so it's
 * cached per channel and only fetched the first time you open one.
 */

/** Provided by the timeline so a message body deep in the virtual list can resolve chips. */
export const mentionNamesKey: InjectionKey<Ref<string[]>> = Symbol('channel-mention-names')

/**
 * The badges each member holds here, keyed by user id.
 *
 * Provided the same way, and for the same reason, as the mention names: the timeline needs
 * them on every author line, and handing the roster to each MessageItem would mean passing
 * the same array through the virtual list a hundred times.
 *
 * Reading badges off the *roster* rather than off each message is the whole trick. Badges
 * are per-server and the roster is already fetched, already cached and already scoped to
 * this channel — so putting them on the message payload instead would mean loading a
 * constrained relation in ten different query paths, an N+1 waiting to happen in each, and
 * a badge that only updates when the message is re-fetched.
 */
export const memberBadgesKey: InjectionKey<Ref<Record<number, MemberBadge[]>>> =
  Symbol('channel-member-badges')

export function useChannelMembers() {
  const api = useApi()
  const cache = useState<Record<number, ChannelMember[]>>('channel-members', () => ({}))

  const members = ref<ChannelMember[]>([])
  // Guards against a slow response landing after you've switched channels.
  const requestedId = ref<number | null>(null)

  /**
   * Every name that should render as a chip — account names *and* the public nicknames
   * people go by here.
   *
   * Both, because both are writable: the composer offers whichever name is current, but a
   * message sent before somebody was given a nickname still says their old one, and it
   * addressed them then and addresses them now. Mirrors MentionParser on the server, which
   * decides whose sidebar lights up off the same pair.
   */
  const { publicNameFor } = useNicknames()

  const names = computed(() => [
    ...new Set(members.value.flatMap(m => [m.name, publicNameFor(m)])),
  ])

  /** Badges by user id, for the timeline's author lines. Members without any are omitted. */
  const badges = computed(() => Object.fromEntries(
    members.value.filter(m => m.badges?.length).map(m => [m.id, m.badges!]),
  ) as Record<number, MemberBadge[]>)

  /**
   * @param force Skip the cache. The roster is stable enough to cache for autocomplete,
   * but the roles settings *edit* it, and reopening onto a cached list would show the
   * change undone. Anything that writes a member's role invalidates by asking again.
   */
  async function load(channelId: number, force = false) {
    requestedId.value = channelId

    const cached = cache.value[channelId]
    if (cached && !force) {
      members.value = cached
      return
    }

    try {
      const res = await api<{ data: ChannelMember[] }>(`/api/channels/${channelId}/members`)
      cache.value = { ...cache.value, [channelId]: res.data }
      if (requestedId.value === channelId) members.value = res.data
    }
    catch {
      // Autocomplete and chips are a nicety — if the roster won't load, the channel still works.
    }
  }

  return { members, names, badges, load }
}
