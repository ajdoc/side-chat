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

  return { threads, sideChatThreads, loadThreads, createThread, loadSideChatThreads, createSideChatThread }
}
