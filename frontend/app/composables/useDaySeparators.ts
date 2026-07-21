import type { Ref } from 'vue'

interface DatedMessage {
  id: number
  created_at: string
}

/** Local-calendar-day identity — messages an hour apart across midnight are different days. */
function dayKey(iso: string): string {
  const d = new Date(iso)
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

/** "Today" / "Yesterday" for the recent days, otherwise a full date (year only when it differs). */
function dayLabel(iso: string): string {
  const d = new Date(iso)
  const now = new Date()
  const startOfDay = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diffDays = Math.round((startOfDay(now) - startOfDay(d)) / 86400000)
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  return d.toLocaleDateString([], {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    ...(d.getFullYear() === now.getFullYear() ? {} : { year: 'numeric' }),
  })
}

/**
 * Which messages open a new calendar day, and the label to print above them.
 *
 * Keyed by message id (not index) so it survives the virtual scroller reordering rows and
 * `loadOlder()` prepending history: a message that was the first-of-day at the top can gain
 * an older same-day predecessor and quietly lose its divider, and vice versa. Recomputed
 * whenever `messages` changes, then handed to each row both to render the divider and — via
 * size-dependencies — to make the scroller re-measure the row whose divider came or went.
 */
export function useDaySeparators(messages: Ref<DatedMessage[]>) {
  return computed(() => {
    const labels = new Map<number, string>()
    let prevKey: string | null = null
    for (const m of messages.value) {
      const key = dayKey(m.created_at)
      if (key !== prevKey) labels.set(m.id, dayLabel(m.created_at))
      prevKey = key
    }
    return labels
  })
}
