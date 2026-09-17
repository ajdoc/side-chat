<script setup lang="ts">
import { Copy, Lock, LockOpen, Pencil, Trash2 } from 'lucide-vue-next'
import type { SignupEditorPayload, SignupEntry, SignupSheet } from '~/types'
import { describeRule, formatMoney, signupColor } from '~/lib/signups'

/**
 * One sheet, drawn like the spreadsheet it replaces: a header block, then numbered rows under
 * coloured columns. Any member fills slots; staff reshape, lock, copy and delete.
 */
const props = defineProps<{
  sheet: SignupSheet
  signups: ReturnType<typeof useSignups>
  canEdit: boolean
  canManage: boolean
}>()

const emit = defineEmits<{ copied: [sheet: SignupSheet, created: number], deleted: [] }>()

const { roster } = props.signups

// The roster feeds the suggestions; it's small, so filter it locally and refresh on each open.
void props.signups.loadRoster()

const editing = ref(false)
const error = ref('')

function message(e: any) {
  return e?.data?.message ?? 'Something went wrong. Try again.'
}

const writable = computed(() => props.canEdit && !props.sheet.locked && !props.sheet.archived)

const bySlot = computed(() => {
  const map = new Map<string, SignupEntry>()
  for (const e of props.sheet.entries ?? []) map.set(`${e.column_key}:${e.position}`, e)
  return map
})

const taken = computed(() => new Set((props.sheet.entries ?? []).map(e => e.name.toLowerCase())))

const rows = computed(() => Array.from({ length: props.sheet.slots }, (_, i) => i + 1))

const countFor = (key: string) => (props.sheet.entries ?? []).filter(e => e.column_key === key).length

const attending = computed(() => {
  const keys = new Set(props.sheet.columns.filter(c => c.kind === 'attending').map(c => c.key))
  return new Set((props.sheet.entries ?? []).filter(e => keys.has(e.column_key)).map(e => e.person_id)).size
})

const perHead = computed(() => props.sheet.fees.reduce((n, f) => n + Number(f.amount), 0))

async function save(column: string, position: number, name: string, note: string | null) {
  error.value = ''
  try {
    await props.signups.setSlot(props.sheet.id, column, position, name, note)
  }
  catch (e) {
    error.value = message(e)
  }
}

const notice = ref('')

/** A sheet a schedule made can pass its edit along — the calendar-style choice. */
const scopes = computed(() => props.sheet.series_id === null
  ? undefined
  : [
      { id: 'this', label: 'Only this sheet', hint: 'The schedule and its other sheets stay as they are.' },
      { id: 'following', label: 'This and following sheets', hint: 'This sheet, every later one from the schedule, and months made from now on.' },
      { id: 'all', label: 'All sheets in this schedule', hint: 'Every sheet it made, earlier ones too, and months made from now on.' },
    ])

async function saveShape(payload: Partial<SignupSheet> | SignupEditorPayload) {
  error.value = ''
  notice.value = ''
  try {
    const res = await props.signups.updateSheet(props.sheet.id, payload)
    if (res.applied) notice.value = `Also updated ${res.applied} other ${res.applied === 1 ? 'sheet' : 'sheets'} and the schedule.`
    else if ('apply_to' in payload && payload.apply_to !== 'this') notice.value = 'Schedule updated. No other sheets needed changing.'
    editing.value = false
  }
  catch (e) {
    error.value = message(e)
  }
}

async function toggleLock() {
  await saveShape({ locked: !props.sheet.locked })
}

const copying = ref(false)

function onCopied(copy: SignupSheet, created: number) {
  emit('copied', copy, created)
}

const removing = ref(false)
const removeBusy = ref(false)

async function confirmRemove() {
  removeBusy.value = true
  try {
    await props.signups.deleteSheet(props.sheet.id)
    removing.value = false
    emit('deleted')
  }
  finally {
    removeBusy.value = false
  }
}

const dateLabel = computed(() => props.sheet.event_date
  ? new Date(`${props.sheet.event_date}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
  : null)

const groupName = computed(() => props.signups.groups.value.find(g => g.id === props.sheet.group_id)?.name ?? 'Ungrouped')

const schedule = computed(() => props.signups.schedules.value.find(s => s.id === props.sheet.series_id) ?? null)

const toolBtn = 'flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-muted'
</script>

<template>
  <ConfirmDialog
    v-model:open="removing"
    title="Delete this sheet?"
    description="Its names and every charge it raised go with it, paid or not. This cannot be undone."
    confirm-label="Delete sheet"
    busy-label="Deleting…"
    :busy="removeBusy"
    @confirm="confirmRemove"
  />

  <SignupCopyDialog
    v-model:open="copying"
    :sheet="sheet"
    :groups="signups.groups.value"
    :copy="signups.duplicateSheet"
    @copied="onCopied"
  />

  <div v-if="editing" class="min-h-0 flex-1 overflow-y-auto p-4">
    <p v-if="error" class="mx-auto mb-3 max-w-3xl text-sm text-destructive">{{ error }}</p>
    <SignupSheetEditor :sheet="sheet" :groups="signups.groups.value" :scopes="scopes" submit-label="Save changes" @save="saveShape" @cancel="editing = false" />
  </div>

  <div v-else class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
    <div class="mb-3 flex flex-wrap items-center gap-2">
      <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ groupName }} · {{ sheet.year }}</span>
      <span v-if="schedule" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground" title="Made by a schedule">
        {{ schedule.title }}: {{ describeRule(schedule.weekdays, schedule.weeks) }}
      </span>
      <span v-if="sheet.archived" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Archived</span>
      <span v-else-if="sheet.locked" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Locked. Sign-ups are closed.</span>
      <span class="flex-1" />
      <template v-if="canManage">
        <button v-if="!sheet.archived" type="button" :class="toolBtn" @click="editing = true"><Pencil class="h-3.5 w-3.5" /> Edit layout</button>
        <button v-if="!sheet.archived" type="button" :class="toolBtn" @click="toggleLock">
          <component :is="sheet.locked ? LockOpen : Lock" class="h-3.5 w-3.5" /> {{ sheet.locked ? 'Unlock' : 'Lock' }}
        </button>
        <button type="button" :class="toolBtn" @click="copying = true"><Copy class="h-3.5 w-3.5" /> Copy</button>
        <button type="button" :class="[toolBtn, 'text-destructive']" @click="removing = true"><Trash2 class="h-3.5 w-3.5" /> Delete</button>
      </template>
    </div>


    <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>
    <p v-if="notice" class="mb-3 rounded-md border bg-muted/40 px-3 py-2 text-sm">{{ notice }}</p>

    <div class="overflow-x-auto rounded-lg border">
      <table class="w-full min-w-max border-collapse">
        <!-- The header block: title and date, then the sheet's own label/value rows. -->
        <tbody>
          <tr>
            <th class="w-14 border border-border/60 bg-muted px-2 py-1 text-xs font-semibold">Sheet</th>
            <td :colspan="sheet.columns.length" class="border border-border/60 bg-[#0b3a63] px-3 py-1.5 text-center font-semibold text-white">
              {{ sheet.title }}
            </td>
          </tr>
          <tr v-if="dateLabel">
            <th class="border border-border/60 bg-muted px-2 py-1 text-xs font-semibold">Date</th>
            <td :colspan="sheet.columns.length" class="border border-border/60 bg-[#0b3a63] px-3 py-1.5 text-center text-lg font-bold text-white">
              {{ dateLabel }}
            </td>
          </tr>
          <tr v-for="(h, i) in sheet.header" :key="`h${i}`">
            <th class="border border-border/60 bg-muted px-2 py-1 text-xs font-semibold">{{ h.label }}</th>
            <td :colspan="sheet.columns.length" class="whitespace-pre-line border border-border/60 bg-[#0b3a63] px-3 py-1.5 text-center text-sm font-semibold text-white">
              {{ h.value || ' ' }}
            </td>
          </tr>
          <tr v-if="sheet.fees.length">
            <th class="border border-border/60 bg-muted px-2 py-1 text-xs font-semibold">Fees</th>
            <td :colspan="sheet.columns.length" class="border border-border/60 bg-[#0b3a63] px-3 py-1.5 text-center text-sm font-semibold text-white">
              <span v-for="(f, i) in sheet.fees" :key="f.key">
                <template v-if="i">&ensp;·&ensp;</template>{{ f.label }}: {{ formatMoney(Number(f.amount)) }}<template v-if="f.note"> {{ f.note }}</template>
              </span>
            </td>
          </tr>
        </tbody>

        <tbody>
          <tr>
            <th class="border border-border/60 bg-muted px-2 py-1.5 text-xs font-semibold">No.</th>
            <th
              v-for="c in sheet.columns"
              :key="c.key"
              class="min-w-36 border border-border/60 px-2 py-1.5 text-sm font-semibold"
              :class="signupColor(c.color).head"
            >
              {{ c.label }}
              <span class="ml-1 text-xs font-normal opacity-70">{{ countFor(c.key) }}</span>
            </th>
          </tr>
        </tbody>

        <tbody>
          <tr v-for="n in rows" :key="n">
            <th class="border border-border/60 bg-muted px-2 text-xs font-semibold tabular-nums">{{ n }}</th>
            <SignupSlot
              v-for="c in sheet.columns"
              :key="`${c.key}:${n}`"
              :name="bySlot.get(`${c.key}:${n}`)?.name ?? null"
              :note="bySlot.get(`${c.key}:${n}`)?.note ?? null"
              :roster="roster"
              :taken="taken"
              :editable="writable"
              :cell-class="signupColor(c.color).cell"
              :save="(name, note) => save(c.key, n, name, note)"
            />
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <span><span class="font-semibold tabular-nums text-foreground">{{ attending }}</span> attending</span>
      <span v-if="sheet.fees.length">
        <span class="font-semibold tabular-nums text-foreground">{{ formatMoney(attending * perHead) }}</span> expected in fees
      </span>
      <span v-if="writable">Click a cell to sign up. Add a reason after a dash, e.g. "Dap - meeting".</span>
    </div>
  </div>
</template>
