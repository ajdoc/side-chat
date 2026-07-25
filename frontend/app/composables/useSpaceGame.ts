import type { SpaceGameInfo, SpaceGamePayload } from '~/types'

/**
 * The game living in a Side Space, from the client's side.
 *
 * The counterpart of {@link useSpaceMap}: loaded once over HTTP, then kept current over the
 * channel's own stream. Nothing here is fast — a game's *moves* are rare (a task, a kill, a vote)
 * where movement is constant, so this goes through the server and broadcasts, exactly as widgets
 * do, rather than being whispered like position.
 *
 * ## Why every change is a refetch
 *
 * The server can't broadcast the state, because there *is* no one state — who the impostor is
 * isn't the same fact for every viewer. So {@link SpaceGameUpdated} carries only a ping, and each
 * client GETs its own redacted view. That's the whole reason a game and a map are loaded the same
 * way but a game can never ride the socket the way a cursor does.
 */
export function useSpaceGame(channelId: number) {
  const api = useApi()
  const echo: any = import.meta.client ? useNuxtApp().$echo : null

  const game = ref<SpaceGamePayload | null>(null)
  const catalogue = ref<SpaceGameInfo[]>([])

  // Held so teardown pulls our handler off the exact channel object we joined — the message
  // stream and the map live on this same name, and re-privating it would resurrect a channel
  // somebody else is tearing down. Same dance as useSpaceMap / useMessages.
  let channel: any = null

  async function load() {
    try {
      const res = await api<{ data: { game: SpaceGamePayload | null } }>(`/api/channels/${channelId}/space/game`)
      game.value = res.data.game
    } catch {
      game.value = null
    }
  }

  /** The games that can be proposed. Fetched once — the menu is the same in every room. */
  async function loadCatalogue() {
    if (catalogue.value.length) return
    try {
      const res = await api<{ data: SpaceGameInfo[] }>('/api/space/games')
      catalogue.value = res.data
    } catch {
      catalogue.value = []
    }
  }

  function apply(res: { data: { game: SpaceGamePayload | null } }) {
    game.value = res.data.game
  }

  /**
   * Put a game to the room, or challenge one person to a duel.
   *
   * `opponent` is the challenged player for a challenge-mode game (a pet battle) and left off for
   * a room-wide one (Among Us) — the server knows which from the game's own start mode.
   */
  async function propose(type: string, opponent?: number) {
    apply(await api(`/api/channels/${channelId}/space/game`, { method: 'POST', body: { type, opponent } }))
  }

  /** Vote the proposed game in, or not. A majority of the room tips it into starting. */
  async function vote(yes: boolean) {
    apply(await api(`/api/channels/${channelId}/space/game/vote`, { method: 'POST', body: { vote: yes } }))
  }

  /** Make a move — a task, a kill, a report, a vote. The handler decides what it means. */
  async function act(action: string, payload: Record<string, unknown> = {}) {
    apply(await api(`/api/channels/${channelId}/space/game/act`, { method: 'POST', body: { action, payload } }))
  }

  /** End it — a finished game, or a vote nobody wants. Anyone in the room may. */
  async function cancel() {
    apply(await api(`/api/channels/${channelId}/space/game`, { method: 'DELETE' }))
  }

  function subscribe() {
    if (!echo) return
    channel = echo.private(`channel.${channelId}`)
    channel.listen('.SpaceGameUpdated', () => { void load() })
  }

  function unsubscribe() {
    // Not echo.leave() — useMessages/useReads/useSpaceMap share this channel and own it.
    channel?.stopListening('.SpaceGameUpdated')
    channel = null
  }

  return { game, catalogue, load, loadCatalogue, propose, vote, act, cancel, subscribe, unsubscribe }
}
