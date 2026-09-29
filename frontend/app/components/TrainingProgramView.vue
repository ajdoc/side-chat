<script setup lang="ts">
import { Check, ChevronDown, Pencil, Plus, Trash2 } from 'lucide-vue-next'
import { Input } from '~/components/ui/input'
import type { TrainingContent, TrainingProgram } from '~/types'
import {
  currentDay, currentWeek, dateFor, dayProgress, phaseIndex, sessionFor, sessionIdFor, shortDate, tickKey, warmupItem,
  weekRanges, weekSessionsDone, weekSessionsTotal,
} from '~/lib/training'
import {
  addDay, composeMaps, deleteWeek, editable, forkForWeek, freeOffsets, identityMap, insertWeek, newSessionForWeek,
  removeDay, setSessionForPhase, setSessionForWeek, slotsUsing, weekdayLabel,
} from '~/lib/trainingEdit'
import type { WeekMap } from '~/lib/trainingEdit'
import { describeImportErrors } from '~/lib/trainingImport'

/**
 * One program, the way its author laid it out: a field of weeks to pick from, the week's days,
 * and the day's session as a checklist of blocks you tick as you finish them.
 *
 * Staff can switch it into edit mode and change it in place — add and delete weeks and days,
 * change a day's session for one week or a whole phase, and edit or delete exercises. Edits
 * go into a draft and are saved as one document, with a map of how the weeks moved so the
 * server can move everyone's ticks with them.
 */
const props = defineProps<{
  program: TrainingProgram
  training: ReturnType<typeof useTraining>
  canEdit: boolean
  canManage: boolean
}>()

// --- editing state --------------------------------------------------------------------------

const draft = ref<TrainingContent | null>(null)
const editing = computed(() => draft.value !== null)
let original = ''
/** Original week → week in the draft, across every insert and delete since editing began. */
let weekMap: WeekMap = {}
const editTab = ref<'week' | 'schedule'>('week')
const saving = ref(false)
const saveErrors = ref<string[]>([])
const discarding = ref(false)

const content = computed(() => draft.value ?? props.program.content)
const ticks = computed(() => new Set(props.program.mine))
const dirty = computed(() => draft.value !== null && JSON.stringify(draft.value) !== original)

function startEditing() {
  draft.value = editable(props.program.content)
  original = JSON.stringify(draft.value)
  weekMap = identityMap(draft.value.weeks)
  editTab.value = 'week'
  saveErrors.value = []
}

function stopEditing() {
  draft.value = null
  discarding.value = false
  saveErrors.value = []
  week.value = Math.min(week.value, props.program.weeks)
  if (!props.program.content.days.some(d => d.key === day.value)) day.value = props.program.content.days[0]?.key ?? ''
}

function discard() {
  if (dirty.value) discarding.value = true
  else stopEditing()
}

async function save() {
  if (!draft.value) return
  saving.value = true
  saveErrors.value = []
  // Bullet lists are typed one per line; blank lines are just typing, not items.
  for (const r of draft.value.reference) r.items = r.items.map(x => x.trim()).filter(Boolean)
  try {
    const moved = Object.entries(weekMap).some(([from, to]) => Number(from) !== to)
    await props.training.replaceContent(props.program.id, draft.value, moved ? weekMap : undefined)
    stopEditing()
  }
  catch (e: any) {
    const problems = describeImportErrors(e?.data?.errors)
    saveErrors.value = problems.length ? problems : [e?.data?.message ?? 'Couldn’t save. Try again.']
  }
  finally {
    saving.value = false
  }
}

// --- where we are ---------------------------------------------------------------------------

const week = ref(currentWeek(props.program.starts_on, props.program.weeks))
const day = ref(currentDay(props.program.content))

// Rescheduled from elsewhere: follow today into the new calendar.
watch(() => props.program.starts_on, (s) => { if (!editing.value) week.value = currentWeek(s, props.program.weeks) })

const weeks = computed(() => Array.from({ length: content.value.weeks }, (_, i) => i + 1))
const phase = computed(() => phaseIndex(content.value, week.value))
const note = computed(() => content.value.notes?.[String(week.value)] ?? null)

const firstDay = computed(() => content.value.days[0])
const lastDay = computed(() => content.value.days.at(-1))
const weekDates = computed(() => {
  if (!firstDay.value || !lastDay.value) return ''
  const from = dateFor(props.program.starts_on, week.value, firstDay.value.offset)
  const to = dateFor(props.program.starts_on, week.value, lastDay.value.offset)
  return `${shortDate(from)} to ${shortDate(to)}`
})

const thisWeek = computed(() => currentWeek(props.program.starts_on, props.program.weeks))

function weekState(w: number) {
  // While editing, weeks have moved under the ticks, so the pips would lie: show them empty.
  const done = editing.value ? 0 : weekSessionsDone(content.value, ticks.value, w)
  const total = weekSessionsTotal(content.value, w)
  return { done, total, fill: done === 0 ? 'none' : done >= total ? 'full' : 'part' }
}

const currentPhase = computed(() => draft.value?.phases[phase.value] ?? null)

/** Where the thicker lines between phases fall — after the last week of each but the final one. */
const phaseBreaks = computed(() => new Set(content.value.phases.slice(0, -1).map(p => p.to)))

const sessionId = computed(() => sessionIdFor(content.value, week.value, day.value))
const session = computed(() => sessionFor(content.value, week.value, day.value))
const warmup = computed(() => {
  const key = session.value?.warmup
  return key ? content.value.warmups[key] ?? null : null
})
const progress = computed(() => dayProgress(content.value, ticks.value, week.value, day.value))

// --- ticking --------------------------------------------------------------------------------

const error = ref('')

async function toggle(blockId: string) {
  if (!props.canEdit || editing.value) return
  error.value = ''
  const key = tickKey(week.value, day.value, blockId)
  try {
    await props.training.setTick(week.value, day.value, blockId, !ticks.value.has(key))
  }
  catch {
    error.value = 'That tick didn’t save. Try again.'
  }
}

const clearing = ref(false)
const clearBusy = ref(false)

async function confirmClear() {
  clearBusy.value = true
  try {
    await props.training.clearMine()
    clearing.value = false
  }
  finally {
    clearBusy.value = false
  }
}

// --- structural edits -----------------------------------------------------------------------

const field = 'w-full rounded-md border bg-background px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring'

function addWeek(after: number) {
  if (!draft.value || draft.value.weeks >= 52) return
  weekMap = composeMaps(weekMap, insertWeek(draft.value, after))
  week.value = after + 1
}

function removeWeek() {
  if (!draft.value || draft.value.weeks <= 1) return
  weekMap = composeMaps(weekMap, deleteWeek(draft.value, week.value))
  week.value = Math.min(week.value, draft.value.weeks)
}

function setNote(text: string) {
  if (!draft.value) return
  if (text.trim()) draft.value.notes[String(week.value)] = text
  else delete draft.value.notes[String(week.value)]
}

const selectedDay = computed(() => content.value.days.find(d => d.key === day.value) ?? null)

function onAddDay(event: Event) {
  const el = event.target as HTMLSelectElement
  if (!draft.value || el.value === '') return
  day.value = addDay(draft.value, Number(el.value))
  el.value = ''
}

function onRemoveDay() {
  if (!draft.value || draft.value.days.length <= 1) return
  removeDay(draft.value, day.value)
  day.value = draft.value.days[0]?.key ?? ''
}

/** 'week' changes this week only; 'phase' changes every week of the current phase. */
const scope = ref<'week' | 'phase'>('week')

function onPickSession(event: Event) {
  if (!draft.value) return
  const value = (event.target as HTMLSelectElement).value
  if (value === '__new') {
    newSessionForWeek(draft.value, week.value, day.value)
    return
  }
  const id = value || null
  if (scope.value === 'week') setSessionForWeek(draft.value, week.value, day.value, id)
  else setSessionForPhase(draft.value, phase.value, day.value, id)
}

/** Other (week, day) slots showing the same session — so edits to it land there too. */
const sharedWith = computed(() => {
  if (!editing.value || !sessionId.value) return []
  return slotsUsing(content.value, sessionId.value).filter(s => !(s.week === week.value && s.day === day.value))
})

const sharedLabel = computed(() => {
  const byDay = new Map<string, number[]>()
  for (const s of [...sharedWith.value, { week: week.value, day: day.value }]) byDay.set(s.day, [...(byDay.get(s.day) ?? []), s.week])
  return [...byDay.entries()]
    .map(([d, ws]) => `${content.value.days.find(x => x.key === d)?.label ?? d} week ${weekRanges(ws)}`)
    .join(', ')
})

function onlyThisWeek() {
  if (draft.value) forkForWeek(draft.value, week.value, day.value)
}

const sessionOptions = computed(() => Object.entries(content.value.sessions).map(([id, s]) => ({ id, title: s.title })))

// Reference sections.
function addReference() {
  draft.value?.reference.push({ title: 'New section', body: null, items: [] })
}
</script>

<template>
  <ConfirmDialog
    v-model:open="clearing"
    title="Clear all your ticks?"
    :description="`Every block you’ve ticked across all ${program.weeks} weeks of ${program.title} will be unticked. Nobody else’s progress changes.`"
    confirm-label="Clear my ticks"
    busy-label="Clearing…"
    :busy="clearBusy"
    @confirm="confirmClear"
  />

  <ConfirmDialog
    v-model:open="discarding"
    title="Discard your changes?"
    description="Everything you changed since you started editing is thrown away."
    confirm-label="Discard changes"
    @confirm="stopEditing"
  />

  <!-- Edit bar. -->
  <div v-if="editing" class="sticky top-0 z-20 border-b bg-background/95 backdrop-blur">
    <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-2 px-4 py-2">
      <span class="text-sm font-semibold">Editing</span>
      <nav class="flex gap-1">
        <button
          v-for="t in ([['week', 'Week by week'], ['schedule', 'Schedule & phases']] as const)"
          :key="t[0]"
          type="button"
          class="rounded-md px-2.5 py-1 text-sm transition-colors"
          :class="editTab === t[0] ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'"
          @click="editTab = t[0]"
        >
          {{ t[1] }}
        </button>
      </nav>
      <span class="ml-auto flex gap-2">
        <button type="button" class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted" @click="discard">Cancel</button>
        <button
          type="button"
          class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          :disabled="saving || !dirty"
          @click="save"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </span>
    </div>
    <ul v-if="saveErrors.length" class="mx-auto max-w-3xl list-disc px-4 pb-2 pl-9 text-sm text-destructive">
      <li v-for="e in saveErrors" :key="e">{{ e }}</li>
    </ul>
  </div>

  <div v-if="editing && editTab === 'schedule' && draft" class="mx-auto w-full max-w-3xl px-4 pb-10 pt-4">
    <TrainingScheduleEditor :draft="draft" />
  </div>

  <div v-else class="mx-auto w-full max-w-3xl px-4 pb-10 pt-4">
    <div v-if="canManage && !editing" class="mb-3 flex justify-end">
      <button type="button" class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted" @click="startEditing">
        <Pencil class="h-4 w-4" /> Edit program
      </button>
    </div>

    <!-- The field: an end zone at each end and a yard per week. -->
    <div
      class="flex h-20 overflow-hidden rounded-md bg-emerald-800 shadow-[inset_0_0_0_3px_rgba(255,255,255,.85)] dark:bg-emerald-900"
      role="group"
      aria-label="Choose a week"
    >
      <div class="w-4 shrink-0 border-r-2 border-white/85 bg-[repeating-linear-gradient(135deg,rgba(255,255,255,.14)_0_6px,transparent_6px_12px)] sm:w-5" aria-hidden="true" />
      <div class="grid flex-1" :style="{ gridTemplateColumns: `repeat(${weeks.length}, minmax(0, 1fr))` }">
        <button
          v-for="w in weeks"
          :key="w"
          type="button"
          class="relative flex flex-col items-center justify-center gap-2 text-sm font-bold text-white transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-amber-400 sm:text-base"
          :class="[
            w === week ? 'bg-white/15' : 'hover:bg-white/5',
            w === weeks.length ? '' : phaseBreaks.has(w) ? 'border-r-2 border-white/75' : 'border-r border-white/30',
          ]"
          :aria-pressed="w === week"
          :aria-label="`Week ${w}, ${weekState(w).done} of ${weekState(w).total} sessions done${w === thisWeek ? ', this week' : ''}`"
          @click="week = w"
        >
          <span :class="w === thisWeek && !editing ? 'underline decoration-amber-400 decoration-2 underline-offset-4' : ''">{{ w }}</span>
          <span
            class="h-3 w-3 rounded-full border-2 sm:h-3.5 sm:w-3.5"
            :class="[
              weekState(w).fill === 'full' ? 'border-white bg-white' : 'border-white/70',
              w === week ? 'ring-[3px] ring-amber-400' : '',
            ]"
            :style="weekState(w).fill === 'part' ? { background: 'linear-gradient(90deg,#fff 50%,transparent 50%)' } : undefined"
          />
        </button>
      </div>
      <div class="w-4 shrink-0 border-l-2 border-white/85 bg-[repeating-linear-gradient(135deg,rgba(255,255,255,.14)_0_6px,transparent_6px_12px)] sm:w-5" aria-hidden="true" />
    </div>

    <!-- Phases, under the weeks they cover. -->
    <div class="mt-1.5 flex px-4 text-xs text-muted-foreground sm:px-5" aria-hidden="true">
      <span
        v-for="(p, i) in content.phases"
        :key="p.name + p.from"
        class="mx-0.5 truncate border-t-2 pt-1"
        :class="i === phase ? 'border-amber-400 font-semibold text-foreground' : 'border-border'"
        :style="{ flex: `${p.to - p.from + 1} 1 0` }"
      >
        {{ p.name }}
      </span>
    </div>

    <div class="mt-6 flex flex-wrap items-baseline gap-x-3 gap-y-1">
      <h2 class="text-3xl font-extrabold leading-none tracking-tight">Week {{ week }}</h2>
      <span v-if="!editing" class="text-muted-foreground">{{ weekDates }}</span>
      <span v-if="week === thisWeek && !editing" class="rounded-full bg-amber-400/20 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">This week</span>
    </div>

    <!-- Week actions. -->
    <div v-if="editing && draft" class="mt-3 flex flex-wrap gap-2">
      <button type="button" class="flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted disabled:opacity-40" :disabled="draft.weeks >= 52" @click="addWeek(week - 1)">
        <Plus class="h-4 w-4" /> Week before
      </button>
      <button type="button" class="flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted disabled:opacity-40" :disabled="draft.weeks >= 52" @click="addWeek(week)">
        <Plus class="h-4 w-4" /> Week after
      </button>
      <button type="button" class="flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm text-destructive hover:bg-destructive/10 disabled:opacity-40" :disabled="draft.weeks <= 1" @click="removeWeek">
        <Trash2 class="h-4 w-4" /> Delete week {{ week }}
      </button>
      <p class="w-full text-xs text-muted-foreground">
        A new week follows the sessions of the phase it lands in. Deleting a week removes everyone's ticks in it; ticks in later weeks move up with their week.
      </p>
    </div>

    <template v-if="editing && draft">
      <label class="mt-4 block">
        <span class="text-xs font-medium text-muted-foreground">{{ content.phases[phase]?.name }} phase: what it's for</span>
        <div class="mt-1 flex gap-2">
          <template v-if="currentPhase">
            <Input v-model="currentPhase.name" class="h-9 w-36 shrink-0" maxlength="40" aria-label="Phase name" />
            <Input v-model="currentPhase.line" class="h-9" maxlength="500" placeholder="One line on this phase" />
          </template>
        </div>
      </label>
      <label class="mt-3 block">
        <span class="text-xs font-medium text-muted-foreground">Note for week {{ week }}</span>
        <textarea :value="note ?? ''" rows="2" :class="field" class="mt-1" maxlength="1000" placeholder="e.g. Lighter week. Cut reps by 40%." @input="setNote(($event.target as HTMLTextAreaElement).value)" />
      </label>
    </template>
    <template v-else>
      <p class="mt-1.5 max-w-prose text-sm">{{ content.phases[phase]?.line }}</p>
      <p v-if="note" class="mt-3 rounded-r-md border-l-4 border-amber-400 bg-muted/50 px-3 py-2 text-sm">{{ note }}</p>
    </template>

    <!-- Days. A dot marks a hard day; a green border, one you've finished. -->
    <div
      class="mt-5 grid gap-1.5"
      :style="{ gridTemplateColumns: `repeat(${content.days.length}, minmax(0, 1fr))` }"
      role="tablist"
      aria-label="Choose a day"
    >
      <button
        v-for="d in content.days"
        :key="d.key"
        type="button"
        role="tab"
        class="rounded-lg border-2 px-1 py-1.5 text-center transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400"
        :class="d.key === day
          ? 'border-emerald-600 bg-emerald-800 text-white dark:bg-emerald-800'
          : !editing && dayProgress(content, ticks, week, d.key).total > 0 && dayProgress(content, ticks, week, d.key).done === dayProgress(content, ticks, week, d.key).total
            ? 'border-emerald-600 bg-card hover:bg-muted'
            : 'border-border bg-card hover:bg-muted'"
        :aria-selected="d.key === day"
        @click="day = d.key"
      >
        <b class="flex items-center justify-center gap-1 text-base font-bold leading-none">
          {{ d.label }}
          <span v-if="sessionFor(content, week, d.key)?.hard" class="h-1.5 w-1.5 rounded-full bg-amber-400" />
        </b>
        <small class="mt-1 block truncate text-[11px] leading-tight" :class="d.key === day ? 'text-white/85' : 'text-muted-foreground'">
          {{ d.tag }}
        </small>
      </button>
    </div>

    <!-- Day actions. -->
    <div v-if="editing && draft" class="mt-3 flex flex-wrap items-end gap-2">
      <template v-if="selectedDay">
        <label class="block">
          <span class="text-xs font-medium text-muted-foreground">Day name</span>
          <Input v-model="selectedDay.label" class="mt-1 h-8 w-20 text-sm" maxlength="16" />
        </label>
        <label class="block">
          <span class="text-xs font-medium text-muted-foreground">Tag</span>
          <Input v-model="selectedDay.tag" class="mt-1 h-8 w-36 text-sm" maxlength="40" placeholder="e.g. Cutting" />
        </label>
        <button type="button" class="flex h-8 items-center gap-1 rounded-md border px-2.5 text-sm text-destructive hover:bg-destructive/10 disabled:opacity-40" :disabled="draft.days.length <= 1" @click="onRemoveDay">
          <Trash2 class="h-4 w-4" /> Delete {{ selectedDay.label }}
        </button>
      </template>
      <select v-if="freeOffsets(draft).length" :class="field" class="ml-auto h-8 w-auto py-0" aria-label="Add a day" @change="onAddDay">
        <option value="">+ Add a day…</option>
        <option v-for="o in freeOffsets(draft)" :key="o" :value="o">{{ weekdayLabel(o) }}</option>
      </select>
    </div>

    <!-- The session, in edit mode. -->
    <section v-if="editing && draft" class="mt-4 rounded-xl border bg-card p-4 sm:p-5">
      <div class="flex flex-wrap items-end gap-2">
        <label class="block min-w-0 flex-1">
          <span class="text-xs font-medium text-muted-foreground">{{ selectedDay?.label }}, week {{ week }}</span>
          <select :value="sessionId ?? ''" :class="field" class="mt-1" @change="onPickSession">
            <option value="">Rest day</option>
            <option v-for="o in sessionOptions" :key="o.id" :value="o.id">{{ o.title }}</option>
            <option value="__new">+ New session for this week</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs font-medium text-muted-foreground">Change it for</span>
          <select v-model="scope" :class="field" class="mt-1 w-auto">
            <option value="week">Week {{ week }} only</option>
            <option value="phase">Every {{ content.phases[phase]?.name }} week ({{ content.phases[phase]?.from }}–{{ content.phases[phase]?.to }})</option>
          </select>
        </label>
      </div>

      <div v-if="sharedWith.length" class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-amber-400/60 bg-amber-400/10 px-3 py-2 text-sm">
        <span class="min-w-0 flex-1">This session is used on {{ sharedLabel }}. Edits below change all of them.</span>
        <button type="button" class="rounded-md border bg-background px-2.5 py-1 text-sm hover:bg-muted" @click="onlyThisWeek">
          Edit week {{ week }} only
        </button>
      </div>

      <div v-if="session" class="mt-4">
        <TrainingSessionEditor :key="sessionId ?? ''" :session="draft.sessions[sessionId!]!" :warmups="draft.warmups" />
      </div>
      <p v-else class="mt-4 text-sm text-muted-foreground">Rest day. Pick a session above, or make a new one.</p>
    </section>

    <!-- The session. -->
    <section v-else-if="session" class="mt-4 rounded-xl border bg-card p-4 sm:p-5" role="tabpanel">
      <h3 class="text-2xl font-bold leading-tight">{{ session.title }}</h3>
      <p class="mt-1 text-sm text-muted-foreground">{{ [session.time, session.hard ? 'hard day' : 'easy on the legs'].filter(Boolean).join(', ') }}</p>
      <p v-if="session.intent" class="mt-3">{{ session.intent }}</p>

      <details v-if="warmup" class="group mt-4 border-t pt-3">
        <summary class="flex cursor-pointer list-none items-center gap-1 font-semibold">
          <ChevronDown class="h-4 w-4 -rotate-90 transition-transform group-open:rotate-0" /> {{ warmup.label }}
        </summary>
        <ul class="mt-2 list-disc space-y-1 pl-6 text-sm">
          <li v-for="(item, i) in warmup.items" :key="i">
            {{ warmupItem(item).text }}
            <TrainingVideoLinks :videos="warmupItem(item).videos" />
          </li>
        </ul>
      </details>

      <ol class="mt-4">
        <li
          v-for="(b, i) in session.blocks"
          :key="`${week}-${day}-${b.id}`"
          class="grid grid-cols-[2rem_1fr_2.25rem] items-start gap-2.5 border-t py-3 last:pb-0"
        >
          <span class="text-2xl font-extrabold leading-none text-emerald-700 dark:text-emerald-400">{{ i + 1 }}</span>
          <div>
            <div :class="ticks.has(tickKey(week, day, b.id)) ? 'opacity-60' : ''">
              <p class="font-semibold" :class="ticks.has(tickKey(week, day, b.id)) ? 'line-through' : ''">{{ b.name }}</p>
              <p class="mt-0.5" :class="ticks.has(tickKey(week, day, b.id)) ? 'line-through' : ''">{{ b.dose }}</p>
              <p class="mt-1 text-sm text-muted-foreground">{{ b.cue }}</p>
            </div>
            <TrainingVideoLinks :videos="b.videos ?? []" />
          </div>
          <button
            type="button"
            role="checkbox"
            class="grid h-8 w-8 place-items-center rounded-full border-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
            :class="ticks.has(tickKey(week, day, b.id)) ? 'border-amber-400 bg-amber-400 text-amber-950' : 'border-border bg-background hover:border-amber-400'"
            :aria-checked="ticks.has(tickKey(week, day, b.id))"
            :aria-label="`Mark ${b.name} done`"
            :disabled="!canEdit"
            @click="toggle(b.id)"
          >
            <Check v-if="ticks.has(tickKey(week, day, b.id))" class="h-4 w-4" stroke-width="3" />
          </button>
        </li>
      </ol>

      <div class="mt-4 flex items-center gap-2.5 text-sm text-muted-foreground">
        <span>{{ progress.done }} of {{ progress.total }} done</span>
        <span class="h-2 flex-1 overflow-hidden rounded bg-muted">
          <i
            class="block h-full bg-amber-400 transition-[width] duration-200 motion-reduce:transition-none"
            :style="{ width: `${progress.total ? Math.round(progress.done / progress.total * 100) : 0}%` }"
          />
        </span>
      </div>
      <p v-if="error" class="mt-2 text-sm text-destructive">{{ error }}</p>
    </section>
    <p v-else class="mt-4 rounded-xl border bg-card p-5 text-sm text-muted-foreground">Rest day. Nothing planned.</p>

    <!-- Reference, edited in place. -->
    <section v-if="editing && draft" class="mt-8">
      <h2 class="text-xl font-bold">Reference</h2>
      <div v-for="(r, i) in draft.reference" :key="i" class="mt-2 space-y-2 rounded-lg border bg-card p-3">
        <div class="flex gap-2">
          <Input v-model="r.title" class="h-8 font-medium" maxlength="120" placeholder="Section title" />
          <button type="button" class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive" title="Delete section" @click="draft.reference.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
        </div>
        <textarea
          :value="r.body ?? ''"
          rows="2"
          :class="field"
          maxlength="2000"
          placeholder="Paragraph (optional)"
          @input="r.body = ($event.target as HTMLTextAreaElement).value || null"
        />
        <textarea
          :value="r.items.join('\n')"
          rows="3"
          :class="field"
          placeholder="Bullet points, one per line (optional)"
          @input="r.items = ($event.target as HTMLTextAreaElement).value.split('\n')"
        />
      </div>
      <button v-if="draft.reference.length < 20" type="button" class="mt-2 flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted" @click="addReference">
        <Plus class="h-4 w-4" /> Section
      </button>
    </section>

    <section v-else-if="content.reference?.length" class="mt-8">
      <h2 class="text-xl font-bold">Reference</h2>
      <details v-for="r in content.reference" :key="r.title" class="group mt-2 rounded-lg border bg-card px-3.5 py-2.5">
        <summary class="flex cursor-pointer list-none items-center gap-1 font-semibold">
          <ChevronDown class="h-4 w-4 -rotate-90 transition-transform group-open:rotate-0" /> {{ r.title }}
        </summary>
        <p v-if="r.body" class="mt-2 text-sm">{{ r.body }}</p>
        <ul v-if="r.items.length" class="mt-2 list-disc space-y-1 pl-6 text-sm">
          <li v-for="(item, i) in r.items" :key="i">{{ item }}</li>
        </ul>
      </details>
    </section>

    <button
      v-if="canEdit && !editing && program.mine.length"
      type="button"
      class="mt-6 text-sm text-muted-foreground underline hover:text-foreground"
      @click="clearing = true"
    >
      Clear all my ticks
    </button>
  </div>
</template>
