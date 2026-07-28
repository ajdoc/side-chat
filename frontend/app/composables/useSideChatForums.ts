import { useLocalStorage } from '@vueuse/core'
import type { SideChat, SideChatForum } from '~/types'

/**
 * The open channel's forum groups — the headings its side chat list folds under.
 *
 * Shared state on the same argument as {@link useSideChats}: the channel's real-time listener
 * keeps one copy fresh and the panel draws it, rather than each consumer fetching a list that
 * could disagree with the others. The key carries a scope prefix so the split view's docked
 * pane gets a parallel copy instead of fighting the main column for this one.
 */
export function useSideChatForums() {
  const api = useApi()
  // Prefixed so a docked split pane keeps its own list. See useChannelScope.
  const scope = useChannelScope()
  const forums = useState<SideChatForum[]>(`${scope}channel:sideChatForums`, () => [])
  /**
   * May you create a group here?
   *
   * Separate from the per-row `can_manage` because it has to survive there being no rows:
   * a channel with no groups yet is exactly when the "add a group" control matters, and
   * there'd be nothing to read the flag off. The server sends it as `meta.can_manage`.
   */
  const canManageForums = useState<boolean>(`${scope}channel:canManageForums`, () => false)

  async function loadForums(channelId: number) {
    const res = await api<{ data: SideChatForum[], meta?: { can_manage?: boolean } }>(
      `/api/channels/${channelId}/side-chat-forums`,
    )
    forums.value = res.data
    canManageForums.value = res.meta?.can_manage ?? false
  }

  /**
   * Add a group.
   *
   * Folded in by id rather than appended, because the append can lose a race it looks
   * incapable of losing: `SideChatForumsUpdated` is a `ShouldBroadcastNow` event, so the
   * websocket frame carrying the new list can — and over a local Reverb usually does —
   * arrive *before* this POST's own response. The handler has already replaced the list
   * with one containing the new group by the time we get here, and a blind append then
   * shows it twice.
   *
   * Upserting makes the two orderings converge on the same list, which is the property to
   * want: neither side has to know whether it went first.
   */
  async function createForum(channelId: number, name: string) {
    const res = await api<{ data: SideChatForum }>(`/api/channels/${channelId}/side-chat-forums`, {
      method: 'POST',
      body: { name },
    })
    upsert(res.data)
    return res.data
  }

  /** Replace a group in the list, or add it if the broadcast hasn't brought it yet. */
  function upsert(next: SideChatForum) {
    const idx = forums.value.findIndex(f => f.id === next.id)
    forums.value = idx === -1
      ? [...forums.value, next]
      : forums.value.map(f => (f.id === next.id ? { ...f, ...next } : f))
  }

  async function renameForum(forumId: number, name: string) {
    const res = await api<{ data: SideChatForum }>(`/api/side-chat-forums/${forumId}`, {
      method: 'PATCH',
      body: { name },
    })
    upsert(res.data)
    return res.data
  }

  /** Delete the group. Its posts aren't deleted — they fall back to Uncategorised. */
  async function removeForum(forumId: number) {
    await api(`/api/side-chat-forums/${forumId}`, { method: 'DELETE' })
    forums.value = forums.value.filter(f => f.id !== forumId)
  }

  /**
   * Move a group up or down. Sends the *whole* running order rather than one position,
   * because moving one group renumbers the ones it passed — see the request class.
   *
   * The local list is reordered first so the row moves under the finger; the response is
   * the server's answer and replaces it.
   */
  async function moveForum(channelId: number, forumId: number, delta: number) {
    const order = [...forums.value]
    const from = order.findIndex(f => f.id === forumId)
    const to = from + delta
    if (from < 0 || to < 0 || to >= order.length) return

    const [moved] = order.splice(from, 1)
    order.splice(to, 0, moved!)
    forums.value = order

    const res = await api<{ data: SideChatForum[] }>(`/api/channels/${channelId}/side-chat-forums/order`, {
      method: 'PUT',
      body: { ids: order.map(f => f.id) },
    })
    forums.value = res.data
  }

  /**
   * Which groups are folded shut, keyed `channelId:forumId` (and `channelId:none` for
   * Uncategorised).
   *
   * The *collapsed* set rather than the expanded one, so absence means open: a group
   * somebody else just created, or a channel you've never opened, shows its posts instead
   * of hiding them. Keyed by channel so folding a group in one doesn't fold anything in the
   * next — the same reasoning, and the same bug avoided, as the sidebar's channel-type
   * sections in {@link useSidebarChannels}.
   */
  const collapsed = useLocalStorage<string[]>('sideChats:collapsedForums', [])

  const groupKey = (channelId: number, forumId: number | null) => `${channelId}:${forumId ?? 'none'}`

  function isGroupOpen(channelId: number, forumId: number | null) {
    return !collapsed.value.includes(groupKey(channelId, forumId))
  }

  function toggleGroup(channelId: number, forumId: number | null) {
    const key = groupKey(channelId, forumId)
    collapsed.value = collapsed.value.includes(key)
      ? collapsed.value.filter(k => k !== key)
      : [...collapsed.value, key]
  }

  /**
   * The posts, cut into the groups the list draws — every group in order, then whatever is
   * left over under "Uncategorised".
   *
   * Uncategorised is appended only when it has something in it: an empty bucket for the
   * absence of a choice is a heading that means nothing. Named groups, by contrast, are
   * always drawn even when empty — somebody made that heading on purpose, and a group you
   * can't see is a group nobody will file anything into.
   */
  function groupPosts(posts: SideChat[]) {
    const groups = forums.value.map(forum => ({
      forum,
      posts: posts.filter(p => p.side_chat_forum_id === forum.id),
    }))

    const known = new Set(forums.value.map(f => f.id))
    // `!known.has(...)` rather than a plain null check: a post filed under a group that was
    // deleted a moment ago on someone else's screen must land somewhere visible, not vanish.
    const loose = posts.filter(p => p.side_chat_forum_id == null || !known.has(p.side_chat_forum_id))

    return loose.length
      ? [...groups, { forum: null, posts: loose }]
      : groups
  }

  return {
    forums,
    canManageForums,
    loadForums,
    createForum,
    renameForum,
    removeForum,
    moveForum,
    isGroupOpen,
    toggleGroup,
    groupPosts,
  }
}
