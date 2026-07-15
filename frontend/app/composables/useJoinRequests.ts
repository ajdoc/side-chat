import type { ServerJoinRequest } from '~/types'

// Pending requests to join a server, plus the real-time Reverb subscription. Shared
// state (not a per-call ref) so the badge count and the requests page always agree.
export function useJoinRequests() {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const requests = useState<ServerJoinRequest[]>('server:joinRequests', () => [])
  const loading = ref(false)

  async function load(serverId: number) {
    loading.value = true
    try {
      const res = await api<{ data: ServerJoinRequest[] }>(`/api/servers/${serverId}/join-requests`)
      requests.value = res.data
    } finally {
      loading.value = false
    }
  }

  async function approve(serverId: number, ids: number[]) {
    if (!ids.length) return
    await api(`/api/servers/${serverId}/join-requests/approve`, {
      method: 'POST',
      body: { request_ids: ids },
    })
    requests.value = requests.value.filter(r => !ids.includes(r.id))
  }

  async function decline(serverId: number, ids: number[]) {
    if (!ids.length) return
    await api(`/api/servers/${serverId}/join-requests/decline`, {
      method: 'POST',
      body: { request_ids: ids },
    })
    requests.value = requests.value.filter(r => !ids.includes(r.id))
  }

  function subscribe(serverId: number) {
    echo.private(`server.${serverId}`)
      .listen('.JoinRequestCreated', (r: ServerJoinRequest) => {
        if (!requests.value.some(x => x.id === r.id)) requests.value = [...requests.value, r]
      })
      .listen('.JoinRequestResolved', (p: { ids: number[] }) => {
        requests.value = requests.value.filter(r => !p.ids.includes(r.id))
      })
  }

  function unsubscribe(serverId: number) {
    echo.leave(`server.${serverId}`)
  }

  return { requests, loading, load, approve, decline, subscribe, unsubscribe }
}
