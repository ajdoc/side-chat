import type { Comment } from '~/types'

/**
 * Comments ("word-reactions") — on a message, or on a side chat *post*.
 *
 * Deliberately thin: the aggregated chips live on the thing they belong to
 * (`message.comments`, `sideChat.comments`) and are kept fresh by the channel/thread/
 * side-chat stream — the same place reactions are patched. This composable is only the
 * write side (toggle a phrase, delete one) plus the lazy fetch of the full list behind
 * the chips.
 *
 * Every call takes a {@link CommentSubject} rather than a bare id, because the two kinds
 * live at different URLs and mean genuinely different things: a comment on a message
 * annotates one line of a conversation, a comment on a post annotates the post. Passing
 * the pair keeps the caller honest about which it meant, and lets one CommentBar render
 * both.
 */

export type CommentSubject
  = { kind: 'message', id: number }
    | { kind: 'sideChat', id: number }

/** Where this subject's comments live. */
function basePath(subject: CommentSubject): string {
  return subject.kind === 'message'
    ? `/api/messages/${subject.id}/comments`
    : `/api/side-chats/${subject.id}/comments`
}

/**
 * Where an individual comment is deleted.
 *
 * Keyed off the *subject's* kind rather than the comment's, because the two are separate
 * tables: comment 7 on a message and comment 7 on a post are different rows, and only the
 * caller knows which list the id came out of.
 */
function deletePath(subject: CommentSubject, commentId: number): string {
  return subject.kind === 'message'
    ? `/api/comments/${commentId}`
    : `/api/side-chat-comments/${commentId}`
}

export function useComments() {
  const api = useApi()

  /**
   * Post a comment, or take it back if you already left that exact phrase (a chip toggle).
   *
   * The response is the refreshed *subject* — a Message or a SideChat — so it comes back
   * untyped rather than pretending to be one of them. Callers that care fold it into their
   * own list; most don't, because the broadcast is on its way regardless.
   */
  async function toggle(subject: CommentSubject, body: string, emoji: string | null = null): Promise<unknown> {
    const res = await api<{ data: unknown }>(basePath(subject), {
      method: 'POST',
      body: { body, emoji },
    })
    return res.data
  }

  /** The full comment list — loaded when the "see all" list is opened. */
  async function list(subject: CommentSubject): Promise<Comment[]> {
    const res = await api<{ data: Comment[] }>(basePath(subject))
    return res.data
  }

  /** Remove one of your own comments; returns the refreshed subject. */
  async function remove(subject: CommentSubject, commentId: number): Promise<unknown> {
    const res = await api<{ data: unknown }>(deletePath(subject, commentId), { method: 'DELETE' })
    return res.data
  }

  return { toggle, list, remove }
}
