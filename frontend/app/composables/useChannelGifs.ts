import type { Attachment } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

// GIFs posted in a channel — the Info panel's GIFs tab. Same shape as useChannelFiles.
export function useChannelGifs() {
  const api = useApi()
  const gifs = ref<Attachment[]>([])
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)

  const hasMore = computed(() => page.value < lastPage.value)

  async function load(channelId: number) {
    loading.value = true
    try {
      const res = await api<Paginated<Attachment>>(`/api/channels/${channelId}/gifs?page=1`)
      gifs.value = res.data
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
      const res = await api<Paginated<Attachment>>(`/api/channels/${channelId}/gifs?page=${page.value + 1}`)
      const seen = new Set(gifs.value.map(g => g.id))
      gifs.value = [...gifs.value, ...res.data.filter(g => !seen.has(g.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  return { gifs, hasMore, loading, load, loadMore }
}
