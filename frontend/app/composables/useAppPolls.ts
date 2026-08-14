import type { AppPoll, AppPollType, AppReactionSummary } from '~/types'

/**
 * A channel's Polls — the wall, and voting on what's on it.
 *
 * Same contract as {@link useTracker}: base path plus stream, state in a
 * {@link useSurfaceStore} so a polls channel and the same polls in a floating window are one
 * list. It rides the tracker's `TrackerChanged` broadcast under the `poll` subject — see that
 * event for why the apps share one.
 *
 * The wall holds whole polls including their counted results, because a poll card *is* its
 * results — there is no lighter version worth fetching. What the wall leaves out is the comment
 * threads, which arrive when a poll is opened.
 */
export function useAppPolls(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()

  const { state, attach } = useSurfaceStore('polls', basePath, () => ({
    polls: ref<AppPoll[]>([]),
    loaded: ref(false),
  }))

  const { polls, loaded } = state

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** Open polls first, newest first within each — the order the server sends and the wall draws. */
  function sort() {
    polls.value = [...polls.value].sort((a: AppPoll, b: AppPoll) =>
      Number(a.closed) - Number(b.closed)
      || new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
  }

  function upsert(poll: AppPoll) {
    const idx = polls.value.findIndex((p: AppPoll) => p.id === poll.id)
    // Merged, not replaced: a broadcast from somebody else's vote carries no comments, and a
    // poll you have open would otherwise have its thread wiped out by their click.
    if (idx === -1) polls.value = [...polls.value, poll]
    else polls.value.splice(idx, 1, { ...polls.value[idx], ...poll })
    sort()
  }

  async function load() {
    const res = await api<{ data: AppPoll[] }>(`${basePath}/polls`)
    polls.value = res.data
    loaded.value = true
  }

  /** One poll with its comment thread — the detail view's extra load. */
  async function loadOne(id: number) {
    const res = await api<{ data: AppPoll }>(`${basePath}/polls/${id}`)
    upsert(res.data)
    return res.data
  }

  async function add(input: {
    type: AppPollType
    question: string
    description?: string | null
    anonymous?: boolean
    options?: string[]
  }) {
    const res = await api<{ data: AppPoll }>(`${basePath}/polls`, {
      method: 'POST', body: input, headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  async function patch(id: number, changes: { question?: string, description?: string | null, closed?: boolean }) {
    const res = await api<{ data: AppPoll }>(`${basePath}/polls/${id}`, {
      method: 'PATCH', body: changes, headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  /**
   * Cast or change your answer.
   *
   * The full set you now stand behind, not a delta — so un-voting is `[]` and changing your mind
   * on a single-choice poll is one call. See the controller for why a delta would go stale.
   */
  async function vote(id: number, optionIds: number[]) {
    const res = await api<{ data: AppPoll }>(`${basePath}/polls/${id}/vote`, {
      method: 'PUT', body: { option_ids: optionIds }, headers: socketHeaders(),
    })
    upsert(res.data)
    return res.data
  }

  async function remove(id: number) {
    const prev = polls.value
    polls.value = polls.value.filter((p: AppPoll) => p.id !== id)
    try {
      await api(`${basePath}/polls/${id}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      polls.value = prev
      throw e
    }
  }

  /** Toggle an emoji on a poll. The server answers with the whole chip row — see the controller. */
  async function react(id: number, emoji: string) {
    const res = await api<{ reactions: AppReactionSummary[] }>(
      `${basePath}/apps/app_poll/${id}/reactions`,
      { method: 'POST', body: { emoji }, headers: socketHeaders() },
    )
    const idx = polls.value.findIndex((p: AppPoll) => p.id === id)
    if (idx !== -1) polls.value.splice(idx, 1, { ...polls.value[idx], reactions: res.reactions })
    return res.reactions
  }

  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = hold(streamName)
      channel.listen('.TrackerChanged', (e: { subject: string, action: string, payload: any }) => {
        if (e.subject !== 'poll') return
        if (e.action === 'removed') polls.value = polls.value.filter((p: AppPoll) => p.id !== e.payload.id)
        else upsert(e.payload)
      })

      return () => {
        channel.stopListening('.TrackerChanged')
        release(streamName)
      }
    })
  }

  return { polls, loaded, open, load, loadOne, add, patch, vote, remove, react }
}
