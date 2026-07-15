import type { Attachment } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

// Files posted in a channel — the Info panel's Files tab.
export function useChannelFiles() {
  const api = useApi()
  const files = ref<Attachment[]>([])
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)

  const hasMore = computed(() => page.value < lastPage.value)

  async function load(channelId: number) {
    loading.value = true
    try {
      const res = await api<Paginated<Attachment>>(`/api/channels/${channelId}/attachments?page=1`)
      files.value = res.data
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
      const res = await api<Paginated<Attachment>>(`/api/channels/${channelId}/attachments?page=${page.value + 1}`)
      const seen = new Set(files.value.map(f => f.id))
      files.value = [...files.value, ...res.data.filter(f => !seen.has(f.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  return { files, hasMore, loading, load, loadMore }
}
