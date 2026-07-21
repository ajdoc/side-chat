import type { SpaceNote, User } from '~/types'
import { merge3 } from '~/lib/mergeText'

/**
 * A Side Space's shared note — one markdown document per surface, loaded over HTTP and kept
 * in sync over broadcast. Surface-agnostic like {@link useWhiteboard}: the caller passes the
 * REST base path and the private stream, so this drives a side chat's note
 * (`/api/side-chats/{id}/notes`, `sidechat.{id}`) and a channel's alike.
 *
 * Collaboration is **merge**, not last-write-wins. A save PUTs the whole body tagged with the
 * `version` it was typed on top of; if someone else saved in the meantime the server refuses
 * it with a 409 and hands back the current body, and this retries with the two edits merged
 * ({@link merge3}) — so people editing at once keep both their paragraphs instead of one of
 * them disappearing. `base` is the body as the server last had it: the common ancestor those
 * merges are measured against, which the host component also merges incoming broadcasts
 * against. The saver skips its own echo via the `->toOthers()` on the server, keyed by the
 * `X-Socket-ID` header sent here.
 */
export function useSpaceNote(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const content = ref('')
  const updatedBy = ref<User | null>(null)
  const updatedAt = ref<string | null>(null)
  const loading = ref(true)
  const saving = ref(false)
  /** The body as the server last had it, and the revision it belongs to. */
  const base = ref('')
  const version = ref(0)

  // Held so teardown removes our handler from the exact channel we joined, never a fresh
  // `echo.private(name)` — the surface's message stream may share it. See useWhiteboard.
  let channel: any = null

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  /** Adopt a note the server just handed us as the new common ancestor. */
  function applyServer(note: SpaceNote) {
    updatedBy.value = note.updated_by ?? null
    updatedAt.value = note.updated_at ?? null
    base.value = note.content ?? ''
    version.value = note.version ?? version.value
  }

  async function load() {
    loading.value = true
    try {
      const res = await api<{ data: SpaceNote }>(`${basePath}/notes`)
      content.value = res.data.content ?? ''
      applyServer(res.data)
    } finally {
      loading.value = false
    }
  }

  /**
   * Persist the whole body, resolving a concurrent save rather than losing to it.
   *
   * Returns the text that actually ended up on the server — the same string on the happy
   * path, or the merge of it with whoever got there first. The caller owns the editor, so it
   * decides how to fold that back into what's on screen. The retry is bounded: a note several
   * people are hammering settles on a later attempt rather than spinning here.
   */
  async function save(next: string): Promise<string> {
    saving.value = true
    let attempt = next
    try {
      for (let i = 0; i < 3; i++) {
        try {
          const res = await api<{ data: SpaceNote }>(`${basePath}/notes`, {
            method: 'PUT',
            body: { content: attempt, base_version: version.value },
            headers: socketHeaders(),
          })
          applyServer(res.data)
          return attempt
        } catch (e: any) {
          const status = e?.status ?? e?.statusCode
          const theirs: SpaceNote | undefined = e?.data?.data
          if (status !== 409 || !theirs) throw e
          attempt = merge3(base.value, attempt, theirs.content ?? '')
          applyServer(theirs)
        }
      }
      return attempt
    } finally {
      saving.value = false
    }
  }

  /**
   * Listen for other people's saves. `onRemote` gets the new body *and* the ancestor it grew
   * out of, which is what lets the host merge it into an edit in progress instead of having
   * to choose between the two.
   */
  function subscribe(onRemote: (content: string, ancestor: string) => void) {
    if (!echo) return
    channel = echo.private(streamName)
    channel.listen('.SpaceNoteUpdated', (note: SpaceNote) => {
      // Ignore anything not newer than what we hold — a save of ours that crossed with the
      // broadcast, or an out-of-order delivery.
      if (typeof note.version === 'number' && note.version <= version.value) return
      const ancestor = base.value
      applyServer(note)
      onRemote(note.content ?? '', ancestor)
    })
  }

  function unsubscribe() {
    channel?.stopListening('.SpaceNoteUpdated')
    channel = null
  }

  return { content, updatedBy, updatedAt, loading, saving, base, version, load, save, subscribe, unsubscribe }
}
