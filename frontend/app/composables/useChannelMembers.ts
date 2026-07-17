import type { InjectionKey, Ref } from 'vue'
import type { ChannelMember } from '~/types'

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

export function useChannelMembers() {
  const api = useApi()
  const cache = useState<Record<number, ChannelMember[]>>('channel-members', () => ({}))

  const members = ref<ChannelMember[]>([])
  // Guards against a slow response landing after you've switched channels.
  const requestedId = ref<number | null>(null)

  const names = computed(() => members.value.map(m => m.name))

  async function load(channelId: number) {
    requestedId.value = channelId

    const cached = cache.value[channelId]
    if (cached) {
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

  return { members, names, load }
}
