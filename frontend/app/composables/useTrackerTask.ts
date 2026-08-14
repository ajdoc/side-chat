import type { AppComment, TrackerTask } from '~/types'

/**
 * One open task's detail — its description, its comments and its history.
 *
 * Separate from {@link useTracker} on purpose, and *not* stored in a surface store. A board
 * holds fifty tasks; holding fifty comment threads and fifty histories alongside them would be
 * fifty requests nobody asked for. This loads when a task is opened and is dropped when it
 * closes, which is exactly the lifetime of the detail pane.
 *
 * The task itself still lives in the shared tracker state — editing a field here goes through
 * `patchTask` there, so the board row behind the pane updates with it. What this owns is only
 * the two lists the board never shows.
 */
export function useTrackerTask(basePath: string, streamName: string, taskId: Ref<number | null>) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()

  const task = ref<TrackerTask | null>(null)
  const loading = ref(false)

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  async function load() {
    const id = taskId.value
    if (id == null) {
      task.value = null
      return
    }
    loading.value = true
    try {
      const res = await api<{ data: TrackerTask }>(`${basePath}/tracker/tasks/${id}`)
      // Guard against the slow response for a task you've already navigated away from
      // landing on top of the one you're now looking at.
      if (taskId.value === id) task.value = res.data
    }
    finally {
      loading.value = false
    }
  }

  async function addComment(body: string) {
    const id = taskId.value
    if (id == null || !task.value) return
    const res = await api<{ data: AppComment }>(
      `${basePath}/apps/tracker_task/${id}/comments`,
      { method: 'POST', body: { body }, headers: socketHeaders() },
    )
    task.value.comments = [...(task.value.comments ?? []), res.data]
    return res.data
  }

  async function editComment(commentId: number, body: string) {
    const res = await api<{ data: AppComment }>(`${basePath}/app-comments/${commentId}`, {
      method: 'PATCH', body: { body }, headers: socketHeaders(),
    })
    if (task.value?.comments) {
      const idx = task.value.comments.findIndex(c => c.id === commentId)
      if (idx !== -1) task.value.comments.splice(idx, 1, res.data)
    }
    return res.data
  }

  async function removeComment(commentId: number) {
    if (!task.value?.comments) return
    const prev = task.value.comments
    task.value.comments = prev.filter(c => c.id !== commentId)
    try {
      await api(`${basePath}/app-comments/${commentId}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      if (task.value) task.value.comments = prev
      throw e
    }
  }

  // Reload whenever the pane is pointed at a different task — including at null, which clears
  // it so a reopened pane never flashes the previous task's comments.
  watch(taskId, () => void load(), { immediate: true })

  /**
   * Listen for comments on the task that's open.
   *
   * The tracker's own listener deliberately ignores the comment subject: it doesn't hold
   * comments, and a comment on a task nobody has open is nothing to draw. So the routing lives
   * here, where there's exactly one task to match against.
   */
  onMounted(() => {
    if (!echo) return
    const channel = hold(streamName)
    channel.listen('.TrackerChanged', (e: { subject: string, action: string, payload: any }) => {
      if (e.subject !== 'comment' || !task.value) return
      const p = e.payload
      if (p.commentable_type !== 'tracker_task' || Number(p.commentable_id) !== task.value.id) return

      const list = task.value.comments ?? []
      if (e.action === 'removed') {
        task.value.comments = list.filter(c => c.id !== p.id)
        return
      }
      const idx = list.findIndex(c => c.id === p.id)
      if (idx === -1) task.value.comments = [...list, p]
      else list.splice(idx, 1, p)
    })

    onBeforeUnmount(() => {
      channel.stopListening('.TrackerChanged')
      release(streamName)
    })
  })

  return { task, loading, load, addComment, editComment, removeComment }
}
