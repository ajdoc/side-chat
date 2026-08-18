import type { Meeting, MeetingPreview, User } from '~/types'

/**
 * Meetings — making one, and following a link to one.
 *
 * ## What a meeting is here
 *
 * A room, a link to it, and optionally a time. Where the room comes from is decided by what's
 * sent: a server makes a channel there, nothing makes a **group conversation** whose channel is
 * the room. That second shape is what lets somebody from outside your server be in the meeting —
 * they end up in a group chat, which is already this app's answer to "these particular people".
 *
 * ## The link
 *
 * `{origin}/meet/{token}`, composed here. The API never emits it: a path is the frontend's, and a
 * server that wrote one would be a second place the routes are written down.
 *
 * Following it depends on how far open the meeting's door is. **`guest`** lets anybody in with a
 * name and no account; **`account`** sends a signed-out visitor to log in and returns them here;
 * **`members`** means the link is only the address, for people who could already come. The page
 * asks the API which, rather than deciding — the answer belongs to whoever made the meeting.
 */
export function useMeetings() {
  const api = useApi()

  /** The shareable address of a meeting. Built, not served — see the class comment. */
  function linkFor(token: string) {
    const origin = import.meta.client ? window.location.origin : ''
    return `${origin}/meet/${token}`
  }

  /**
   * Make one.
   *
   * `server_id` puts the room in that server; omitting it makes a group chat. `type` defaults to
   * voice server-side, because that is what "a meeting" means to nearly everybody.
   */
  async function create(body: {
    title: string
    type?: 'voice' | 'space'
    server_id?: number | null
    /** An existing room. Nothing is created — the meeting is a link to what's already there. */
    channel_id?: number | null
    starts_at?: string | null
    remind_minutes?: number | null
    /** How far open the door is: 'members' | 'account' | 'guest'. */
    access?: 'members' | 'account' | 'guest'
  }) {
    const res = await api<{ data: Meeting }>('/api/meetings', { method: 'POST', body })
    return res.data
  }

  /**
   * This room's meeting links.
   *
   * Any member may read them — a link is exactly the thing they're entitled to pass on. (Who
   * *used* one is the audit, and is not.)
   */
  async function forChannel(channelId: number) {
    const res = await api<{ data: Meeting[] }>(`/api/channels/${channelId}/meeting-links`)
    return res.data
  }

  /**
   * The link for a room you're standing in — the one it already has, or a new one.
   *
   * "Get the link" is one gesture whether or not somebody has made one before, because from the
   * asker's side it is one question. A room with no meeting row yet gets one pointing at itself:
   * no channel is created, since the room is right here.
   */
  async function ensureFor(channelId: number, title: string) {
    const existing = await forChannel(channelId)
    if (existing[0]) return existing[0]

    return create({ title, channel_id: channelId })
  }

  /** What a link leads to, before anybody commits to following it. Thin on purpose. */
  async function preview(token: string) {
    const res = await api<{ data: MeetingPreview }>(`/api/meetings/${token}`)
    return res.data
  }

  /**
   * Walk in with no account: a name, and you're in.
   *
   * The response is a real session — the same token shape a sign-in returns — so the caller
   * stores it exactly as it would a login and every other endpoint works for the guest
   * unchanged. What a guest *may* reach is settled server-side by ConfineGuests, not here.
   */
  async function joinAsGuest(token: string, name: string) {
    const res = await api<{ token: string, user: User, meeting: Meeting }>(
      `/api/meetings/${token}/guest`,
      { method: 'POST', body: { name } },
    )
    return res
  }

  /** Follow it. Admits an outsider only to a group meeting; the server refuses the rest. */
  async function join(token: string) {
    const res = await api<{ data: Meeting }>(`/api/meetings/${token}/join`, { method: 'POST' })
    return res.data
  }

  /**
   * The path to a meeting's room, once you're in it.
   *
   * The same `/servers/…` and `/chats/…` shapes the search panel builds, off the ids the API
   * returned — a group meeting lands in Chats, a server meeting in the server.
   */
  function roomPath(meeting: Meeting, options: { call?: boolean } = {}) {
    const room = meeting.room
    if (!room) return '/'

    const base = room.conversation_id
      ? `/chats/${room.conversation_id}`
      : `/servers/${room.server_id}/channels/${room.id}`

    /*
     * `?call=1` — "and put me in the call".
     *
     * Carried in the URL rather than in a store because the room is a *navigation* away, and
     * this is one more fact about where you're going, like `?sidechat=` and `?desk=`. It also
     * means the meeting page doesn't have to know how a voice channel or a Side Space connects
     * — each room already knows, and this only says that you want it to.
     */
    return options.call ? `${base}?call=1` : base
  }

  return { linkFor, create, forChannel, ensureFor, preview, join, joinAsGuest, roomPath }
}
