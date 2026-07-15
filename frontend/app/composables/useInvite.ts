import type { InvitePreview } from '~/types'

// Opening an invite link doesn't join you — it records a request a member must approve.
export function useInvite() {
  const api = useApi()

  function preview(code: string) {
    return api<{ data: InvitePreview }>(`/api/invites/${code}`).then(r => r.data)
  }

  function requestToJoin(code: string) {
    return api<{ data: InvitePreview }>(`/api/invites/${code}/join`, { method: 'POST' }).then(r => r.data)
  }

  return { preview, requestToJoin }
}
