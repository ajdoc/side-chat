import { CircleCheck, CircleDashed, CircleDot, Eye, Inbox } from 'lucide-vue-next'
import type { Component } from 'vue'
import type { AppActivity, AppTagColor, TrackerPriority, TrackerStatus } from '~/types'

/**
 * How the Tracker's closed sets are drawn.
 *
 * The values themselves are the server's ({@link App\Support\Tracker\TrackerFields}) — it
 * decides what may be stored. This decides what any of it *looks* like, which is why the
 * labels, icons and colours live on the client and not in the database: re-wording a status or
 * re-tuning the palette is a release, not a migration.
 */

export interface StatusMeta {
  id: TrackerStatus
  label: string
  icon: Component
  /** Tailwind text colour for the icon, and the tint behind a group header. */
  text: string
  tint: string
}

/** Board order, top to bottom — the same order the server lists them in. */
export const TRACKER_STATUSES: StatusMeta[] = [
  { id: 'backlog', label: 'Backlog', icon: Inbox, text: 'text-muted-foreground', tint: 'bg-muted/40' },
  { id: 'todo', label: 'To Do', icon: CircleDashed, text: 'text-sky-500', tint: 'bg-sky-500/10' },
  { id: 'in_progress', label: 'In Progress', icon: CircleDot, text: 'text-amber-500', tint: 'bg-amber-500/10' },
  { id: 'in_review', label: 'In Review', icon: Eye, text: 'text-violet-500', tint: 'bg-violet-500/10' },
  { id: 'done', label: 'Done', icon: CircleCheck, text: 'text-emerald-500', tint: 'bg-emerald-500/10' },
]

const STATUS_BY_ID = new Map(TRACKER_STATUSES.map(s => [s.id, s]))

/**
 * Falls back rather than returning undefined: a client one release behind the server can be
 * handed a status it has never heard of, and a row that renders as a blank chip reads as a bug
 * where "backlog" reads as merely out of date.
 */
export function statusMeta(id: TrackerStatus): StatusMeta {
  return STATUS_BY_ID.get(id) ?? TRACKER_STATUSES[0]!
}

export interface PriorityMeta {
  id: TrackerPriority
  label: string
  /** The pill's classes — a tinted background, not a solid one, so a board isn't a traffic light. */
  chip: string
}

export const TRACKER_PRIORITIES: PriorityMeta[] = [
  { id: 'low', label: 'Low', chip: 'bg-muted text-muted-foreground' },
  { id: 'mid', label: 'Mid', chip: 'bg-amber-500/15 text-amber-600 dark:text-amber-400' },
  { id: 'high', label: 'High', chip: 'bg-red-500/15 text-red-600 dark:text-red-400' },
  { id: 'urgent', label: 'Urgent', chip: 'bg-red-500 text-white' },
]

const PRIORITY_BY_ID = new Map(TRACKER_PRIORITIES.map(p => [p.id, p]))

export function priorityMeta(id: TrackerPriority): PriorityMeta {
  return PRIORITY_BY_ID.get(id) ?? TRACKER_PRIORITIES[1]!
}

/**
 * Tag colours, as chip classes.
 *
 * Named rather than hex for the same reason the calendar's are: the stored value survives a
 * re-theme. Every entry has to read on both light and dark, hence the tinted background plus a
 * matching border rather than solid fills.
 */
export const TAG_COLORS: Record<AppTagColor, string> = {
  slate: 'bg-muted text-muted-foreground border-border',
  primary: 'bg-primary/15 text-primary border-primary/30',
  green: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
  amber: 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30',
  red: 'bg-red-500/15 text-red-600 dark:text-red-400 border-red-500/30',
  violet: 'bg-violet-500/15 text-violet-600 dark:text-violet-400 border-violet-500/30',
  sky: 'bg-sky-500/15 text-sky-600 dark:text-sky-400 border-sky-500/30',
}

export function tagChip(color: AppTagColor): string {
  return TAG_COLORS[color] ?? TAG_COLORS.slate
}

/**
 * One line of an item's history, in words.
 *
 * The server stores `kind` + `data` and never a sentence — see the activity migration — so all
 * the phrasing lives here. An unrecognised kind falls back to something honest rather than
 * rendering blank, because a client behind the server will meet kinds it doesn't know.
 */
export function activityLine(entry: AppActivity): string {
  const d = entry.data ?? {}

  switch (entry.kind) {
    case 'created':
      return 'created this issue'
    case 'status':
      return `moved this from ${statusMeta(d.from).label} to ${statusMeta(d.to).label}`
    case 'priority':
      return `changed priority from ${priorityMeta(d.from).label} to ${priorityMeta(d.to).label}`
    case 'assignee':
      if (!d.to) return 'unassigned this'
      return d.from ? 'reassigned this' : 'assigned this'
    case 'due_date':
      if (!d.to) return 'cleared the due date'
      return `set the due date to ${formatDueDate(d.to)}`
    case 'title':
      return 'renamed this'
    default:
      return 'updated this'
  }
}

/**
 * A due date as a short label — "Aug 11".
 *
 * Parsed as local midnight rather than through `new Date('2026-08-11')`, which JavaScript reads
 * as UTC midnight and then renders in the viewer's zone: west of UTC that silently prints the
 * day before, which is exactly the bug that makes a due date untrustworthy.
 */
export function formatDueDate(value: string): string {
  const [y, m, d] = value.split('-').map(Number)
  if (!y || !m || !d) return value
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/** True when a due date has passed — "overdue" is about the day, so today is never overdue. */
export function isOverdue(value: string | null | undefined): boolean {
  if (!value) return false
  const [y, m, d] = value.split('-').map(Number)
  if (!y || !m || !d) return false
  const due = new Date(y, m - 1, d)
  const today = new Date()
  return due < new Date(today.getFullYear(), today.getMonth(), today.getDate())
}
