<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-vue-next'
import type { SignupColumn, SignupEditorPayload, SignupFee, SignupGroup, SignupSchedule, SignupSheet } from '~/types'
import { Input } from '~/components/ui/input'
import { SIGNUP_COLORS, SIGNUP_KINDS, WEEKDAYS, WEEK_OPTIONS, monthKey, signupColor } from '~/lib/signups'

/**
 * The shape of a sheet: title, when, which group, the header rows, the columns and the fees.
 * Used to create or change a sheet, and — in `schedule` mode — a recurring schedule, where
 * "when" is a rule (weekdays, which weeks) instead of a date. Keys are kept as sent, so renaming a column or
 * fee never orphans the names or charges under it — the server mints keys for new ones.
 */
const props = defineProps<{
  sheet?: SignupSheet | SignupSchedule | null
  groups: SignupGroup[]
  submitLabel: string
  mode?: 'sheet' | 'schedule'
  defaultGroupId?: number | null
  /** Schedule mode: the server's answer for which dates a rule gives. */
  preview?: (weekdays: number[], weeks: number[] | null, month: string) => Promise<string[]>
  /**
   * When the edit can reach other sheets (a schedule, or a sheet one made): the choices, first
   * one the default. Omitted = no choice to make.
   */
  scopes?: { id: string, label: string, hint: string }[]
}>()

const emit = defineEmits<{
  save: [payload: SignupEditorPayload]
  cancel: []
}>()

const isSchedule = computed(() => props.mode === 'schedule')
const editingExisting = computed(() => !!props.sheet)

interface Draft {
  title: string
  group_id: number | null
  event_date: string
  weekdays: number[]
  /** Empty = every week. */
  weeks: number[]
  /** Schedule mode, creating: which month to make sheets for straight away ('' = none). */
  month: string
  slots: number
  header: { label: string, value: string }[]
  columns: SignupColumn[]
  fees: (Omit<SignupFee, 'amount'> & { amount: number | string })[]
}

function fromSheet(s: SignupSheet | SignupSchedule | null | undefined): Draft {
  if (!s) {
    // The layout of the team sheet this app was modelled on; everything is editable.
    return {
      title: '',
      group_id: props.defaultGroupId ?? props.groups[0]?.id ?? null,
      event_date: '',
      weekdays: [],
      weeks: [],
      month: monthKey(new Date()),
      slots: 15,
      header: [
        { label: 'Field', value: '' },
        { label: 'Jersey', value: '' },
        { label: 'Notes', value: '' },
      ],
      columns: [
        { key: '', label: 'Boys', color: 'blue', kind: 'attending' },
        { key: '', label: 'Girls', color: 'pink', kind: 'attending' },
        { key: '', label: 'NYS', color: 'orange', kind: 'attending' },
        { key: '', label: 'Not Going (reason)', color: 'red', kind: 'absent' },
      ],
      fees: [
        { key: '', label: 'Field fee', amount: '', note: null },
      ],
    }
  }
  return {
    title: s.title,
    group_id: s.group_id,
    event_date: 'event_date' in s ? (s.event_date ?? '') : '',
    weekdays: 'weekdays' in s ? [...s.weekdays] : [],
    weeks: 'weekdays' in s ? [...(s.weeks ?? [])] : [],
    month: '',
    slots: s.slots,
    header: s.header.map(h => ({ label: h.label, value: h.value ?? '' })),
    columns: s.columns.map(c => ({ ...c })),
    fees: s.fees.map(f => ({ ...f })),
  }
}

const draft = ref<Draft>(fromSheet(props.sheet))

const applyTo = ref(props.scopes?.[0]?.id ?? '')
/** The first scope is the "just this one" choice; anything else reaches other sheets. */
const reachesOthers = computed(() => !!props.scopes?.length && applyTo.value !== props.scopes[0]!.id)

/** Columns removed in this edit, whether or not this sheet has names in them. */
const removedColumns = computed(() => (props.sheet?.columns ?? []).filter(c => !draft.value.columns.some(d => d.key === c.key)))

function move<T>(list: T[], i: number, by: number) {
  const j = i + by
  if (j < 0 || j >= list.length) return
  const [item] = list.splice(i, 1)
  list.splice(j, 0, item!)
}

const colorNames = Object.keys(SIGNUP_COLORS)

/** Existing columns being dropped — worth a warning, since their names go with them. */
const droppedColumns = computed(() => {
  const s = props.sheet
  if (!s || !('entries' in s)) return []
  return s.columns
    .filter(c => !draft.value.columns.some(d => d.key === c.key))
    .filter(c => (s.entries ?? []).some(e => e.column_key === c.key))
})

function toggleIn(list: number[], id: number) {
  const i = list.indexOf(id)
  if (i === -1) list.push(id)
  else list.splice(i, 1)
}

// Schedule preview: which dates the rule gives in the chosen month, asked of the server.
const previewDates = ref<string[]>([])
const previewMonth = computed(() => draft.value.month || monthKey(new Date()))
let previewSeq = 0

watch(() => [isSchedule.value, [...draft.value.weekdays], [...draft.value.weeks], previewMonth.value] as const, async () => {
  if (!isSchedule.value || !props.preview || !draft.value.weekdays.length) {
    previewDates.value = []
    return
  }
  const seq = ++previewSeq
  try {
    const dates = await props.preview(draft.value.weekdays, draft.value.weeks.length ? draft.value.weeks : null, previewMonth.value)
    if (seq === previewSeq) previewDates.value = dates
  }
  catch {
    if (seq === previewSeq) previewDates.value = []
  }
}, { immediate: true })

const shortDate = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })

const canSubmit = computed(() => draft.value.title.trim() !== ''
  && (!isSchedule.value || draft.value.weekdays.length > 0)
  && draft.value.columns.length > 0
  && draft.value.columns.every(c => c.label.trim())
  && draft.value.header.every(h => h.label.trim())
  && draft.value.fees.every(f => f.label.trim() && f.amount !== '' && Number(f.amount) >= 0))

function submit() {
  if (!canSubmit.value) return
  const d = draft.value
  emit('save', {
    title: d.title.trim(),
    group_id: d.group_id,
    ...(isSchedule.value
      ? {
          weekdays: [...d.weekdays].sort((a, b) => a - b),
          weeks: d.weeks.length ? [...d.weeks] : null,
          ...(editingExisting.value ? {} : { month: d.month || null }),
        }
      : { event_date: d.event_date || null }),
    slots: Math.max(1, Math.min(200, Number(d.slots) || 15)),
    header: d.header.map(h => ({ label: h.label.trim(), value: h.value.trim() })),
    columns: d.columns.map(c => ({ ...c, label: c.label.trim() })),
    fees: d.fees.map(f => ({ ...f, key: f.key, label: f.label.trim(), amount: Number(f.amount), note: f.note?.trim() || null })),
    ...(props.scopes?.length ? { apply_to: applyTo.value } : {}),
  })
}

const sectionTitle = 'text-[11px] font-semibold uppercase tracking-wide text-muted-foreground'
const iconBtn = 'grid h-8 w-8 shrink-0 place-items-center rounded border text-muted-foreground transition-colors hover:bg-muted disabled:opacity-40'
</script>

<template>
  <form class="mx-auto max-w-3xl space-y-6" @submit.prevent="submit">
    <section class="space-y-3">
      <Input v-model="draft.title" :placeholder="isSchedule ? 'Schedule name, e.g. Weekday training' : 'Sheet title, e.g. Thursday training vs MUFC'" class="text-base" />
      <div class="flex flex-wrap gap-3">
        <label v-if="!isSchedule" class="flex flex-col gap-1 text-xs text-muted-foreground">
          Date
          <input v-model="draft.event_date" type="date" class="h-9 rounded-md border bg-background px-2 text-sm text-foreground">
        </label>
        <label class="flex flex-col gap-1 text-xs text-muted-foreground">
          Group
          <select v-model="draft.group_id" class="h-9 rounded-md border bg-background px-2 text-sm text-foreground">
            <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
            <option :value="null">Ungrouped</option>
          </select>
        </label>
        <label class="flex flex-col gap-1 text-xs text-muted-foreground">
          Rows per column
          <input v-model.number="draft.slots" type="number" min="1" max="200" class="h-9 w-24 rounded-md border bg-background px-2 text-sm text-foreground">
        </label>
      </div>
      <p v-if="!isSchedule" class="text-xs text-muted-foreground">The sheet is filed under the year of its date.</p>
    </section>

    <section v-if="isSchedule" class="space-y-3">
      <h3 :class="sectionTitle">Repeats on</h3>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="(d, i) in WEEKDAYS"
          :key="d"
          type="button"
          class="h-8 w-12 rounded-md border text-xs font-medium transition-colors"
          :class="draft.weekdays.includes(i) ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
          :aria-pressed="draft.weekdays.includes(i)"
          @click="toggleIn(draft.weekdays, i)"
        >
          {{ d }}
        </button>
      </div>

      <div class="space-y-2">
        <div class="flex flex-wrap gap-4 text-sm">
          <label class="flex cursor-pointer items-center gap-1.5">
            <input type="radio" :checked="!draft.weeks.length" @change="draft.weeks = []"> Every week
          </label>
          <label class="flex cursor-pointer items-center gap-1.5">
            <input type="radio" :checked="draft.weeks.length > 0" @change="draft.weeks = draft.weeks.length ? draft.weeks : [2, 4]"> Only some weeks of the month
          </label>
        </div>
        <div v-if="draft.weeks.length" class="flex flex-wrap gap-1.5">
          <button
            v-for="w in WEEK_OPTIONS"
            :key="w.id"
            type="button"
            class="h-8 rounded-md border px-3 text-xs font-medium transition-colors"
            :class="draft.weeks.includes(w.id) ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
            :aria-pressed="draft.weeks.includes(w.id)"
            :disabled="draft.weeks.length === 1 && draft.weeks.includes(w.id)"
            @click="toggleIn(draft.weeks, w.id)"
          >
            {{ w.label }}
          </button>
        </div>
        <p v-if="draft.weeks.length" class="text-xs text-muted-foreground">
          Counts each chosen weekday within the month. "2nd" means the second Saturday, whatever day the month starts on.
        </p>
      </div>

      <label v-if="!editingExisting" class="flex flex-col gap-1 text-xs text-muted-foreground">
        Make sheets for
        <input v-model="draft.month" type="month" class="h-9 w-44 rounded-md border bg-background px-2 text-sm text-foreground">
      </label>

      <div v-if="draft.weekdays.length" class="rounded-md border bg-muted/30 p-2">
        <p class="mb-1.5 text-xs text-muted-foreground">
          {{ previewDates.length }} {{ previewDates.length === 1 ? 'date' : 'dates' }} in {{ new Date(`${previewMonth}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) }}
        </p>
        <div class="flex flex-wrap gap-1">
          <span v-for="d in previewDates" :key="d" class="rounded bg-background px-1.5 py-0.5 text-xs tabular-nums">{{ shortDate(d) }}</span>
        </div>
      </div>
    </section>

    <section class="space-y-2">
      <h3 :class="sectionTitle">Header</h3>
      <div v-for="(h, i) in draft.header" :key="i" class="flex items-center gap-2">
        <Input v-model="h.label" placeholder="Label" class="h-8 w-32 shrink-0 text-sm" />
        <Input v-model="h.value" placeholder="Value" class="h-8 text-sm" />
        <button type="button" :class="iconBtn" title="Move up" :disabled="i === 0" @click="move(draft.header, i, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
        <button type="button" :class="iconBtn" title="Remove row" @click="draft.header.splice(i, 1)"><X class="h-3.5 w-3.5" /></button>
      </div>
      <button type="button" class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="draft.header.push({ label: '', value: '' })">
        <Plus class="h-3.5 w-3.5" /> Add header row
      </button>
    </section>

    <section class="space-y-2">
      <h3 :class="sectionTitle">Columns</h3>
      <div v-for="(c, i) in draft.columns" :key="i" class="flex flex-wrap items-center gap-2 sm:flex-nowrap">
        <span class="h-8 w-2 shrink-0 rounded" :class="signupColor(c.color).swatch" />
        <Input v-model="c.label" placeholder="Column name" class="h-8 min-w-0 flex-1 text-sm" />
        <select v-model="c.color" class="h-8 rounded-md border bg-background px-2 text-sm" aria-label="Colour">
          <option v-for="n in colorNames" :key="n" :value="n">{{ n }}</option>
        </select>
        <select v-model="c.kind" class="h-8 rounded-md border bg-background px-2 text-sm" aria-label="Kind" :title="SIGNUP_KINDS.find(k => k.id === c.kind)?.hint">
          <option v-for="k in SIGNUP_KINDS" :key="k.id" :value="k.id">{{ k.label }}</option>
        </select>
        <button type="button" :class="iconBtn" title="Move up" :disabled="i === 0" @click="move(draft.columns, i, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
        <button type="button" :class="iconBtn" title="Move down" :disabled="i === draft.columns.length - 1" @click="move(draft.columns, i, 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
        <button type="button" :class="iconBtn" title="Remove column" :disabled="draft.columns.length === 1" @click="draft.columns.splice(i, 1)"><X class="h-3.5 w-3.5" /></button>
      </div>
      <button type="button" class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="draft.columns.push({ key: '', label: '', color: 'slate', kind: 'attending' })">
        <Plus class="h-3.5 w-3.5" /> Add column
      </button>
      <p class="text-xs text-muted-foreground">
        Everyone in an <span class="font-medium text-foreground">Attending</span> column is charged each fee below, once per sheet.
      </p>
      <p v-if="droppedColumns.length" class="rounded-md border border-destructive/40 bg-destructive/5 px-2 py-1.5 text-xs text-destructive">
        Saving removes the names in {{ droppedColumns.map(c => c.label).join(', ') }}.
      </p>
    </section>

    <section class="space-y-2">
      <h3 :class="sectionTitle">Fees</h3>
      <div v-for="(f, i) in draft.fees" :key="i" class="flex flex-wrap items-center gap-2 sm:flex-nowrap">
        <Input v-model="f.label" placeholder="Fee, e.g. Coaching fee" class="h-8 min-w-0 flex-1 text-sm" />
        <input v-model="f.amount" type="number" min="0" step="0.01" placeholder="Amount" class="h-8 w-28 rounded-md border bg-background px-2 text-sm tabular-nums">
        <Input v-model="f.note" placeholder="Note, e.g. per half day" class="h-8 min-w-0 flex-1 text-sm" />
        <button type="button" :class="iconBtn" title="Remove fee" @click="draft.fees.splice(i, 1)"><X class="h-3.5 w-3.5" /></button>
      </div>
      <button type="button" class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground" @click="draft.fees.push({ key: '', label: '', amount: '', note: null })">
        <Plus class="h-3.5 w-3.5" /> Add fee
      </button>
      <p v-if="sheet && !isSchedule" class="text-xs text-muted-foreground">Changing a fee updates unpaid charges only. Paid ones stay as they were.</p>
    </section>

    <section v-if="scopes?.length" class="space-y-2 rounded-lg border p-3">
      <h3 :class="sectionTitle">Apply changes to</h3>
      <label v-for="s in scopes" :key="s.id" class="flex cursor-pointer items-start gap-2 text-sm">
        <input v-model="applyTo" type="radio" :value="s.id" class="mt-1">
        <span>
          {{ s.label }}
          <span class="block text-xs text-muted-foreground">{{ s.hint }}</span>
        </span>
      </label>
      <p v-if="reachesOthers" class="text-xs text-muted-foreground">
        Title, group, header, columns, fees and rows are copied. Dates and names stay as they are, and archived sheets are skipped.
      </p>
      <p v-if="reachesOthers && removedColumns.length" class="rounded-md border border-destructive/40 bg-destructive/5 px-2 py-1.5 text-xs text-destructive">
        Removing {{ removedColumns.map(c => c.label).join(', ') }} also removes the names in it on every sheet this reaches.
      </p>
    </section>

    <div class="flex gap-2 border-t pt-4">
      <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="!canSubmit">
        {{ submitLabel }}
      </button>
      <button type="button" class="px-3 py-1.5 text-sm text-muted-foreground" @click="emit('cancel')">Cancel</button>
    </div>
  </form>
</template>
