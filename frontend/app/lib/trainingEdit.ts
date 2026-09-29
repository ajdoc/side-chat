import type { TrainingContent, TrainingSession } from '~/types'
import { sessionIdFor } from './training'

/**
 * Editing a program's structure — weeks, days, phases, and which session a day gets.
 *
 * Every function mutates the document it's given (the editor's draft) and keeps the parts
 * that refer to each other consistent: deleting a week shifts the phases, notes and one-off
 * swaps after it; deleting a phase drops its column from every day's plan.
 *
 * Weeks are positions, so the week operations also return a {@link WeekMap} (week before →
 * week after, or null when deleted). The editor composes them across a session of edits and
 * sends the result with the save, and the server moves everyone's ticks to match.
 */

export type WeekMap = Record<number, number | null>

export const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
const WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export function identityMap(weeks: number): WeekMap {
  const map: WeekMap = {}
  for (let w = 1; w <= weeks; w++) map[w] = w
  return map
}

/** `base` maps original → current; `step` maps current → next. Result: original → next. */
export function composeMaps(base: WeekMap, step: WeekMap): WeekMap {
  const out: WeekMap = {}
  for (const [orig, cur] of Object.entries(base)) out[Number(orig)] = cur === null ? null : (step[cur] ?? null)
  return out
}

/** Short random id for a new block or session. */
export function newId(prefix = 'b') {
  return `${prefix}${Math.random().toString(36).slice(2, 8)}`
}

/** PHP sends an empty map as `[]`; the editor wants objects throughout. */
export function editable(c: TrainingContent): TrainingContent {
  const copy: TrainingContent = JSON.parse(JSON.stringify(c))
  for (const k of ['notes', 'warmups', 'plan', 'sessions'] as const) {
    if (Array.isArray(copy[k]) || !copy[k]) (copy as any)[k] = {}
  }
  copy.overrides ??= []
  copy.reference ??= []
  return copy
}

// --- plan columns ---------------------------------------------------------------------------

/** A day's plan as one entry per phase. */
export function planColumns(c: TrainingContent, day: string): (string | null)[] {
  const plan = c.plan[day]
  return c.phases.map((_, i) => (plan == null ? null : typeof plan === 'string' ? plan : plan[i] ?? null))
}

/** Store a day's per-phase plan in its simplest shape. */
export function setPlanColumns(c: TrainingContent, day: string, cols: (string | null)[]) {
  if (cols.every(x => x === null)) delete c.plan[day]
  else if (cols.every(x => x === cols[0])) c.plan[day] = cols[0]!
  else c.plan[day] = cols
}

// --- weeks ----------------------------------------------------------------------------------

function remapWeeks(c: TrainingContent, map: WeekMap) {
  const notes: Record<string, string> = {}
  for (const [w, text] of Object.entries(c.notes)) {
    const to = map[Number(w)]
    if (to != null) notes[String(to)] = text
  }
  c.notes = notes
  c.overrides = c.overrides
    .map(o => ({ ...o, weeks: o.weeks.map(w => map[w]).filter((w): w is number => w != null) }))
    .filter(o => o.weeks.length > 0)
}

/** Insert an empty-noted week after `after` (0 = at the start). It joins that week's phase. */
export function insertWeek(c: TrainingContent, after: number): WeekMap {
  const map: WeekMap = {}
  for (let w = 1; w <= c.weeks; w++) map[w] = w <= after ? w : w + 1
  const host = after === 0 ? 0 : Math.max(0, c.phases.findIndex(p => after >= p.from && after <= p.to))
  c.phases = c.phases.map((p, i) => (i === host
    ? { ...p, to: p.to + 1 }
    : { ...p, from: p.from > after ? p.from + 1 : p.from, to: p.to > after ? p.to + 1 : p.to }))
  c.weeks += 1
  remapWeeks(c, map)
  return map
}

/** Delete a week. A phase left with no weeks is removed along with its plan column. */
export function deleteWeek(c: TrainingContent, week: number): WeekMap {
  if (c.weeks <= 1) return identityMap(c.weeks)
  const map: WeekMap = {}
  for (let w = 1; w <= c.weeks; w++) map[w] = w < week ? w : w === week ? null : w - 1
  const phases = c.phases.map(p => ({ ...p, from: p.from > week ? p.from - 1 : p.from, to: p.to >= week ? p.to - 1 : p.to }))
  for (let i = phases.length - 1; i >= 0; i--) {
    if (phases[i]!.to < phases[i]!.from) {
      dropPlanColumn(c, i)
      phases.splice(i, 1)
    }
  }
  c.phases = phases
  c.weeks -= 1
  remapWeeks(c, map)
  pruneForks(c)
  return map
}

// --- phases ---------------------------------------------------------------------------------

function dropPlanColumn(c: TrainingContent, i: number) {
  for (const d of c.days) {
    const cols = planColumns(c, d.key)
    cols.splice(i, 1)
    setPlanColumns(c, d.key, cols.length ? cols : [null])
  }
}

/** Split phase `i` in two at its midpoint. The new half starts with the same sessions. */
export function splitPhase(c: TrainingContent, i: number) {
  const p = c.phases[i]
  if (!p || p.to === p.from) return
  const cut = p.from + Math.ceil((p.to - p.from + 1) / 2)
  for (const d of c.days) {
    const cols = planColumns(c, d.key)
    cols.splice(i + 1, 0, cols[i] ?? null)
    c.plan[d.key] = cols // set directly: setPlanColumns reads phase count, which changes below
  }
  c.phases.splice(i, 1, { ...p, to: cut - 1 }, { name: `${p.name} 2`, from: cut, to: p.to, line: '' })
  for (const d of c.days) setPlanColumns(c, d.key, planColumns(c, d.key))
}

/** Merge phase `i` into its neighbour (the one before, or after for the first). */
export function removePhase(c: TrainingContent, i: number) {
  if (c.phases.length <= 1) return
  const p = c.phases[i]!
  const into = i === 0 ? 1 : i - 1
  const q = c.phases[into]!
  c.phases[into] = { ...q, from: Math.min(q.from, p.from), to: Math.max(q.to, p.to) }
  dropPlanColumn(c, i)
  c.phases.splice(i, 1)
}

/** Move the boundary after phase `i`: it now ends on `to`, and the next phase starts after it. */
export function setPhaseEnd(c: TrainingContent, i: number, to: number) {
  const p = c.phases[i]
  const next = c.phases[i + 1]
  if (!p || !next) return
  const end = Math.min(Math.max(to, p.from), next.to - 1)
  p.to = end
  next.from = end + 1
}

// --- days -----------------------------------------------------------------------------------

/** Weekday offsets with no training day yet. */
export function freeOffsets(c: TrainingContent) {
  const used = new Set(c.days.map(d => d.offset))
  return [0, 1, 2, 3, 4, 5, 6].filter(o => !used.has(o))
}

export function weekdayLabel(offset: number) {
  return WEEKDAY_LABELS[offset] ?? `Day ${offset + 1}`
}

/** Add a (rest) day on a free weekday; returns its key. */
export function addDay(c: TrainingContent, offset: number): string {
  let key: string = WEEKDAYS[offset] ?? `d${offset}`
  while (c.days.some(d => d.key === key)) key = `${key}x`
  c.days.push({ key, label: weekdayLabel(offset), tag: '', offset })
  c.days.sort((a, b) => a.offset - b.offset)
  return key
}

export function removeDay(c: TrainingContent, key: string) {
  c.days = c.days.filter(d => d.key !== key)
  delete c.plan[key]
  c.overrides = c.overrides.filter(o => o.day !== key)
  pruneForks(c)
}

// --- which session a day gets ---------------------------------------------------------------

/** Every (week, day) that shows a session. */
export function slotsUsing(c: TrainingContent, sessionId: string) {
  const out: { week: number, day: string }[] = []
  for (let w = 1; w <= c.weeks; w++) {
    for (const d of c.days) if (sessionIdFor(c, w, d.key) === sessionId) out.push({ week: w, day: d.key })
  }
  return out
}

function takeWeekOutOfOverrides(c: TrainingContent, week: number, day: string) {
  c.overrides = c.overrides
    .map(o => (o.day === day ? { ...o, weeks: o.weeks.filter(w => w !== week) } : o))
    .filter(o => o.weeks.length > 0)
}

/** What a day gets from the plan alone, ignoring one-off swaps. */
function plannedFor(c: TrainingContent, week: number, day: string) {
  return sessionIdFor({ ...c, overrides: [] }, week, day)
}

/** Give one week's day a session (or rest), leaving every other week alone. */
export function setSessionForWeek(c: TrainingContent, week: number, day: string, sessionId: string | null) {
  takeWeekOutOfOverrides(c, week, day)
  if (plannedFor(c, week, day) !== sessionId) {
    const same = c.overrides.find(o => o.day === day && o.session === sessionId)
    if (same) same.weeks = [...same.weeks, week].sort((a, b) => a - b)
    else c.overrides.push({ weeks: [week], day, session: sessionId })
  }
  pruneForks(c)
}

/** Give a day a session (or rest) for every week of one phase — clearing that phase's swaps. */
export function setSessionForPhase(c: TrainingContent, phase: number, day: string, sessionId: string | null) {
  const cols = planColumns(c, day)
  cols[phase] = sessionId
  setPlanColumns(c, day, cols)
  const p = c.phases[phase]
  if (p) for (let w = p.from; w <= p.to; w++) takeWeekOutOfOverrides(c, w, day)
  pruneForks(c)
}

const FORK = /-w\d+-[a-z0-9_]+(?:-\d+)?$/

function uniqueSessionId(c: TrainingContent, base: string) {
  const clean = base.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+/, '').slice(0, 34) || 's'
  let id = clean
  for (let n = 2; c.sessions[id]; n++) id = `${clean}-${n}`
  return id
}

/**
 * Make one week's day its own session, so editing it leaves other weeks alone.
 *
 * The copy keeps the block ids, so ticks already on that day stay on the same exercises.
 * Returns the session id to edit — the existing one if it's already used nowhere else.
 */
export function forkForWeek(c: TrainingContent, week: number, day: string): string | null {
  const sid = sessionIdFor(c, week, day)
  if (!sid) return null
  if (slotsUsing(c, sid).length === 1) return sid
  const base = sid.replace(FORK, '')
  const id = uniqueSessionId(c, `${base}-w${week}-${day}`)
  c.sessions[id] = JSON.parse(JSON.stringify(c.sessions[sid]))
  setSessionForWeek(c, week, day, id)
  return id
}

/** A brand-new session on one week's day (for a rest day, or starting over). */
export function newSessionForWeek(c: TrainingContent, week: number, day: string): string {
  const id = uniqueSessionId(c, `session-w${week}-${day}`)
  c.sessions[id] = blankSession()
  setSessionForWeek(c, week, day, id)
  return id
}

export function blankSession(): TrainingSession {
  return { title: 'New session', time: '', hard: false, warmup: null, intent: '', blocks: [{ id: newId(), name: 'New exercise', dose: '', cue: '' }] }
}

/** Sessions nothing points at any more. */
export function unusedSessions(c: TrainingContent) {
  const used = new Set<string>()
  for (const d of c.days) for (const s of planColumns(c, d.key)) if (s) used.add(s)
  for (const o of c.overrides) if (o.session) used.add(o.session)
  return Object.keys(c.sessions).filter(id => !used.has(id))
}

/**
 * Drop per-week copies that nothing uses any more — made by {@link forkForWeek}, recognisable by
 * their `-w<week>-<day>` id. Sessions the author named are kept even when unscheduled.
 */
function pruneForks(c: TrainingContent) {
  for (const id of unusedSessions(c)) if (FORK.test(id)) delete c.sessions[id]
}
