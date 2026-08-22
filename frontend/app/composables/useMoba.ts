import type { MobaCatalogue, MobaHero, MobaMatch, MobaMe } from '~/types'

/**
 * The MOBA's metagame: the roster, the queue, and getting a seat.
 *
 * ## What this does not do
 *
 * It does not play the game. The match runs at 30Hz in a separate Rust process and the client
 * half of it is a wasm bundle that owns a canvas and its own WebSocket — see MOBA.md. This
 * composable's job ends the moment it has a ticket and an address to hand that bundle.
 *
 * That division is why there is no game state here at all: no positions, no health, no abilities.
 * Mixing them in would mean Vue reactivity in the path of a 60fps render loop, which is the one
 * place it must not be.
 */
export function useMoba() {
  const api = useApi()

  const catalogue = ref<MobaCatalogue | null>(null)
  const me = ref<MobaMe | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  /** Which hero the player has picked. Local until they queue with it. */
  const hero = ref<string>('ironclad')
  const teamSize = ref<number>(1)

  const heroes = computed<MobaHero[]>(() => catalogue.value?.heroes ?? [])
  const match = computed<MobaMatch | null>(() => me.value?.match ?? null)
  const queued = computed(() => me.value?.queued ?? false)

  /**
   * A match is ready to play once it has a ticket and somewhere to send it.
   *
   * Both, not either: a match row exists from the moment matchmaking forms a roster, which is
   * before the game server necessarily has it, and launching at that point would connect to
   * nothing.
   */
  const ready = computed(() => !!match.value?.ticket && !!match.value?.server_address)

  async function loadCatalogue() {
    catalogue.value = await api<MobaCatalogue>('/api/moba/catalogue')
  }

  async function refresh() {
    me.value = await api<MobaMe>('/api/moba/me')
  }

  async function join(channelId?: number) {
    loading.value = true
    error.value = null
    try {
      me.value = await api<MobaMe>('/api/moba/queue', {
        method: 'POST',
        body: { team_size: teamSize.value, hero: hero.value, channel_id: channelId },
      })
    }
    catch (e: any) {
      error.value = e?.data?.message ?? 'Could not join the queue.'
    }
    finally {
      loading.value = false
    }
  }

  async function leave() {
    me.value = await api<MobaMe>('/api/moba/queue', { method: 'DELETE' })
  }

  /**
   * Give up on a match in progress.
   *
   * Ends it for everyone — see the server side for why. Also the only way out of a match that
   * never started properly, which without it leaves every player in it unable to queue again:
   * the API counts an unfinished match as a commitment, and nothing else clears one.
   */
  async function leaveMatch() {
    const id = match.value?.id
    if (!id) return
    me.value = await api<MobaMe>(`/api/moba/matches/${id}/leave`, { method: 'POST' })
  }

  /**
   * Poll while waiting.
   *
   * Two seconds, and only while actually queued. The endpoint also *forms* matches, so polling
   * is what advances the queue — but a poll per second per idle lobby would be a lot of writes
   * for a screen nobody is waiting on.
   */
  let timer: ReturnType<typeof setInterval> | null = null

  function startPolling() {
    stopPolling()
    timer = setInterval(async () => {
      if (!queued.value && !match.value) return
      try {
        me.value = await api<MobaMe>('/api/moba/queue')
      }
      catch {
        // A failed poll is not worth showing: the next one is two seconds away, and a red
        // banner that clears itself teaches people to ignore red banners.
      }
    }, 2000)
  }

  function stopPolling() {
    if (timer) clearInterval(timer)
    timer = null
  }

  onScopeDispose(stopPolling)

  return {
    catalogue, me, heroes, match, queued, ready, loading, error,
    hero, teamSize,
    loadCatalogue, refresh, join, leave, leaveMatch, startPolling, stopPolling,
  }
}
