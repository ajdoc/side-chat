import type { User } from '~/types'

/** A server, or a DM/group chat — the two things a nickname can be scoped to. */
export interface NicknamePlace {
  kind: 'server' | 'conversation'
  id: number
}

/** Whose eyes a naming is for: everyone in the place, or only the person who set it. */
export type NicknameScope = 'public' | 'private'

type NameMap = Record<number, string>

function toNameMap(raw: Record<string, string> | undefined): NameMap {
  return Object.fromEntries(Object.entries(raw ?? {}).map(([id, name]) => [Number(id), name]))
}

/**
 * What people are called in the place you're currently in.
 *
 * Two maps, kept apart on purpose, laid one over the other:
 *
 *  - **public** — what everyone here calls this person. Their own choice, or the server
 *    owner's. This is the only name that means anything to anybody else, which makes it
 *    the one an @mention has to be written with.
 *  - **private** — what *you* call them, stored against you and shown to nobody else.
 *    Wins for display, because display is the only thing it can safely affect.
 *
 * Both are *sparse*: only people whose name is overridden appear, and everyone else falls
 * through to the name already on the user object. One place at a time is enough because
 * the app only ever shows one — a timeline, a roster, a call, a thread and its side chats
 * all belong to the same server or chat.
 *
 * Doing the substitution here rather than server-side, into every payload that carries a
 * name, is what makes private aliases possible at all: half those payloads are broadcasts,
 * and a broadcast has no one reader whose aliases could be applied to it.
 */
export function useNicknames() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const publicNames = useState<NameMap>('nicknames:public', () => ({}))
  const privateNames = useState<NameMap>('nicknames:private', () => ({}))
  const place = useState<NicknamePlace | null>('nicknames:place', () => null)

  /** What to actually show: my relabelling, else theirs, else nothing (their real name). */
  const names = computed<NameMap>(() => ({ ...publicNames.value, ...privateNames.value }))

  function pathFor(target: NicknamePlace) {
    return target.kind === 'server'
      ? `/api/servers/${target.id}/nicknames`
      : `/api/conversations/${target.id}/nicknames`
  }

  function channelFor(target: NicknamePlace) {
    return `${target.kind}.${target.id}`
  }

  function isCurrent(target: NicknamePlace) {
    return place.value?.kind === target.kind && place.value.id === target.id
  }

  /**
   * Switch to a place: load its names and listen for public renames while we're here.
   *
   * `stopListening` rather than `leave` on the way out — `server.{id}` and
   * `conversation.{id}` are shared streams that unread counts, join requests and the voice
   * roster are also listening on, and leaving the channel outright would take theirs down
   * with ours.
   */
  async function open(target: NicknamePlace) {
    if (isCurrent(target)) return

    close()
    place.value = target

    echo?.private(channelFor(target))
      .listen('.NicknameUpdated', (p: { user_id: number, nickname: string | null }) => {
        publicNames.value = patched(publicNames.value, p.user_id, p.nickname)
      })

    try {
      const res = await api<{ data: { public: Record<string, string>, private: Record<string, string> } }>(
        pathFor(target),
      )

      // A slow response mustn't land on a place we've since left — the same race
      // openServer and openConversation each guard against.
      if (!isCurrent(target)) return

      publicNames.value = toNameMap(res.data?.public)
      privateNames.value = toNameMap(res.data?.private)
    } catch {
      // A place whose names wouldn't load still shows everybody's real name. Not worth
      // putting an error in front of the user for.
    }
  }

  function close() {
    if (place.value) echo?.private(channelFor(place.value)).stopListening('.NicknameUpdated')
    place.value = null
    publicNames.value = {}
    privateNames.value = {}
  }

  /** A copy with one entry written, or removed when the naming was cleared. */
  function patched(map: NameMap, userId: number, nickname: string | null): NameMap {
    const next = { ...map }

    if (nickname) next[userId] = nickname
    else delete next[userId]

    return next
  }

  /**
   * What to call someone here. The single question every name in the UI should ask.
   *
   * Takes anything with an id and a name, because that's the shape names arrive in all
   * over the app — a full User on a message, a `{ id, name, avatar }` on a voice roster,
   * a member row on a participant list.
   */
  function nameFor(user: Pick<User, 'id' | 'name'> | null | undefined): string {
    if (!user) return ''

    return names.value[user.id] ?? user.name
  }

  /** Same, for the places that only ever had an id and a name to hand. */
  function nameOf(userId: number, fallback: string): string {
    return names.value[userId] ?? fallback
  }

  /**
   * The name to *write*, as opposed to the name to show — public only.
   *
   * An @mention lands in a message body everyone reads, and it's resolved by matching
   * that text against the roster. A private alias would match for nobody, so the composer
   * has to offer the name the whole place shares. See NicknameService::mentionNamesFor.
   */
  function publicNameFor(user: Pick<User, 'id' | 'name'>): string {
    return publicNames.value[user.id] ?? user.name
  }

  /** Whether this person is going by something other than their account name here. */
  function hasNickname(userId: number): boolean {
    return names.value[userId] !== undefined
  }

  /** The naming currently set at one scope, for prefilling an edit box. */
  function nicknameAt(userId: number, scope: NicknameScope): string {
    const map = scope === 'public' ? publicNames.value : privateNames.value

    return map[userId] ?? ''
  }

  /**
   * Set or clear a naming. A null/blank nickname clears it, uncovering whatever is
   * underneath — their public nickname here, or failing that their account name.
   *
   * The local map is patched from the response rather than optimistically. A public change
   * does reach everyone else over the socket, but `toOthers()` means it never comes back
   * to us; a private one is never broadcast at all.
   */
  async function setNickname(userId: number, nickname: string | null, scope: NicknameScope) {
    const target = place.value
    if (!target) return

    const res = await api<{ data: { user_id: number, nickname: string | null } }>(
      `${pathFor(target)}/${userId}`,
      { method: 'PUT', body: { nickname, scope } },
    )

    if (!isCurrent(target)) return

    if (scope === 'public') publicNames.value = patched(publicNames.value, userId, res.data.nickname)
    else privateNames.value = patched(privateNames.value, userId, res.data.nickname)
  }

  return {
    names,
    place,
    open,
    close,
    nameFor,
    nameOf,
    publicNameFor,
    hasNickname,
    nicknameAt,
    setNickname,
  }
}
