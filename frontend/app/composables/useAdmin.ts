import type {
  AdminConversation,
  AdminMessage,
  AdminOverview,
  AdminServer,
  AdminUser,
  Paginated,
  SiteRole,
} from '~/types'

/**
 * The admin panel's one door to the API.
 *
 * Unlike most composables here this keeps *no* shared state. Everything in the panel is a
 * paginated query over data that other people are changing while you look at it, and a
 * cached copy of "all users" is a cache that is wrong by the time you scroll. Each screen
 * owns its own page of results and refetches; this just wraps the calls.
 *
 * `isSuperAdmin` is the exception worth noting: it reads the role off your own user record,
 * which the API sends only for you (see UserResource). It gates the nav entry, and nothing
 * more — the server gates the endpoints, and a client-side check has never stopped anybody.
 */
export function useAdmin() {
  const api = useApi()
  const { user } = useAuth()

  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')

  // ── Overview ───────────────────────────────────────────────────────────────────────────

  const overview = () => api<AdminOverview>('/api/admin/overview')

  // ── Users ──────────────────────────────────────────────────────────────────────────────

  type UserQuery = { q?: string, filter?: 'banned' | 'admins' | 'bots' | '', page?: number }

  const users = (query: UserQuery = {}) =>
    api<Paginated<AdminUser>>('/api/admin/users', { query: clean(query) })

  const updateUser = (id: number, body: { name?: string, email?: string, password?: string }) =>
    api<{ data: AdminUser }>(`/api/admin/users/${id}`, { method: 'PATCH', body }).then(r => r.data)

  const setRole = (id: number, role: SiteRole | null) =>
    api<{ data: AdminUser }>(`/api/admin/users/${id}/role`, { method: 'PUT', body: { role } }).then(r => r.data)

  /** Block, with the sentence the person reads when they next try to sign in. */
  const banUser = (id: number, reason: string) =>
    api<{ data: AdminUser }>(`/api/admin/users/${id}/ban`, { method: 'POST', body: { reason } }).then(r => r.data)

  const unbanUser = (id: number) =>
    api<{ data: AdminUser }>(`/api/admin/users/${id}/ban`, { method: 'DELETE' }).then(r => r.data)

  const deleteUser = (id: number) => api(`/api/admin/users/${id}`, { method: 'DELETE' })

  // ── Servers and channels ───────────────────────────────────────────────────────────────

  const servers = (query: { q?: string, page?: number } = {}) =>
    api<Paginated<AdminServer>>('/api/admin/servers', { query: clean(query) })

  const server = (id: number) =>
    api<{ data: AdminServer }>(`/api/admin/servers/${id}`).then(r => r.data)

  const updateServer = (id: number, body: Record<string, unknown>) =>
    api<{ data: AdminServer }>(`/api/admin/servers/${id}`, { method: 'PATCH', body }).then(r => r.data)

  const deleteServer = (id: number) => api(`/api/admin/servers/${id}`, { method: 'DELETE' })

  const updateChannel = (id: number, body: { name?: string, is_private?: boolean }) =>
    api(`/api/admin/channels/${id}`, { method: 'PATCH', body })

  const deleteChannel = (id: number) => api(`/api/admin/channels/${id}`, { method: 'DELETE' })

  // ── DMs and group chats ────────────────────────────────────────────────────────────────

  const conversations = (query: { q?: string, type?: 'dm' | 'group' | '', user_id?: number, page?: number } = {}) =>
    api<Paginated<AdminConversation>>('/api/admin/conversations', { query: clean(query) })

  const deleteConversation = (id: number) => api(`/api/admin/conversations/${id}`, { method: 'DELETE' })

  // ── The audit view ─────────────────────────────────────────────────────────────────────

  type MessageQuery = {
    q?: string
    user_id?: number
    channel_id?: number
    conversation_id?: number
    server_id?: number
    from?: string
    to?: string
    page?: number
  }

  const messages = (query: MessageQuery = {}) =>
    api<Paginated<AdminMessage>>('/api/admin/messages', { query: clean(query) })

  const deleteMessage = (id: number) => api(`/api/admin/messages/${id}`, { method: 'DELETE' })

  return {
    isSuperAdmin,
    overview,
    users, updateUser, setRole, banUser, unbanUser, deleteUser,
    servers, server, updateServer, deleteServer, updateChannel, deleteChannel,
    conversations, deleteConversation,
    messages, deleteMessage,
  }
}

/**
 * Drop empty filters before they reach the query string.
 *
 * An empty `q` is not the same request as no `q` — it lands in the URL, gets bookmarked, and
 * comes back as a search for the empty string. The API ignores it either way; this keeps the
 * address bar readable.
 */
function clean<T extends Record<string, unknown>>(query: T): Record<string, unknown> {
  return Object.fromEntries(
    Object.entries(query).filter(([, value]) => value !== '' && value !== undefined && value !== null),
  )
}
