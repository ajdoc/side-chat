import type { CalendarEvent } from '~/types'

/**
 * What's scheduled in this room.
 *
 * A meeting is a calendar entry with a `room_channel_id` — there is no meetings table, because a
 * second concept would need its own reminders, its own editor and its own idea of "when", and
 * would then disagree with the calendar about all three (see MeetingController).
 *
 * What this adds is the *room's* view of it. The entry lives in a text channel's calendar
 * ("Design → Standup, 10:00, in 🔊 Standup Room"), so without this the room itself couldn't
 * answer the question people ask while standing in it: is something happening here, and when?
 *
 * Polled rather than pushed. A meeting's arrival is a *clock* event, not a user action — nothing
 * broadcasts when 09:50 becomes 09:51 — so a socket would still need a timer to notice the thing
 * that matters. One small request every couple of minutes covers both.
 */
export function useRoomMeetings(channelId: MaybeRefOrGetter<number | null>) {
  const api = useApi()

  const meetings = ref<CalendarEvent[]>([])
  /** Ticks so the countdown re-renders without refetching. */
  const now = ref(Date.now())

  let poll: ReturnType<typeof setInterval> | undefined
  let clock: ReturnType<typeof setInterval> | undefined

  async function load() {
    const id = toValue(channelId)
    if (id == null) return

    try {
      const res = await api<{ data: CalendarEvent[] }>(`/api/channels/${id}/meetings`)
      meetings.value = res.data
    }
    catch {
      // A room with no readable calendar is a room with no meetings banner, which is the same
      // room it was before this existed.
      meetings.value = []
    }
  }

  /** On now: started, and not yet over (an entry with no end is assumed to run an hour). */
  const current = computed(() => meetings.value.find((m) => {
    const start = new Date(m.starts_at).getTime()
    const end = m.ends_at ? new Date(m.ends_at).getTime() : start + 60 * 60 * 1000
    return start <= now.value && end > now.value
  }) ?? null)

  /** The next one that hasn't started. */
  const next = computed(() => meetings.value.find(m => new Date(m.starts_at).getTime() > now.value) ?? null)

  /** "in 12 minutes", "in 2 hours", "tomorrow at 09:00" — the countdown people read. */
  function until(meeting: CalendarEvent) {
    const start = new Date(meeting.starts_at)
    const minutes = Math.round((start.getTime() - now.value) / 60000)

    if (minutes <= 0) return 'now'
    if (minutes < 60) return `in ${minutes} minute${minutes === 1 ? '' : 's'}`

    const hours = Math.round(minutes / 60)
    if (hours < 12) return `in ${hours} hour${hours === 1 ? '' : 's'}`

    return start.toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })
  }

  onMounted(() => {
    void load()
    poll = setInterval(() => void load(), 120_000)
    clock = setInterval(() => { now.value = Date.now() }, 30_000)
  })

  onBeforeUnmount(() => {
    clearInterval(poll)
    clearInterval(clock)
  })

  watch(() => toValue(channelId), () => void load())

  return { meetings, current, next, until, load }
}
