import type { AppComment, AppTag } from '~/types'

/**
 * Comments and tags for one item in any app — a calendar entry, a canvas card, a tracker task.
 *
 * The generic half of what {@link useTrackerTask} does for a task. Both talk to the same
 * endpoints (`/apps/{type}/{id}/comments`, `/app-tags`), because the tables behind them were
 * made polymorphic precisely so the second app to want a comment thread wouldn't need a second
 * of everything.
 *
 * Loaded per open item and dropped when it closes, like the tracker's detail: a calendar month
 * holds thirty entries and none of them needs its comments until somebody opens one.
 *
 * @param subject the short morph name — 'calendar_event', 'canvas_item', 'tracker_task'
 */
export function useAppItem(
  basePath: string,
  subject: string,
  itemId: Ref<number | null>,
) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo

  const comments = ref<AppComment[]>([])
  const tags = ref<AppTag[]>([])
  const loading = ref(false)

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  async function load() {
    const id = itemId.value
    if (id == null) {
      comments.value = []
      return
    }
    loading.value = true
    try {
      const res = await api<{ data: AppComment[] }>(`${basePath}/apps/${subject}/${id}/comments`)
      // The response for an item you've since navigated away from must not land on the one
      // you're now looking at.
      if (itemId.value === id) comments.value = res.data
    }
    finally {
      loading.value = false
    }
  }

  /** The channel's whole vocabulary — what the tag picker offers. */
  async function loadTags() {
    const res = await api<{ data: AppTag[] }>(`${basePath}/app-tags`)
    tags.value = res.data
  }

  async function addComment(body: string) {
    const id = itemId.value
    if (id == null) return
    const res = await api<{ data: AppComment }>(`${basePath}/apps/${subject}/${id}/comments`, {
      method: 'POST', body: { body }, headers: socketHeaders(),
    })
    comments.value = [...comments.value, res.data]
    return res.data
  }

  async function removeComment(commentId: number) {
    const prev = comments.value
    comments.value = prev.filter(c => c.id !== commentId)
    try {
      await api(`${basePath}/app-comments/${commentId}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      comments.value = prev
      throw e
    }
  }

  /**
   * Attach a tag, creating it first if the label is new.
   *
   * One call rather than two for the caller, because "type a word and press enter" is the only
   * way a tag ever gets used — and the create endpoint hands back the existing tag when the
   * label already exists, so this is safe to call with either.
   */
  async function attachTag(label: string) {
    const id = itemId.value
    if (id == null) return
    const created = await api<{ data: AppTag }>(`${basePath}/app-tags`, {
      method: 'POST', body: { label }, headers: socketHeaders(),
    })
    const res = await api<{ data: AppTag }>(
      `${basePath}/apps/${subject}/${id}/tags/${created.data.id}`,
      { method: 'PUT', headers: socketHeaders() },
    )
    if (!tags.value.some(t => t.id === created.data.id)) tags.value = [...tags.value, created.data]
    return res.data
  }

  async function detachTag(tagId: number) {
    const id = itemId.value
    if (id == null) return
    await api(`${basePath}/apps/${subject}/${id}/tags/${tagId}`, {
      method: 'DELETE', headers: socketHeaders(),
    })
  }

  watch(itemId, () => void load(), { immediate: true })

  return { comments, tags, loading, load, loadTags, addComment, removeComment, attachTag, detachTag }
}
