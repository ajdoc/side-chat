import type { SignupColumnKind } from '~/types'

export const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/** Occurrences of a weekday within a month; -1 is the last one. */
export const WEEK_OPTIONS: { id: number, label: string }[] = [
  { id: 1, label: '1st' },
  { id: 2, label: '2nd' },
  { id: 3, label: '3rd' },
  { id: 4, label: '4th' },
  { id: -1, label: 'Last' },
]

/** "Every Tue & Thu", "2nd & 4th Sat" — a schedule's rule in a few words. */
export function describeRule(weekdays: number[], weeks: number[] | null): string {
  const days = [...weekdays].sort((a, b) => a - b).map(d => WEEKDAYS[d]).join(' & ')
  if (!weeks?.length) return `Every ${days}`
  const which = WEEK_OPTIONS.filter(w => weeks.includes(w.id)).map(w => w.label).join(' & ')
  return `${which} ${days} of the month`
}

/** `2026-09` for a Date (local time). */
export function monthKey(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function nextMonthKey(key: string): string {
  const [y, m] = key.split('-').map(Number)
  return monthKey(new Date(y!, m!, 1))
}

export function monthLabel(key: string): string {
  const [y, m] = key.split('-').map(Number)
  return new Date(y!, m! - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
}

export const SIGNUP_KINDS: { id: SignupColumnKind, label: string, hint: string }[] = [
  { id: 'attending', label: 'Attending', hint: 'Counted and charged the sheet\'s fees' },
  { id: 'waitlist', label: 'Waitlist', hint: 'Counted separately, not charged' },
  { id: 'absent', label: 'Not going', hint: 'Not charged; add a reason after " - "' },
  { id: 'info', label: 'Info', hint: 'Free text, e.g. parking' },
]

/**
 * Column colours. Full class strings, because Tailwind only ships classes it can see written
 * out — `bg-${c}-100` would be purged.
 */
export const SIGNUP_COLORS: Record<string, { head: string, cell: string, swatch: string }> = {
  blue: { head: 'bg-sky-300 dark:bg-sky-700', cell: 'bg-sky-100/80 dark:bg-sky-950/60', swatch: 'bg-sky-400' },
  pink: { head: 'bg-pink-300 dark:bg-pink-800', cell: 'bg-pink-100/80 dark:bg-pink-950/60', swatch: 'bg-pink-400' },
  orange: { head: 'bg-orange-400 dark:bg-orange-700', cell: 'bg-orange-100/80 dark:bg-orange-950/60', swatch: 'bg-orange-400' },
  red: { head: 'bg-red-400 dark:bg-red-800', cell: 'bg-red-100/80 dark:bg-red-950/60', swatch: 'bg-red-400' },
  purple: { head: 'bg-violet-400 dark:bg-violet-800', cell: 'bg-violet-100/80 dark:bg-violet-950/60', swatch: 'bg-violet-400' },
  green: { head: 'bg-green-300 dark:bg-green-800', cell: 'bg-green-100/80 dark:bg-green-950/60', swatch: 'bg-green-400' },
  yellow: { head: 'bg-amber-300 dark:bg-amber-700', cell: 'bg-amber-100/80 dark:bg-amber-950/60', swatch: 'bg-amber-300' },
  teal: { head: 'bg-teal-300 dark:bg-teal-800', cell: 'bg-teal-100/80 dark:bg-teal-950/60', swatch: 'bg-teal-400' },
  slate: { head: 'bg-slate-300 dark:bg-slate-700', cell: 'bg-slate-100/80 dark:bg-slate-900/60', swatch: 'bg-slate-400' },
}

export function signupColor(name: string) {
  return SIGNUP_COLORS[name] ?? SIGNUP_COLORS.slate!
}

/**
 * "Dap - meeting" → name "Dap", note "meeting". The sheet this app replaces put reasons inline
 * like that, so the cell accepts it rather than growing a second field.
 */
export function splitSlotText(text: string): { name: string, note: string | null } {
  const at = text.indexOf(' - ')
  if (at === -1) return { name: text.trim(), note: null }
  return { name: text.slice(0, at).trim(), note: text.slice(at + 3).trim() || null }
}

export function joinSlotText(name: string, note: string | null): string {
  return note ? `${name} - ${note}` : name
}

export function formatMoney(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 })
}
