import type { TrainingContent, TrainingSession, TrainingVideo, TrainingWarmupItem } from '~/types'

/**
 * Reading a training program — which session a day gets, which phase a week is in, what's done.
 *
 * The one place this is worked out: the server stores the program as authored and never
 * resolves it, so the program view, the day tabs and the team view all come through here.
 */

const DAY_MS = 864e5

/** The tick key for one block — the same `week:day:blockId` the server builds. */
export function tickKey(week: number, day: string, block: string) {
  return `${week}:${day}:${block}`
}

/** Index of the phase a week falls in; the last phase if a week is past every range. */
export function phaseIndex(content: TrainingContent, week: number) {
  const i = content.phases.findIndex(p => week >= p.from && week <= p.to)
  return i === -1 ? Math.max(0, content.phases.length - 1) : i
}

export function sessionIdFor(content: TrainingContent, week: number, day: string): string | null {
  const override = content.overrides?.find(o => o.day === day && o.weeks.includes(week))
  if (override) return override.session
  const plan = content.plan[day]
  if (plan == null) return null
  if (typeof plan === 'string') return plan
  return plan[Math.min(phaseIndex(content, week), plan.length - 1)] ?? null
}

export function sessionFor(content: TrainingContent, week: number, day: string): TrainingSession | null {
  const id = sessionIdFor(content, week, day)
  return id ? content.sessions[id] ?? null : null
}

/** How many of a day's blocks are ticked in a set of keys. */
export function dayProgress(content: TrainingContent, ticks: Set<string>, week: number, day: string) {
  const session = sessionFor(content, week, day)
  const blocks = session?.blocks ?? []
  const done = blocks.filter(b => ticks.has(tickKey(week, day, b.id))).length
  return { done, total: blocks.length }
}

/** How many days in a week have a session at all — rest days don't count toward "all done". */
export function weekSessionsTotal(content: TrainingContent, week: number) {
  return content.days.filter(d => (sessionFor(content, week, d.key)?.blocks.length ?? 0) > 0).length
}

/** Sessions across the whole program. */
export function programSessionsTotal(content: TrainingContent) {
  let n = 0
  for (let w = 1; w <= content.weeks; w++) n += weekSessionsTotal(content, w)
  return n
}

/** Sessions fully finished in a week. */
export function weekSessionsDone(content: TrainingContent, ticks: Set<string>, week: number) {
  return content.days.filter((d) => {
    const { done, total } = dayProgress(content, ticks, week, d.key)
    return total > 0 && done === total
  }).length
}

/** Sessions fully finished across the whole program. */
export function programSessionsDone(content: TrainingContent, ticks: Set<string>) {
  let n = 0
  for (let w = 1; w <= content.weeks; w++) n += weekSessionsDone(content, ticks, w)
  return n
}

/** Local midnight of a `YYYY-MM-DD`, so dates never slip a day across time zones. */
export function parseDate(iso: string) {
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y!, m! - 1, d!)
}

export function dateFor(startsOn: string, week: number, offset: number) {
  const start = parseDate(startsOn)
  return new Date(start.getFullYear(), start.getMonth(), start.getDate() + (week - 1) * 7 + offset)
}

/** The week `today` falls in, clamped to the program. */
export function currentWeek(startsOn: string, weeks: number, today = new Date()) {
  const start = parseDate(startsOn)
  const midnight = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const diff = Math.floor(Math.round((midnight.getTime() - start.getTime()) / DAY_MS) / 7)
  return Math.min(weeks, Math.max(1, diff + 1))
}

/** Today's day key, or the nearest training day before it (Sunday → Saturday). */
export function currentDay(content: TrainingContent, today = new Date()) {
  const offset = (today.getDay() + 6) % 7 // Monday = 0
  const past = content.days.filter(d => d.offset <= offset)
  return (past.at(-1) ?? content.days[0])?.key ?? ''
}

/** Whether the program is running, not started yet, or over. */
export function programStatus(startsOn: string, weeks: number, today = new Date()) {
  const start = parseDate(startsOn)
  const end = dateFor(startsOn, weeks, 7)
  if (today < start) return 'upcoming'
  if (today >= end) return 'finished'
  return 'running'
}

export function shortDate(d: Date) {
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * Where a video link goes: its own `url` if it's http(s), else a YouTube search. Anything else
 * (a `javascript:` url in a hand-edited program) gets no link at all.
 */
export function videoHref(v: TrainingVideo): string | null {
  if (v.url) return /^https?:\/\//i.test(v.url) ? v.url : null
  if (v.search) return `https://www.youtube.com/results?search_query=${encodeURIComponent(v.search)}`
  return null
}

/** A warm-up step as text plus links, whichever way it was written. */
export function warmupItem(item: TrainingWarmupItem) {
  return typeof item === 'string' ? { text: item, videos: [] } : { text: item.text, videos: item.videos ?? [] }
}

/** `[1, 2, 3, 5, 9, 10]` → `"1–3, 5, 9–10"`. */
export function weekRanges(weeks: number[]) {
  const sorted = [...new Set(weeks)].sort((a, b) => a - b)
  const parts: string[] = []
  for (let i = 0; i < sorted.length; i++) {
    const start = sorted[i]!
    while (sorted[i + 1] === sorted[i]! + 1) i++
    parts.push(start === sorted[i] ? String(start) : `${start}–${sorted[i]}`)
  }
  return parts.join(', ')
}
