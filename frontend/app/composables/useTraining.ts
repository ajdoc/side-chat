import type { TrainingContent, TrainingProgram, TrainingProgramSummary, TrainingTeamMember, TrainingTemplate } from '~/types'
import { tickKey } from '~/lib/training'

/**
 * A channel's Training — its programs, the open one with your ticks, and the team's progress.
 *
 * Same contract as {@link useSignups}: state in a {@link useSurfaceStore} so the channel view
 * and a floating window are one program. It rides `TrackerChanged` under three subjects —
 * `training_program` (re-read the list), `training_tick` (one tick, applied in place) and
 * `training_ticks` (someone cleared theirs, re-read).
 */
export function useTraining(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()
  const { user } = useAuth()

  const { state, attach } = useSurfaceStore('training', basePath, () => ({
    programs: ref<TrainingProgramSummary[]>([]),
    templates: ref<TrainingTemplate[]>([]),
    canManage: ref(false),
    loaded: ref(false),
    current: ref<TrainingProgram | null>(null),
    /** Null until the team view first asks for it; kept live from then on. */
    team: ref<TrainingTeamMember[] | null>(null),
  }))

  const { programs, templates, canManage, loaded, current, team } = state

  const headers = () => ({ 'X-Socket-ID': echo?.socketId() ?? '' })
  const url = (id: number) => `${basePath}/training/programs/${id}`

  async function load() {
    const res = await api<{ programs: TrainingProgramSummary[], templates: TrainingTemplate[], can_manage: boolean }>(
      `${basePath}/training`,
    )
    programs.value = res.programs
    templates.value = res.templates
    canManage.value = res.can_manage
    loaded.value = true
  }

  async function openProgram(id: number) {
    if (current.value?.id !== id) {
      current.value = null
      team.value = null
    }
    const res = await api<{ data: TrainingProgram }>(url(id))
    current.value = res.data
    return res.data
  }

  function closeProgram() {
    current.value = null
    team.value = null
  }

  /** From a built-in `template`, or an imported `content` document. */
  async function createProgram(input: { template?: string, content?: TrainingContent, title: string | null, starts_on: string | null }) {
    const res = await api<{ data: TrainingProgram }>(`${basePath}/training/programs`, {
      method: 'POST', body: input, headers: headers(),
    })
    await load()
    current.value = res.data
    team.value = null
    return res.data
  }

  async function updateProgram(id: number, changes: { title?: string, starts_on?: string }) {
    const res = await api<{ data: TrainingProgram }>(url(id), { method: 'PATCH', body: changes, headers: headers() })
    if (current.value?.id === id) current.value = res.data
    await load()
    return res.data
  }

  /**
   * Replace a program's whole document — the editor's save, or importing over it. `weekMap`
   * (week before → week after, or null) moves everyone's ticks when weeks were added or deleted.
   */
  async function replaceContent(id: number, content: TrainingContent, weekMap?: Record<number, number | null>) {
    const res = await api<{ data: TrainingProgram }>(`${url(id)}/content`, {
      method: 'PUT', body: { content, week_map: weekMap ?? null }, headers: headers(),
    })
    if (current.value?.id === id) current.value = res.data
    await load()
    return res.data
  }

  async function deleteProgram(id: number) {
    await api(url(id), { method: 'DELETE', headers: headers() })
    if (current.value?.id === id) closeProgram()
    await load()
  }

  /** Apply one tick to local state — yours to `mine`, anyone's to the team list if it's loaded. */
  function applyTick(userId: number, key: string, done: boolean) {
    const me = user.value?.id
    if (current.value && userId === me) {
      const mine = current.value.mine.filter(k => k !== key)
      current.value = { ...current.value, mine: done ? [...mine, key] : mine }
    }
    if (!team.value) return
    const idx = team.value.findIndex(m => m.user.id === userId)
    if (idx === -1) {
      // Somebody's first tick. Their name isn't in hand, so re-read rather than guess.
      if (done) void loadTeam()
      return
    }
    const member = team.value[idx]!
    const ticks = member.ticks.filter(k => k !== key)
    team.value.splice(idx, 1, { ...member, ticks: done ? [...ticks, key] : ticks })
  }

  /** Tick or untick one of your blocks. Optimistic; rolled back if the server refuses. */
  async function setTick(week: number, day: string, block: number, done: boolean) {
    const program = current.value
    const me = user.value?.id
    if (!program || me == null) return
    const key = tickKey(week, day, block)
    applyTick(me, key, done)
    try {
      await api(`${url(program.id)}/tick`, { method: 'PUT', body: { week, day, block, done }, headers: headers() })
    }
    catch (e) {
      applyTick(me, key, !done)
      throw e
    }
  }

  async function clearMine() {
    const program = current.value
    if (!program) return
    await api(`${url(program.id)}/ticks`, { method: 'DELETE', headers: headers() })
    await openProgram(program.id)
    if (team.value) await loadTeam()
  }

  async function loadTeam() {
    const program = current.value
    if (!program) return
    const res = await api<{ members: TrainingTeamMember[] }>(`${url(program.id)}/team`)
    if (current.value?.id === program.id) team.value = res.members
  }

  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = hold(streamName)
      const onChange = (e: { subject: string, action: string, payload: Record<string, any> }) => {
        if (e.subject === 'training_program') {
          void load()
          if (current.value?.id !== e.payload.id) return
          if (e.action === 'removed') closeProgram()
          else void openProgram(e.payload.id)
          return
        }
        if (e.payload?.program_id !== current.value?.id) return
        if (e.subject === 'training_tick') {
          applyTick(e.payload.user_id, e.payload.key, e.payload.done)
        }
        else if (e.subject === 'training_ticks') {
          if (e.payload.user_id === user.value?.id && current.value) void openProgram(current.value.id)
          if (team.value) void loadTeam()
        }
      }
      channel.listen('.TrackerChanged', onChange)

      return () => {
        // Only our handler: the tracker, polls and sign-ups listen for the same event here.
        channel.stopListening('.TrackerChanged', onChange)
        release(streamName)
      }
    })
  }

  return {
    programs, templates, canManage, loaded, current, team,
    open, load, openProgram, closeProgram, createProgram, updateProgram, replaceContent, deleteProgram,
    setTick, clearMine, loadTeam,
  }
}
