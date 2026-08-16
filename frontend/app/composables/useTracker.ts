import type { AppTag, TrackerProject, TrackerStatus, TrackerTask } from '~/types'

/**
 * A channel's Tracker — projects, tasks and the channel's tag vocabulary.
 *
 * Same contract as {@link useCalendar} and {@link useWhiteboard}: the caller hands a REST base
 * path and the private stream the surface lives on, and the state sits in a
 * {@link useSurfaceStore} so a tracker open as a channel and the same tracker in a floating
 * window are one tracker rather than two that agree most of the time. See that file for why
 * broadcast alone can't do it — two views in one browser tab share a socket, and every save
 * goes out with `toOthers()`.
 *
 * ## What's held, and what isn't
 *
 * Projects and tasks are held whole, because the tracker's two screens are both views over the
 * same set: the home lists projects and your tasks across all of them, and a board is that
 * same list filtered. Fetching per screen would refetch the tasks you already have every time
 * you pressed Back.
 *
 * A task's *comments and history* are deliberately not held here. They're fetched when a task
 * is opened and dropped when it closes — a board of fifty tasks would otherwise carry fifty
 * comment threads nobody is reading. See {@link useTrackerTask}.
 */
export function useTracker(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()

  const { state, attach } = useSurfaceStore('tracker', basePath, () => ({
    projects: ref<TrackerProject[]>([]),
    tasks: ref<TrackerTask[]>([]),
    tags: ref<AppTag[]>([]),
    /** False until the first load lands, so a view can hold a skeleton rather than "nothing here". */
    loaded: ref(false),
  }))

  const { projects, tasks, tags, loaded } = state

  function socketHeaders() {
    return { 'X-Socket-ID': echo?.socketId() ?? '' }
  }

  function upsertProject(p: TrackerProject) {
    const idx = projects.value.findIndex((x: TrackerProject) => x.id === p.id)
    if (idx === -1) projects.value = [...projects.value, p]
    else projects.value.splice(idx, 1, p)
  }

  function upsertTask(t: TrackerTask) {
    const idx = tasks.value.findIndex((x: TrackerTask) => x.id === t.id)
    // Merge rather than replace: a board broadcast carries no comments or history, and a task
    // the user has open would otherwise have both wiped out by somebody else's edit.
    if (idx === -1) tasks.value = [...tasks.value, t]
    else tasks.value.splice(idx, 1, { ...tasks.value[idx], ...t })
  }

  function upsertTag(tag: AppTag) {
    const idx = tags.value.findIndex((x: AppTag) => x.id === tag.id)
    if (idx === -1) tags.value = [...tags.value, tag]
    else tags.value.splice(idx, 1, tag)
  }

  async function load() {
    const [p, t, g] = await Promise.all([
      api<{ data: TrackerProject[] }>(`${basePath}/tracker/projects`),
      api<{ data: TrackerTask[] }>(`${basePath}/tracker/tasks`),
      api<{ data: AppTag[] }>(`${basePath}/app-tags`),
    ])
    projects.value = p.data
    tasks.value = t.data
    tags.value = g.data
    loaded.value = true
  }

  // --- projects ------------------------------------------------------------------------

  async function addProject(input: { name: string, key: string, description?: string | null }) {
    const res = await api<{ data: TrackerProject }>(`${basePath}/tracker/projects`, {
      method: 'POST', body: input, headers: socketHeaders(),
    })
    upsertProject(res.data)
    return res.data
  }

  async function patchProject(id: number, changes: Partial<TrackerProject>) {
    const res = await api<{ data: TrackerProject }>(`${basePath}/tracker/projects/${id}`, {
      method: 'PATCH', body: changes, headers: socketHeaders(),
    })
    upsertProject(res.data)
    return res.data
  }

  /** Deletes its tasks too — the confirm dialog says so before this is called. */
  async function removeProject(id: number) {
    const prevProjects = projects.value
    const prevTasks = tasks.value
    projects.value = projects.value.filter((p: TrackerProject) => p.id !== id)
    tasks.value = tasks.value.filter((t: TrackerTask) => t.project_id !== id)
    try {
      await api(`${basePath}/tracker/projects/${id}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      projects.value = prevProjects
      tasks.value = prevTasks
      throw e
    }
  }

  // --- tasks ---------------------------------------------------------------------------

  async function addTask(input: {
    project_id: number
    title: string
    status?: TrackerStatus
    priority?: string
    assignee_id?: number | null
    due_date?: string | null
  }) {
    const res = await api<{ data: TrackerTask }>(`${basePath}/tracker/tasks`, {
      method: 'POST', body: input, headers: socketHeaders(),
    })
    upsertTask(res.data)
    await refreshCounts(input.project_id)
    return res.data
  }

  /**
   * Persist a partial change, optimistically — dragging a task to another column saves just
   * the status, and the row moves before the round trip.
   */
  async function patchTask(id: number, changes: Partial<TrackerTask> & { tag_ids?: number[] }) {
    const idx = tasks.value.findIndex((t: TrackerTask) => t.id === id)
    if (idx === -1) return
    const prev = tasks.value[idx]!
    tasks.value.splice(idx, 1, { ...prev, ...changes } as TrackerTask)
    try {
      const res = await api<{ data: TrackerTask }>(`${basePath}/tracker/tasks/${id}`, {
        method: 'PATCH', body: changes, headers: socketHeaders(),
      })
      upsertTask(res.data)
      // The progress bar counts done-vs-total, so a status change moves it.
      if (changes.status !== undefined) await refreshCounts(prev.project_id)
      return res.data
    }
    catch (e) {
      tasks.value.splice(idx, 1, prev)
      throw e
    }
  }

  async function removeTask(id: number) {
    const prev = tasks.value
    const task = prev.find((t: TrackerTask) => t.id === id)
    tasks.value = tasks.value.filter((t: TrackerTask) => t.id !== id)
    try {
      await api(`${basePath}/tracker/tasks/${id}`, { method: 'DELETE', headers: socketHeaders() })
      if (task) await refreshCounts(task.project_id)
    }
    catch (e) {
      tasks.value = prev
      throw e
    }
  }

  /**
   * Re-read one project's counts after its tasks changed.
   *
   * Counted server-side rather than derived from `tasks` here, because the held list can be a
   * filtered one — the board fetches a single project — and counting a filtered list would
   * draw a confident, wrong progress bar. One small request beats a number that lies.
   */
  async function refreshCounts(projectId: number) {
    const found = projects.value.find((p: TrackerProject) => p.id === projectId)
    if (!found) return
    const res = await api<{ data: TrackerProject[] }>(`${basePath}/tracker/projects`)
    for (const p of res.data) upsertProject(p)
  }

  // --- tags ----------------------------------------------------------------------------

  /** Creating a tag that already exists hands back the existing one — see AppTagController. */
  async function addTag(label: string, color?: string) {
    const res = await api<{ data: AppTag }>(`${basePath}/app-tags`, {
      method: 'POST', body: { label, ...(color ? { color } : {}) }, headers: socketHeaders(),
    })
    upsertTag(res.data)
    return res.data
  }

  async function removeTag(id: number) {
    const prev = tags.value
    tags.value = tags.value.filter((t: AppTag) => t.id !== id)
    // Drop it off every task we're holding, so the chips disappear without a reload.
    tasks.value = tasks.value.map((t: TrackerTask) => ({
      ...t,
      tags: (t.tags ?? []).filter(g => g.id !== id),
    }))
    try {
      await api(`${basePath}/app-tags/${id}`, { method: 'DELETE', headers: socketHeaders() })
    }
    catch (e) {
      tags.value = prev
      throw e
    }
  }

  /**
   * Hold the tracker open for this component's life: load once, listen while anyone is
   * watching, let go when the last view unmounts. The refcounting in useSurfaceStore makes
   * the duplicate calls free.
   */
  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = hold(streamName)

      // One event for the whole app, dispatched on subject/action — see TrackerChanged for
      // why this isn't eight event classes.
      channel.listen('.TrackerChanged', (e: { subject: string, action: string, payload: any }) => {
        if (e.action === 'removed') {
          if (e.subject === 'project') {
            projects.value = projects.value.filter((p: TrackerProject) => p.id !== e.payload.id)
            tasks.value = tasks.value.filter((t: TrackerTask) => t.project_id !== e.payload.id)
          }
          if (e.subject === 'task') tasks.value = tasks.value.filter((t: TrackerTask) => t.id !== e.payload.id)
          if (e.subject === 'tag') tags.value = tags.value.filter((t: AppTag) => t.id !== e.payload.id)
          return
        }

        if (e.subject === 'project') upsertProject(e.payload)
        if (e.subject === 'task') upsertTask(e.payload)
        if (e.subject === 'tag') upsertTag(e.payload)
        // Comments are routed by the open task's own listener, not here — see useTrackerTask.
      })

      /*
       * An import dropped a pile of content into this channel.
       *
       * One event for the whole import, carrying only an app id and a count — never the rows,
       * which are unbounded (see AppContentImported, and the board broadcast that taught us).
       * So the answer is to re-read, which is the one thing this composable already knows how
       * to do.
       */
      channel.listen('.AppContentImported', (e: { app: string }) => {
        if (e.app === 'tracker') void load()
      })


      return () => {
        channel.stopListening('.TrackerChanged').stopListening('.AppContentImported')
        release(streamName)
      }
    })
  }

  return {
    projects, tasks, tags, loaded,
    open, load,
    addProject, patchProject, removeProject,
    addTask, patchTask, removeTask,
    addTag, removeTag,
  }
}
