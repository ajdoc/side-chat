import type { Server } from '~/types'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number, last_page: number }
}

// Shared list of servers the user belongs to (the left rail), 200 per page.
export function useServers() {
  const api = useApi()
  const servers = useState<Server[]>('servers', () => [])
  const page = useState<number>('servers:page', () => 0) // 0 = not loaded yet
  const lastPage = useState<number>('servers:lastPage', () => 1)
  const loading = ref(false)

  const hasMore = computed(() => page.value >= 1 && page.value < lastPage.value)

  async function fetchServers(force = false) {
    if (page.value >= 1 && !force) return
    const res = await api<Paginated<Server>>('/api/servers?page=1')
    servers.value = res.data
    page.value = res.meta.current_page
    lastPage.value = res.meta.last_page
  }

  async function loadMore() {
    if (!hasMore.value || loading.value) return
    loading.value = true
    try {
      const res = await api<Paginated<Server>>(`/api/servers?page=${page.value + 1}`)
      const seen = new Set(servers.value.map(s => s.id))
      servers.value = [...servers.value, ...res.data.filter(s => !seen.has(s.id))]
      page.value = res.meta.current_page
      lastPage.value = res.meta.last_page
    } finally {
      loading.value = false
    }
  }

  async function createServer(name: string) {
    const res = await api<{ data: Server }>('/api/servers', { method: 'POST', body: { name } })
    servers.value = [...servers.value, res.data]
    if (page.value === 0) page.value = 1
    return res.data
  }

  /** Drop a server from the rail. The server itself may be gone, or just gone *for us*. */
  function forget(id: number) {
    servers.value = servers.value.filter(s => s.id !== id)
  }

  /** Patch a server in the rail — renamed here, or renamed by its owner elsewhere. */
  function patch(id: number, fields: Partial<Server>) {
    const idx = servers.value.findIndex(s => s.id === id)
    if (idx !== -1) servers.value.splice(idx, 1, { ...servers.value[idx]!, ...fields })
  }

  /** Owner only. Renames it for everybody. */
  async function renameServer(id: number, name: string) {
    const res = await api<{ data: Server }>(`/api/servers/${id}`, {
      method: 'PATCH',
      body: { name },
    })
    patch(id, res.data)

    return res.data
  }

  /** Owner only. Deletes every channel, message and file in it, for everybody. */
  async function deleteServer(id: number) {
    await api(`/api/servers/${id}`, { method: 'DELETE' })
    forget(id)
  }

  /** Leave a server you're a member of. The owner can't — the API answers 422. */
  async function leaveServer(id: number) {
    await api(`/api/servers/${id}/leave`, { method: 'POST' })
    forget(id)
  }

  return { servers, hasMore, loading, fetchServers, loadMore, createServer, renameServer, deleteServer, leaveServer, forget, patch }
}
