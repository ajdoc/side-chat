import type { Conversation, User } from '~/types'

/**
 * What to call a chat, from where *you* are standing.
 *
 * Worked out here rather than sent by the API, and that's deliberate. A DM is called "Ana"
 * to you and "Ben" to Ana — it is the one thing in the app whose name genuinely depends on
 * who is reading it. The server can't put a title in the payload because the payload is
 * *broadcast*: one message, many recipients, and a baked-in title would be wrong for half
 * of them. So the API sends the members and the client does the subtraction.
 */
export function conversationTitle(conversation: Conversation, viewer: User | null): string {
  if (conversation.type === 'group') return conversation.name ?? 'Group chat'

  const other = conversation.members.find(m => m.id !== viewer?.id)

  // A DM with yourself is your own notes, and is a legitimate thing to have.
  return other?.name ?? viewer?.name ?? 'Direct message'
}

/** The people in a group chat other than you — for the "Ana, Ben and 2 others" subtitle. */
export function otherMembers(conversation: Conversation, viewer: User | null): User[] {
  return conversation.members.filter(m => m.id !== viewer?.id)
}

/** The face on a chat row: the other person in a DM, nobody in particular in a group. */
export function conversationAvatar(conversation: Conversation, viewer: User | null): string | null {
  if (conversation.type === 'group') return null

  return conversation.members.find(m => m.id !== viewer?.id)?.avatar ?? null
}

export function initialsOf(name: string): string {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
