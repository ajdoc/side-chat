import type { SpaceNote, User } from '~/types'

/**
 * A Side Space's shared note — one markdown document per surface, loaded over HTTP and kept
 * in sync over broadcast. Surface-agnostic like {@link useWhiteboard}: the caller passes the
 * REST base path and the private stream, so this drives a side chat's note
 * (`/api/side-chats/{id}/notes`, `sidechat.{id}`) and a channel's alike.
 *
 * Collaboration is last-write-wins, not a CRDT: a save PUTs the whole body and broadcasts it,
 * and every other open note replaces its copy — but only while its editor is idle, so a save
 * landing from someone else never yanks the text out from under your cursor (see the host
 * component). The saver skips its own echo via the `->toOthers()` on the server, keyed by the
 * `X-Socket-ID` header sent here.
 */
export function useSpaceNote(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { user } = useAuth()

  const content = ref('')
  const updatedBy = ref<User | null>(null)
  const updatedAt = ref<string | null>(null)
  const loading = ref(true)
  const saving = ref(false)

  // Held so teardown removes our handler from the exact channel we joined, never a fresh
  // `echo.private(name)` — the surface's message stream may share it. See useWhiteboard.
  let channel: any = null

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  function applyMeta(note: SpaceNote) {
    updatedBy.value = note.updated_by ?? null
    updatedAt.value = note.updated_at ?? null
  }

  async function load() {
    loading.value = true
    try {
      const res = await api<{ data: SpaceNote }>(`${basePath}/notes`)
      content.value = res.data.content ?? ''
      applyMeta(res.data)
    } finally {
      loading.value = false
    }
  }

  /** Persist the whole body. The caller debounces; this just PUTs and updates the meta line. */
  async function save(next: string) {
    saving.value = true
    try {
      const res = await api<{ data: SpaceNote }>(`${basePath}/notes`, {
        method: 'PUT',
        body: { content: next },
        headers: socketHeaders(),
      })
      applyMeta(res.data)
    } finally {
      saving.value = false
    }
  }

  /** Listen for other people's saves; `onRemote` gets the new body to apply (or not). */
  function subscribe(onRemote: (content: string) => void) {
    if (!echo) return
    channel = echo.private(streamName)
    channel.listen('.SpaceNoteUpdated', (note: SpaceNote) => {
      applyMeta(note)
      onRemote(note.content ?? '')
    })
  }

  function unsubscribe() {
    channel?.stopListening('.SpaceNoteUpdated')
    channel = null
  }

  return { content, updatedBy, updatedAt, loading, saving, load, save, subscribe, unsubscribe }
}
