import { useLocalStorage } from '@vueuse/core'

/**
 * Unsent message text, remembered per channel — the Viber-style draft.
 *
 * A conversation, a group and a server channel are all addressed by a channel id (a chat
 * *is* a channel here), so one flat `channelId -> text` map covers every place you can
 * type. Backed by localStorage so a half-written message outlives a reload, and shared
 * (one instance of the map) so the composer can save into it while the sidebar reads out
 * of it to flag which rows have something waiting.
 *
 * Text only: pending file attachments are live `File` objects that can't be serialised, and
 * a draft is about the words you didn't finish, not the picture you hadn't sent yet.
 */
export function useDrafts() {
  const drafts = useLocalStorage<Record<number, string>>('composer:drafts', {})

  function getDraft(channelId: number): string {
    return drafts.value[channelId] ?? ''
  }

  /** Store the draft, or drop it entirely once it's been emptied — no blank entries linger. */
  function setDraft(channelId: number, text: string) {
    if (text.trim()) drafts.value[channelId] = text
    else delete drafts.value[channelId]
  }

  function clearDraft(channelId: number) {
    delete drafts.value[channelId]
  }

  function hasDraft(channelId: number): boolean {
    return !!drafts.value[channelId]?.trim()
  }

  return { drafts, getDraft, setDraft, clearDraft, hasDraft }
}
