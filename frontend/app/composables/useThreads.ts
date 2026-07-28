import type { Thread } from '~/types'

// Threads that belong to the open channel (the Threads list). Shared state so the
// channel's real-time listener can keep counts fresh while the panel renders them.
//
// A side chat's own threads are kept in a *separate* list — they're a different scope, and
// pouring them into `channel:threads` would let the channel stream and the side chat cross
// over each other. The ThreadPanel picks whichever list matches how it was opened.
export function useThreads() {
  const api = useApi()
  const threads = useState<Thread[]>('channel:threads', () => [])
  const sideChatThreads = useState<Thread[]>('sidechat:threads', () => [])

  async function loadThreads(channelId: number) {
    const res = await api<{ data: Thread[] }>(`/api/channels/${channelId}/threads`)
    threads.value = res.data
  }

  async function createThread(channelId: number, payload: { name: string, message_id?: number | null }) {
    const res = await api<{ data: Thread }>(`/api/channels/${channelId}/threads`, {
      method: 'POST',
      body: payload,
    })
    return res.data
  }

  async function loadSideChatThreads(sideChatId: number) {
    const res = await api<{ data: Thread[] }>(`/api/side-chats/${sideChatId}/threads`)
    sideChatThreads.value = res.data
  }

  async function createSideChatThread(sideChatId: number, payload: { name: string, message_id?: number | null }) {
    const res = await api<{ data: Thread }>(`/api/side-chats/${sideChatId}/threads`, {
      method: 'POST',
      body: payload,
    })
    return res.data
  }

  /**
   * Retitle a thread. The server broadcasts `.ThreadUpdated`, which the channel timeline
   * already folds in (it's the same event an edited parent message fires), so both lists
   * are patched here only so the panel doesn't wait a round trip to look right.
   */
  async function renameThread(threadId: number, name: string) {
    const res = await api<{ data: Thread }>(`/api/threads/${threadId}`, {
      method: 'PATCH',
      body: { name },
    })
    patch(threadId, res.data)
    return res.data
  }

  /** Delete a thread and every reply in it. `.ThreadDeleted` tells everyone else. */
  async function deleteThread(threadId: number) {
    await api(`/api/threads/${threadId}`, { method: 'DELETE' })
    threads.value = threads.value.filter(t => t.id !== threadId)
    sideChatThreads.value = sideChatThreads.value.filter(t => t.id !== threadId)
  }

  /** Fold a fresh copy into whichever of the two lists happens to be holding it. */
  function patch(threadId: number, next: Thread) {
    const apply = (list: Thread[]) => list.map(t => (t.id === threadId ? { ...t, ...next } : t))
    threads.value = apply(threads.value)
    sideChatThreads.value = apply(sideChatThreads.value)
  }

  return { threads, sideChatThreads, loadThreads, createThread, loadSideChatThreads, createSideChatThread, renameThread, deleteThread }
}
