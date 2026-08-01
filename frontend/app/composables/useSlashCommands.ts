import type { SlashCommand } from '~/types'

/**
 * What `/` offers in a channel.
 *
 * Fetched rather than hard-coded because half the list isn't ours: bots register their own
 * commands, and which of them are callable depends on the channel (a bot that isn't in a
 * private channel can't be reached from it). The server answers with exactly the same list
 * `/help` prints, so the menu and the help text can never disagree.
 *
 * Cached per channel like the roster next door — it changes only when a bot is deployed or
 * added, which is not something a session needs to watch for. A failure is swallowed: the
 * menu simply doesn't appear, and every command can still be typed out in full.
 */
export function useSlashCommands() {
  const api = useApi()
  const cache = useState<Record<number, SlashCommand[]>>('channel-commands', () => ({}))

  const commands = ref<SlashCommand[]>([])
  // Guards against a slow response landing after you've switched channels.
  const requestedId = ref<number | null>(null)

  async function load(channelId: number) {
    requestedId.value = channelId

    const cached = cache.value[channelId]
    if (cached) {
      commands.value = cached
      return
    }

    try {
      const res = await api<{ data: SlashCommand[] }>(`/api/channels/${channelId}/commands`)
      cache.value = { ...cache.value, [channelId]: res.data }
      if (requestedId.value === channelId) commands.value = res.data
    }
    catch {
      // Autocomplete is a convenience, not the feature — the channel still works without it.
    }
  }

  return { commands, load }
}
