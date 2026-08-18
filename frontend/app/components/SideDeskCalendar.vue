<script setup lang="ts">
import { BellRing, CalendarPlus, Check, ChevronLeft, ChevronRight, Link as LinkIcon, Trash2, X } from 'lucide-vue-next'
import type { CalendarEvent, CalendarEventColor, CalendarRoom } from '~/types'

/**
 * The Calendar app — a surface's shared schedule.
 *
 * Surface-agnostic via the same base-path / stream contract the board, notes and canvas use, so
 * one component serves a channel's calendar, a DM's and a side chat's. Read-only when `canEdit`
 * is false, with `readonlyHint` saying why.
 *
 * Renders a month grid with an agenda for the selected day underneath. `compact` drops the grid
 * to just the agenda — that's the mode the Open Canvas card uses, where a full month in a 280px
 * box would be six rows of unreadable numbers.
 *
 * State comes from {@link useCalendar}, which is shared per surface: this component and the
 * calendar card on the canvas are two views of one list, not two lists that mostly agree.
 */
const props = withDefaults(defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  readonlyHint?: string
  compact?: boolean
  /**
   * An editor to open as soon as this mounts — from a room's "Schedule" button, which reaches
   * here through the URL and SideDeskPanel. Absent in a floating window or on the canvas, which
   * shouldn't react to the page's query at all.
   */
  compose?: { meeting: boolean, eventId: number | null }
}>(), { compact: false })

const emit = defineEmits<{ composed: [] }>()

const { events, loaded, open, add, patch, remove } = useCalendar(props.basePath, props.streamName)

open()
// The room list is small, static and needed the moment somebody opens the editor, so it's
// fetched once with the calendar rather than on each compose. The compose shortcut waits on it.
void loadRooms()

// --- the month being looked at ------------------------------------------------------------

/** Local midnight of a date — the client works in the viewer's zone, the API in UTC. */
function startOfDay(d: Date) {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate())
}

const today = startOfDay(new Date())
const cursor = ref(new Date(today.getFullYear(), today.getMonth(), 1))
const selected = ref<Date>(today)

const monthLabel = computed(() =>
  cursor.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }),
)

function shiftMonth(by: number) {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + by, 1)
}

/**
 * Weekday initials, in the viewer's own locale. 2024-01-07 was a Sunday, so walking seven days
 * from it names a full week in the same Sunday-first order the grid is built in.
 */
const weekdays = Array.from({ length: 7 }, (_, i) =>
  new Date(2024, 0, 7 + i).toLocaleDateString(undefined, { weekday: 'narrow' }))

/**
 * The 42 cells of the month grid — six weeks, always.
 *
 * Fixed at six rather than sized to the month on purpose: a grid that grows and shrinks by a row
 * as you page through the year makes everything below it jump, and in a narrow desk panel that
 * reads as the layout breaking.
 */
const grid = computed(() => {
  const first = new Date(cursor.value.getFullYear(), cursor.value.getMonth(), 1)
  const start = new Date(first)
  start.setDate(1 - first.getDay())

  return Array.from({ length: 42 }, (_, i) => {
    const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i)
    return {
      date,
      key: date.toDateString(),
      inMonth: date.getMonth() === cursor.value.getMonth(),
      isToday: date.getTime() === today.getTime(),
    }
  })
})

// --- events by day --------------------------------------------------------------------------

/**
 * Every event that touches a given day, indexed once per render rather than filtered per cell.
 *
 * A span is written into every day it covers, so a three-day event shows on all three — the
 * naive "index by start date" version silently hides multi-day entries on every day but the
 * first, which is the bug you don't notice until someone misses the middle of a trip.
 */
const byDay = computed(() => {
  const map = new Map<string, CalendarEvent[]>()

  for (const e of events.value) {
    const from = startOfDay(new Date(e.starts_at))
    const to = e.ends_at ? startOfDay(new Date(e.ends_at)) : from

    for (const d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      const key = d.toDateString()
      const list = map.get(key)
      if (list) list.push(e)
      else map.set(key, [e])
    }
  }

  return map
})

function eventsOn(date: Date) {
  return byDay.value.get(date.toDateString()) ?? []
}

const selectedEvents = computed(() => eventsOn(selected.value))

const selectedLabel = computed(() =>
  selected.value.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }),
)

/** In compact mode there's no grid to pick a day from, so the agenda shows what's coming up. */
const upcoming = computed(() => {
  const from = today.getTime()
  return events.value
    .filter(e => new Date(e.ends_at ?? e.starts_at).getTime() >= from)
    .slice(0, 25)
})

const agenda = computed(() => (props.compact ? upcoming.value : selectedEvents.value))

// --- the composer ---------------------------------------------------------------------------

const COLORS: CalendarEventColor[] = ['primary', 'green', 'amber', 'rose', 'violet', 'teal', 'slate']

/** Named colours → theme tokens. Kept here so the API stores a name, not a hex. */
const SWATCH: Record<CalendarEventColor, string> = {
  primary: 'bg-primary',
  green: 'bg-emerald-500',
  amber: 'bg-amber-500',
  rose: 'bg-rose-500',
  violet: 'bg-violet-500',
  teal: 'bg-teal-500',
  slate: 'bg-slate-500',
}

const composing = ref(false)
const editing = ref<CalendarEvent | null>(null)
const draft = reactive({
  title: '',
  description: '',
  date: '',
  time: '',
  endTime: '',
  allDay: false,
  color: 'primary' as CalendarEventColor,
  /** Minutes before the start to post a notice in the channel. Empty string is "no reminder". */
  remind: '' as string,
  /** The room it happens in. Empty string is "nowhere in particular". */
  room: '' as string,
})
const saving = ref(false)
const error = ref('')

/**
 * What "remind me" may be set to.
 *
 * A fixed list, mirroring CalendarEvent::REMIND_CHOICES — a dropdown rather than a number field,
 * because "7 minutes before" is a thing nobody wants and every list of reminders would then have
 * to render.
 */
const REMIND_CHOICES: { value: string, label: string }[] = [
  { value: '', label: 'No reminder' },
  { value: '0', label: 'When it starts' },
  { value: '5', label: '5 minutes before' },
  { value: '10', label: '10 minutes before' },
  { value: '15', label: '15 minutes before' },
  { value: '30', label: '30 minutes before' },
  { value: '60', label: '1 hour before' },
  { value: '120', label: '2 hours before' },
  { value: '1440', label: 'A day before' },
]

/**
 * The rooms this surface may point an entry at.
 *
 * Fetched from the endpoint that answers with exactly what the save validates against, and only
 * on a channel surface: a side chat's calendar has no server to draw rooms from, and the picker
 * is simply absent there rather than empty.
 */
const rooms = ref<CalendarRoom[]>([])

/**
 * The link to the meeting — the room, with the entry named in the query.
 *
 * Built here rather than served by the API for the same reason the side chat's link is: the path
 * is the frontend's, and a server emitting `/servers/3/channels/9?meeting=12` would be a second
 * place the routes are written down. Deliberately *not* an auto-join link: arriving in a room
 * with your microphone already live is not something a pasted URL should be able to do.
 */
const meetingLink = computed(() => {
  const room = rooms.value.find(r => String(r.id) === draft.room)
  if (!room?.server_id || !editing.value) return null
  return `${window.location.origin}/servers/${room.server_id}/channels/${room.id}?meeting=${editing.value.id}`
})

const linkCopied = ref(false)

async function copyMeetingLink() {
  if (!meetingLink.value) return
  try {
    await navigator.clipboard.writeText(meetingLink.value)
    linkCopied.value = true
    setTimeout(() => { linkCopied.value = false }, 1500)
  }
  catch {
    // Clipboard refused (insecure context, or a browser that asks). The field below is
    // selectable, so there is still a way to get the link.
  }
}

async function loadRooms() {
  if (!/\/api\/channels\/\d+$/.test(props.basePath)) return
  try {
    const res = await useApi()<{ data: CalendarRoom[] }>(`${props.basePath}/calendar/rooms`)
    rooms.value = res.data
  }
  catch {
    // No rooms offered is a working editor; an entry without one is the common case anyway.
  }
}

/** `<input type="date">` wants local `YYYY-MM-DD`; toISOString would shift it by the offset. */
function toDateInput(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function toTimeInput(d: Date) {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

function startCompose(day?: Date) {
  if (!props.canEdit) return
  editing.value = null
  error.value = ''
  const d = day ?? selected.value
  Object.assign(draft, {
    title: '',
    description: '',
    date: toDateInput(props.compact ? today : d),
    time: '09:00',
    endTime: '',
    allDay: false,
    color: 'primary' as CalendarEventColor,
    remind: '',
    room: '',
  })
  composing.value = true
}

function startEdit(e: CalendarEvent) {
  if (!props.canEdit) return
  editing.value = e
  error.value = ''
  const start = new Date(e.starts_at)
  const end = e.ends_at ? new Date(e.ends_at) : null
  Object.assign(draft, {
    title: e.title,
    description: e.description ?? '',
    date: toDateInput(start),
    time: toTimeInput(start),
    endTime: end ? toTimeInput(end) : '',
    allDay: e.all_day,
    color: e.color,
    remind: e.remind_minutes == null ? '' : String(e.remind_minutes),
    room: e.room_channel_id == null ? '' : String(e.room_channel_id),
  })
  composing.value = true
}

/**
 * Build the instant to store from the date and time fields.
 *
 * `new Date(y, m, d, h, min)` reads the parts as *local* and yields the right UTC instant, which
 * is what we want: someone typing 09:00 means nine in their own morning. Parsing the strings
 * with `new Date('2026-01-01T09:00')` is what you must not do — that's spec'd as UTC in some
 * engines and local in others.
 */
function instant(date: string, time: string) {
  const [y, m, d] = date.split('-').map(Number)
  const [h, min] = (time || '00:00').split(':').map(Number)
  return new Date(y!, m! - 1, d!, h!, min!)
}

async function save() {
  if (!draft.title.trim() || !draft.date) return
  saving.value = true
  error.value = ''

  // An all-day entry is pinned to local midnight; only a timed one uses the clock fields.
  const starts = instant(draft.date, draft.allDay ? '00:00' : draft.time)
  const ends = !draft.allDay && draft.endTime ? instant(draft.date, draft.endTime) : null

  const body = {
    title: draft.title.trim(),
    description: draft.description.trim() || null,
    starts_at: starts.toISOString(),
    ends_at: ends?.toISOString() ?? null,
    all_day: draft.allDay,
    color: draft.color,
    // Empty string is "none" for both — sent as null so clearing one actually clears it, which
    // an omitted key would not (the API patches only what it's given).
    remind_minutes: draft.remind === '' ? null : Number(draft.remind),
    room_channel_id: draft.room === '' ? null : Number(draft.room),
  }

  try {
    if (editing.value) await patch(editing.value.id, body as Partial<CalendarEvent>)
    else await add(body)
    composing.value = false
    if (!props.compact) selected.value = startOfDay(starts)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save that. Please try again.'
  } finally {
    saving.value = false
  }
}

async function destroy(e: CalendarEvent) {
  if (!props.canEdit) return
  try {
    await remove(e.id)
    if (editing.value?.id === e.id) composing.value = false
  } catch {
    // The optimistic removal already rolled back; nothing more to say here.
  }
}

/** "09:00", "09:00 – 10:30", or "All day". */
function timeLabel(e: CalendarEvent) {
  if (e.all_day) return 'All day'
  const fmt = (s: string) => new Date(s).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
  return e.ends_at ? `${fmt(e.starts_at)} – ${fmt(e.ends_at)}` : fmt(e.starts_at)
}

/** In compact mode the agenda spans days, so each row has to say which day it's on. */
function dayLabel(e: CalendarEvent) {
  return new Date(e.starts_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Header: month paging (full mode) or just the title and an add button (compact). -->
    <div class="flex shrink-0 items-center gap-1 border-b p-2">
      <template v-if="!compact">
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          aria-label="Previous month"
          @click="shiftMonth(-1)"
        >
          <ChevronLeft class="h-4 w-4" />
        </button>
        <button
          type="button"
          class="grid h-7 w-7 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          aria-label="Next month"
          @click="shiftMonth(1)"
        >
          <ChevronRight class="h-4 w-4" />
        </button>
        <span class="ml-1 min-w-0 truncate text-sm font-medium">{{ monthLabel }}</span>
      </template>
      <span v-else class="min-w-0 flex-1 truncate text-sm font-medium">Upcoming</span>

      <button
        type="button"
        class="ml-auto flex shrink-0 items-center gap-1.5 rounded border px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :disabled="!canEdit"
        @click="startCompose()"
      >
        <CalendarPlus class="h-3.5 w-3.5" /> Add
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
      <!-- Month grid -->
      <div v-if="!compact" class="border-b p-2">
        <div class="grid grid-cols-7 gap-px text-center">
          <span v-for="(w, i) in weekdays" :key="i" class="pb-1 text-[10px] uppercase tracking-wide text-muted-foreground">{{ w }}</span>
        </div>
        <div class="grid grid-cols-7 gap-px">
          <button
            v-for="cell in grid"
            :key="cell.key"
            type="button"
            class="flex aspect-square flex-col items-center justify-start gap-0.5 rounded p-1 text-xs transition-colors"
            :class="[
              cell.date.getTime() === selected.getTime()
                ? 'bg-primary text-primary-foreground'
                : cell.inMonth ? 'hover:bg-muted' : 'text-muted-foreground/40 hover:bg-muted/50',
              cell.isToday && cell.date.getTime() !== selected.getTime() ? 'ring-1 ring-inset ring-primary' : '',
            ]"
            @click="selected = cell.date"
            @dblclick="startCompose(cell.date)"
          >
            <span :class="cell.isToday ? 'font-semibold' : ''">{{ cell.date.getDate() }}</span>
            <!-- Up to three dots, then a count — a cell this size can't list titles. -->
            <span class="flex flex-wrap justify-center gap-0.5">
              <span
                v-for="e in eventsOn(cell.date).slice(0, 3)"
                :key="e.id"
                class="h-1 w-1 rounded-full"
                :class="cell.date.getTime() === selected.getTime() ? 'bg-primary-foreground' : SWATCH[e.color]"
              />
            </span>
          </button>
        </div>
      </div>

      <!-- Agenda -->
      <div class="p-2">
        <p v-if="!compact" class="px-1 pb-1.5 text-xs font-medium text-muted-foreground">{{ selectedLabel }}</p>

        <p v-if="!loaded" class="px-1 py-6 text-center text-xs text-muted-foreground">Loading…</p>
        <p v-else-if="!agenda.length" class="px-1 py-6 text-center text-xs text-muted-foreground">
          {{ compact ? 'Nothing coming up.' : 'Nothing on this day.' }}
        </p>

        <ul v-else class="space-y-1">
          <li
            v-for="e in agenda"
            :key="e.id"
            class="group flex items-start gap-2 rounded-lg border bg-card p-2 transition-colors"
            :class="canEdit ? 'cursor-pointer hover:bg-muted/50' : ''"
            @click="startEdit(e)"
          >
            <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="SWATCH[e.color]" />
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ e.title }}</p>
              <p class="flex flex-wrap items-center gap-x-1.5 text-[11px] text-muted-foreground">
                <span v-if="compact">{{ dayLabel(e) }} · </span>{{ timeLabel(e) }}
                <!-- Both facts belong on the row: an entry that will announce itself and an
                     entry that won't are different things to have on a shared calendar. -->
                <span v-if="e.room" class="rounded-full bg-muted px-1.5">
                  {{ e.room.type === 'space' ? '🗺️' : '🔊' }} {{ e.room.name }}
                </span>
                <BellRing v-if="e.remind_minutes != null" class="h-3 w-3" :title="`Reminds the channel`" />
              </p>
              <p v-if="e.description" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{{ e.description }}</p>
            </div>
            <button
              v-if="canEdit"
              type="button"
              class="flex-none text-muted-foreground opacity-0 reveal-touch transition hover:text-destructive group-hover:opacity-100"
              title="Delete event"
              @click.stop="destroy(e)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </li>
        </ul>

        <p v-if="!canEdit && readonlyHint" class="px-1 pt-2 text-xs text-muted-foreground">{{ readonlyHint }}</p>
      </div>
    </div>

    <!-- Composer. An inline sheet rather than a modal dialog: the desk is often a narrow panel
         beside a chat, and a centred modal over the whole window to add a lunch is too much. -->
    <div v-if="composing" class="shrink-0 border-t bg-card p-3">
      <div class="mb-2 flex items-center justify-between">
        <p class="text-sm font-medium">{{ editing ? 'Edit event' : 'New event' }}</p>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="composing = false">
          <X class="h-4 w-4" />
        </button>
      </div>

      <div class="space-y-2">
        <input
          v-model="draft.title"
          class="w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring"
          placeholder="What is it?"
          @keydown.enter="save"
        >

        <div class="flex flex-wrap gap-2">
          <input v-model="draft.date" type="date" class="min-w-0 flex-1 rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring">
          <template v-if="!draft.allDay">
            <input v-model="draft.time" type="time" class="w-28 rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring">
            <input v-model="draft.endTime" type="time" class="w-28 rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring" title="Ends (optional)">
          </template>
        </div>

        <label class="flex items-center gap-2 text-xs text-muted-foreground">
          <input v-model="draft.allDay" type="checkbox" class="h-3.5 w-3.5 accent-[var(--primary)]"> All day
        </label>

        <textarea
          v-model="draft.description"
          rows="2"
          class="w-full resize-none rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring"
          placeholder="Notes (optional)"
        />

        <div class="flex items-center gap-1.5">
          <button
            v-for="c in COLORS"
            :key="c"
            type="button"
            class="h-5 w-5 rounded-full transition"
            :class="[SWATCH[c], draft.color === c ? 'ring-2 ring-foreground ring-offset-2 ring-offset-card' : 'opacity-60 hover:opacity-100']"
            :aria-label="c"
            @click="draft.color = c"
          />
        </div>

        <!--
          Reminder and room: the two fields that make an entry *happen* rather than merely be
          recorded. Both optional, and both default to off — most calendar rows are records, and
          a channel that announced all of them is a channel people mute.
        -->
        <label class="block space-y-1">
          <span class="text-xs text-muted-foreground">Remind the channel</span>
          <select
            v-model="draft.remind"
            class="w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring"
          >
            <option v-for="choice in REMIND_CHOICES" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
          </select>
        </label>

        <label v-if="rooms.length" class="block space-y-1">
          <span class="text-xs text-muted-foreground">Where it happens</span>
          <select
            v-model="draft.room"
            class="w-full rounded border bg-background px-2 py-1.5 text-sm outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="">Nowhere in particular</option>
            <option v-for="room in rooms" :key="room.id" :value="String(room.id)">
              {{ room.type === 'space' ? '🗺️' : '🔊' }} {{ room.name }}
            </option>
          </select>
          <!-- The room is what turns the reminder into a way in rather than a fact. -->
          <span class="text-[11px] text-muted-foreground">Named in the reminder, so people know where to go.</span>
        </label>

        <!--
          The meeting link. Only for a saved entry, because the link names it — a new entry has no
          id yet, and offering a button that produced a broken URL would be worse than waiting.
        -->
        <button
          v-if="meetingLink"
          type="button"
          class="flex w-full items-center gap-1.5 rounded border px-2 py-1.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
          @click="copyMeetingLink"
        >
          <component :is="linkCopied ? Check : LinkIcon" class="h-3.5 w-3.5" />
          {{ linkCopied ? 'Link copied' : 'Copy meeting link' }}
        </button>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

        <div class="flex gap-2 pt-0.5">
          <button
            type="button"
            class="flex-1 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition hover:opacity-90 disabled:opacity-50"
            :disabled="saving || !draft.title.trim()"
            @click="save"
          >
            {{ saving ? 'Saving…' : editing ? 'Save' : 'Add event' }}
          </button>
          <button
            v-if="editing"
            type="button"
            class="rounded-lg border px-3 py-1.5 text-sm text-muted-foreground transition hover:bg-muted hover:text-destructive"
            @click="destroy(editing)"
          >
            <Trash2 class="h-4 w-4" />
          </button>
        </div>

        <!--
          Tags and a comment thread on the entry being edited.

          Only when editing, because both hang off an id and a new event doesn't have one yet.
          The component fetches and writes on its own — see AppItemDiscussion — which is what
          "the polymorphic tables paid off" looks like in practice: the Calendar gained a
          discussion without learning what a comment is.

          Compact mode is excluded: that's the 280px canvas card, where a comment thread under
          the form would be a scrollbar inside a scrollbar.
        -->
        <AppItemDiscussion
          v-if="editing && !compact"
          :base-path="basePath"
          subject="calendar_event"
          :item-id="editing.id"
          :can-edit="canEdit"
        />
      </div>
    </div>
  </div>
</template>
