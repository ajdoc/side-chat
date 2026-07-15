import type { Thread } from '~/types'

// Threads that belong to the open channel (the Threads list). Shared state so the
// channel's real-time listener can keep counts fresh while the panel renders them.
export function useThreads() {
  const api = useApi()
  const threads = useState<Thread[]>('channel:threads', () => [])

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

  return { threads, loadThreads, createThread }
}
