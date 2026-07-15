import type { Message } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

/**
 * Pinned messages in a channel — the Info panel's Pinned tab.
 *
 * The list is shared state rather than a per-call ref, and it is deliberately *not*
 * subscribed to anything here. The `channel.{id}` stream is opened and torn down by
 * useMessages, which already owns it; a second listener for the same event on the same
 * Echo channel is the kind of thing that survives a `stopListening` by accident and
 * double-applies. So useMessages calls `apply()` below when a pin toggles, exactly as it
 * already does for the shared `channel:threads` list.
 */
export function usePins() {
  const api = useApi()
  const pins = useState<Message[]>('channel:pins', () => [])
  const page = useState<number>('channel:pins:page', () => 1)
  const lastPage = useState<number>('channel:pins:lastPage', () => 1)
  const loading = ref(false)

  const hasMore = computed(() => page.value < lastPage.value)

  async function load(channelId: number) {
    loading.value = true
    try {
      const res = await api<Paginated<Message>>(`/api/channels/${channelId}/pins?page=1`)
      pins.value = res.data
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  async function loadMore(channelId: number) {
    if (!hasMore.value || loading.value) return
    loading.value = true
    try {
      const res = await api<Paginated<Message>>(`/api/channels/${channelId}/pins?page=${page.value + 1}`)
      const seen = new Set(pins.value.map(p => p.id))
      pins.value = [...pins.value, ...res.data.filter(p => !seen.has(p.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  /** Pin, or unpin if it's already pinned. Any member may do either. */
  async function toggle(messageId: number) {
    const res = await api<{ data: Message }>(`/api/messages/${messageId}/pin`, { method: 'POST' })
    apply(res.data.pinned, res.data)
    return res.data
  }

  /**
   * Fold a pin/unpin into the list, from our own call or somebody else's broadcast.
   *
   * Newest pin first, matching the server's ordering — a message pinned just now belongs
   * at the top even when it was written months ago.
   */
  function apply(pinned: boolean, message: Message) {
    if (!pinned) {
      pins.value = pins.value.filter(p => p.id !== message.id)
      return
    }
    pins.value = [message, ...pins.value.filter(p => p.id !== message.id)]
  }

  return { pins, hasMore, loading, load, loadMore, toggle, apply }
}
