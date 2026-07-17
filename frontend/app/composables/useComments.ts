import type { Comment, Message } from '~/types'

/**
 * Comments ("word-reactions") on a message.
 *
 * Deliberately thin: the aggregated chips live on the message itself (`message.comments`)
 * and are kept fresh by the channel/thread/side-chat stream — the same place reactions are
 * patched. This composable is only the write side (toggle a phrase, delete one) plus the
 * lazy fetch of the full list behind the chips. Each write returns the refreshed message so
 * the caller can fold it back into whichever timeline it came from.
 */
export function useComments() {
  const api = useApi()

  /** Post a comment, or take it back if you already left that exact phrase (a chip toggle). */
  async function toggle(messageId: number, body: string, emoji: string | null = null): Promise<Message> {
    const res = await api<{ data: Message }>(`/api/messages/${messageId}/comments`, {
      method: 'POST',
      body: { body, emoji },
    })
    return res.data
  }

  /** The full comment list for a message — loaded when the "see all" list is opened. */
  async function list(messageId: number): Promise<Comment[]> {
    const res = await api<{ data: Comment[] }>(`/api/messages/${messageId}/comments`)
    return res.data
  }

  /** Remove one of your own comments; returns the refreshed message. */
  async function remove(commentId: number): Promise<Message> {
    const res = await api<{ data: Message }>(`/api/comments/${commentId}`, { method: 'DELETE' })
    return res.data
  }

  return { toggle, list, remove }
}
