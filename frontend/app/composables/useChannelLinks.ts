import type { ChannelLink } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

// Links shared in a channel — the Info panel's Links tab.
export function useChannelLinks() {
  const api = useApi()
  const links = ref<ChannelLink[]>([])
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)

  const hasMore = computed(() => page.value < lastPage.value)

  async function load(channelId: number) {
    loading.value = true
    try {
      const res = await api<Paginated<ChannelLink>>(`/api/channels/${channelId}/links?page=1`)
      links.value = res.data
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
      const res = await api<Paginated<ChannelLink>>(`/api/channels/${channelId}/links?page=${page.value + 1}`)
      // The same URL can legitimately appear more than once (shared twice), so dedupe on
      // the *sharing* — preview id alone would collapse them into one row.
      const seen = new Set(links.value.map(l => `${l.message_id}:${l.id}`))
      links.value = [...links.value, ...res.data.filter(l => !seen.has(`${l.message_id}:${l.id}`))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  return { links, hasMore, loading, load, loadMore }
}
