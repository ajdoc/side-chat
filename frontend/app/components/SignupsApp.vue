<script setup lang="ts">
import { Archive, ArchiveRestore, CalendarPlus, ChevronLeft, Copy, Pencil, Plus, Repeat, Tags, Trash2 } from 'lucide-vue-next'
import type { SignupEditorPayload, SignupSchedule, SignupSheet } from '~/types'
import { describeRule, formatMoney, monthKey, monthLabel, nextMonthKey, signupColor } from '~/lib/signups'

/**
 * The Sign-ups app — a channel's sign-up sheets, filtered by the channel's own groups and
 * listed by month, with the recurring schedules that make them, the roster their names come
 * from, and the payables their fees raise.
 *
 * Screens rather than routes, for the same reason as the Tracker: this can be a channel, a desk
 * tab or a floating window, and only one of those has a URL.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
}>()

const signups = useSignups(props.basePath, props.streamName)
const { sheets, years, year, current, groups, schedules, canManage, loaded } = signups

signups.open()

const manage = computed(() => canManage.value && props.canEdit)

type Tab = 'sheets' | 'payables' | 'roster'
type Screen = 'wall' | 'sheet' | 'new-sheet' | 'new-schedule' | 'edit-schedule' | 'groups'

const tab = ref<Tab>('sheets')
const screen = ref<Screen>('wall')
/** Group filter: an id, 'all', or 'none' for ungrouped. */
const groupFilter = ref<number | 'all' | 'none'>('all')
const error = ref('')
const notice = ref('')

function message(e: any) {
  return e?.data?.message ?? 'Something went wrong. Try again.'
}

const openId = ref<number | null>(null)
/** Shown above a sheet just made by copying. */
const sheetNotice = ref('')

async function openSheet(id: number) {
  openId.value = id
  screen.value = 'sheet'
  await signups.openSheet(id)
}

function back() {
  openId.value = null
  editingSchedule.value = null
  sheetNotice.value = ''
  error.value = ''
  notice.value = ''
  screen.value = 'wall'
  signups.closeSheet()
}

// The open sheet was deleted, here or elsewhere.
watch(current, (sheet) => {
  if (sheet === null && screen.value === 'sheet' && !sheets.value.some(s => s.id === openId.value)) back()
})

// A filter pointing at a group that has since been deleted falls back to everything.
watch(groups, (list) => {
  if (typeof groupFilter.value === 'number' && !list.some(g => g.id === groupFilter.value)) groupFilter.value = 'all'
})

const filterGroupId = computed(() => typeof groupFilter.value === 'number' ? groupFilter.value : null)

function inFilter(groupId: number | null) {
  if (groupFilter.value === 'all') return true
  if (groupFilter.value === 'none') return groupId === null
  return groupId === groupFilter.value
}

const hasUngrouped = computed(() => sheets.value.some(s => s.group_id === null) || schedules.value.some(s => s.group_id === null))

const groupOf = (id: number | null) => groups.value.find(g => g.id === id) ?? null

const yearInfo = computed(() => years.value.find(y => y.year === year.value) ?? null)
const yearArchived = computed(() => yearInfo.value?.archived ?? false)

/** Sheets by month, newest month first; within a month, in date order. Undated last. */
const byMonth = computed(() => {
  const map = new Map<string, SignupSheet[]>()
  for (const s of sheets.value.filter(s => inFilter(s.group_id))) {
    const key = s.event_date ? s.event_date.slice(0, 7) : 'none'
    map.set(key, [...(map.get(key) ?? []), s])
  }
  return [...map.entries()]
    .sort(([a], [b]) => (a === 'none' ? 1 : b === 'none' ? -1 : b.localeCompare(a)))
    .map(([key, list]) => ({
      key,
      label: key === 'none' ? 'No date' : monthLabel(key),
      sheets: [...list].sort((a, b) => (a.event_date ?? '').localeCompare(b.event_date ?? '') || a.id - b.id),
    }))
})

const visibleSchedules = computed(() => schedules.value.filter(s => inFilter(s.group_id)))

function attendingCount(s: SignupSheet) {
  return s.columns.filter(c => c.kind === 'attending').reduce((n, c) => n + (s.counts?.[c.key] ?? 0), 0)
}

function feeTotal(fees: { amount: number }[]) {
  return fees.reduce((n, f) => n + Number(f.amount), 0)
}

function formatDate(d: string | null) {
  if (!d) return 'No date'
  return new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

// --- creating --------------------------------------------------------------------------------

async function createSheet(payload: SignupEditorPayload) {
  error.value = ''
  try {
    const sheet = await signups.createSheet(payload)
    openId.value = sheet.id
    screen.value = 'sheet'
  }
  catch (e) {
    error.value = message(e)
  }
}

async function createSchedule(payload: SignupEditorPayload) {
  error.value = ''
  try {
    const res = await signups.createSchedule({ ...payload, weekdays: payload.weekdays ?? [] })
    back()
    notice.value = res.created
      ? `Schedule saved. ${res.created} ${res.created === 1 ? 'sheet' : 'sheets'} made for ${monthLabel(payload.month!)}.`
      : 'Schedule saved.'
  }
  catch (e) {
    error.value = message(e)
  }
}

// --- copying one sheet ------------------------------------------------------------------------

/** The sheet the copy dialog is for — from a card on the wall. */
const copyingSheet = ref<SignupSheet | null>(null)
const copyDialogOpen = computed({
  get: () => copyingSheet.value !== null,
  set: (v: boolean) => { if (!v) copyingSheet.value = null },
})

/** Open the first copy; say how many were made. The composable already made it current. */
function onCopied(copy: SignupSheet, created: number) {
  openId.value = copy.id
  screen.value = 'sheet'
  notice.value = ''
  sheetNotice.value = created > 1 ? `Made ${created} copies. This is the first.` : 'Copied. This is the new sheet.'
}

// --- schedules -------------------------------------------------------------------------------

const editingSchedule = ref<SignupSchedule | null>(null)

const scheduleScopes = [
  { id: 'none', label: 'Future months only', hint: 'Sheets already made stay as they are.' },
  { id: 'upcoming', label: 'Upcoming sheets too', hint: 'Also updates its sheets from today on.' },
  { id: 'all', label: 'All its sheets', hint: 'Also updates every sheet it has made, past ones too.' },
]

function editSchedule(s: SignupSchedule) {
  editingSchedule.value = s
  screen.value = 'edit-schedule'
}

async function saveSchedule(payload: SignupEditorPayload) {
  if (!editingSchedule.value) return
  error.value = ''
  try {
    const res = await signups.updateSchedule(editingSchedule.value.id, payload)
    back()
    notice.value = payload.apply_to === 'none'
      ? 'Schedule updated. It applies to months you make from now on.'
      : `Schedule updated, along with ${res.applied} existing ${res.applied === 1 ? 'sheet' : 'sheets'}.`
  }
  catch (e) {
    error.value = message(e)
  }
}

/** Which month each schedule's Generate button targets; next month by default. */
const generateMonth = ref<Record<number, string>>({})
const monthFor = (id: number) => generateMonth.value[id] ?? nextMonthKey(monthKey(new Date()))

async function runGenerate(s: SignupSchedule) {
  error.value = ''
  notice.value = ''
  const month = monthFor(s.id)
  try {
    const res = await signups.generate(s.id, month)
    notice.value = res.created
      ? `${res.created} ${res.created === 1 ? 'sheet' : 'sheets'} made for ${monthLabel(month)}.`
      : `${monthLabel(month)} already has every ${s.title} sheet.`
  }
  catch (e) {
    error.value = message(e)
  }
}

const deletingSchedule = ref<SignupSchedule | null>(null)
const deleteScheduleBusy = ref(false)

async function confirmDeleteSchedule() {
  if (!deletingSchedule.value) return
  deleteScheduleBusy.value = true
  try {
    await signups.deleteSchedule(deletingSchedule.value.id)
    deletingSchedule.value = null
  }
  finally {
    deleteScheduleBusy.value = false
  }
}

// --- copy a month forward --------------------------------------------------------------------

const copying = ref(false)
const copyFrom = ref(monthKey(new Date()))
const copyBusy = ref(false)

async function runCopy() {
  error.value = ''
  notice.value = ''
  copyBusy.value = true
  try {
    const res = await signups.copyMonth(copyFrom.value, filterGroupId.value)
    copying.value = false
    notice.value = res.created
      ? `${res.created} ${res.created === 1 ? 'sheet' : 'sheets'} copied into ${monthLabel(res.month)}.`
      : `Nothing new to copy. ${monthLabel(res.month)} already has these sheets.`
  }
  catch (e) {
    error.value = message(e)
  }
  finally {
    copyBusy.value = false
  }
}

// --- archiving a year ------------------------------------------------------------------------

const archiving = ref(false)
const archiveBusy = ref(false)

async function confirmArchive() {
  if (year.value == null) return
  archiveBusy.value = true
  try {
    await signups.setArchived(year.value, !yearArchived.value)
    archiving.value = false
  }
  finally {
    archiveBusy.value = false
  }
}

const tabs: { id: Tab, label: string }[] = [
  { id: 'sheets', label: 'Sheets' },
  { id: 'payables', label: 'Payables' },
  { id: 'roster', label: 'Roster' },
]

const toolBtn = 'flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-muted'
const chip = 'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors'
</script>

<template>
  <ConfirmDialog
    v-model:open="archiving"
    :title="yearArchived ? `Restore ${year}?` : `Archive ${year}?`"
    :description="yearArchived
      ? 'Its sheets become editable again.'
      : 'Every sheet in this year becomes read-only. Names, fees and payments are kept, and you can restore the year later.'"
    :confirm-label="yearArchived ? 'Restore year' : 'Archive year'"
    busy-label="Saving…"
    :busy="archiveBusy"
    variant="default"
    @confirm="confirmArchive"
  />

  <ConfirmDialog
    :open="deletingSchedule !== null"
    :title="`Delete the ${deletingSchedule?.title ?? ''} schedule?`"
    description="No new sheets will be made from it. Sheets it already made stay, with their names and payments."
    confirm-label="Delete schedule"
    busy-label="Deleting…"
    :busy="deleteScheduleBusy"
    @update:open="(v: boolean) => { if (!v) deletingSchedule = null }"
    @confirm="confirmDeleteSchedule"
  />

  <SignupCopyDialog
    v-model:open="copyDialogOpen"
    :sheet="copyingSheet"
    :groups="groups"
    :copy="signups.duplicateSheet"
    @copied="onCopied"
  />

  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-3">
      <button
        v-if="screen !== 'wall'"
        type="button"
        class="flex items-center gap-1 rounded-md border px-2 py-1.5 text-sm transition-colors hover:bg-muted"
        @click="back"
      >
        <ChevronLeft class="h-4 w-4" /> Back
      </button>
      <p v-if="screen === 'new-schedule'" class="font-semibold">New schedule</p>
      <p v-else-if="screen === 'edit-schedule'" class="font-semibold">Edit schedule</p>
      <p v-else-if="screen === 'new-sheet'" class="font-semibold">New sheet</p>
      <p v-else-if="screen === 'groups'" class="font-semibold">Groups</p>
      <template v-else-if="screen === 'wall'">
        <p class="font-semibold">Sign-ups</p>
        <nav class="ml-2 flex gap-1">
          <button
            v-for="t in tabs"
            :key="t.id"
            type="button"
            class="rounded-md px-2.5 py-1 text-sm transition-colors"
            :class="tab === t.id ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'"
            @click="tab = t.id"
          >
            {{ t.label }}
          </button>
        </nav>
      </template>
    </header>

    <div v-if="!loaded" class="grid flex-1 place-items-center text-sm text-muted-foreground">
      Loading…
    </div>

    <div v-else-if="screen === 'new-sheet' || screen === 'new-schedule' || screen === 'edit-schedule'" class="min-h-0 flex-1 overflow-y-auto p-4">
      <p v-if="error" class="mx-auto mb-3 max-w-3xl text-sm text-destructive">{{ error }}</p>
      <SignupSheetEditor
        :key="`${screen}-${editingSchedule?.id ?? ''}`"
        :mode="screen === 'new-sheet' ? 'sheet' : 'schedule'"
        :sheet="screen === 'edit-schedule' ? editingSchedule : null"
        :groups="groups"
        :default-group-id="filterGroupId"
        :preview="signups.previewSchedule"
        :scopes="screen === 'edit-schedule' ? scheduleScopes : undefined"
        :submit-label="screen === 'new-sheet' ? 'Create sheet' : screen === 'new-schedule' ? 'Save schedule' : 'Save changes'"
        @save="screen === 'new-sheet' ? createSheet($event) : screen === 'new-schedule' ? createSchedule($event) : saveSchedule($event)"
        @cancel="back"
      />
    </div>

    <SignupGroupsManager v-else-if="screen === 'groups'" :signups="signups" />

    <template v-else-if="screen === 'sheet'">
      <p v-if="sheetNotice && current" class="mx-3 mt-3 rounded-md border bg-muted/40 px-3 py-2 text-sm sm:mx-4">{{ sheetNotice }}</p>
      <SignupSheetView
        v-if="current"
        :key="current.id"
        :sheet="current"
        :signups="signups"
        :can-edit="canEdit"
        :can-manage="manage"
        @copied="onCopied"
        @deleted="back"
      />
      <div v-else class="grid flex-1 place-items-center text-sm text-muted-foreground">Loading…</div>
    </template>

    <SignupPayablesView
      v-else-if="tab === 'payables'"
      :signups="signups"
      :can-manage="manage"
      :default-year="year"
    />

    <SignupRosterView
      v-else-if="tab === 'roster'"
      :signups="signups"
      :can-manage="manage"
    />

    <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
      <div class="mb-3 flex flex-wrap items-center gap-2">
        <select
          :value="year ?? ''"
          class="h-8 rounded-md border bg-background px-2 text-sm"
          aria-label="Year"
          @change="signups.load(Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-if="!years.some(y => y.year === year)" :value="year ?? ''">{{ year }}</option>
          <option v-for="y in years" :key="y.year" :value="y.year">
            {{ y.year }}{{ y.archived ? ' (archived)' : '' }}
          </option>
        </select>
        <span class="flex-1" />
        <template v-if="manage">
          <button type="button" :class="toolBtn" @click="screen = 'new-sheet'"><Plus class="h-3.5 w-3.5" /> New sheet</button>
          <button type="button" :class="toolBtn" @click="screen = 'new-schedule'"><Repeat class="h-3.5 w-3.5" /> New schedule</button>
          <button type="button" :class="toolBtn" @click="copying = !copying"><Copy class="h-3.5 w-3.5" /> Copy a month</button>
          <button type="button" :class="toolBtn" @click="screen = 'groups'"><Tags class="h-3.5 w-3.5" /> Groups</button>
          <button v-if="yearInfo" type="button" :class="toolBtn" @click="archiving = true">
            <component :is="yearArchived ? ArchiveRestore : Archive" class="h-3.5 w-3.5" />
            {{ yearArchived ? `Restore ${year}` : `Archive ${year}` }}
          </button>
        </template>
      </div>

      <div class="mb-4 flex flex-wrap gap-1.5">
        <button
          type="button"
          :class="[chip, groupFilter === 'all' ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted']"
          @click="groupFilter = 'all'"
        >
          All
        </button>
        <button
          v-for="g in groups"
          :key="g.id"
          type="button"
          :class="[chip, groupFilter === g.id ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted']"
          @click="groupFilter = g.id"
        >
          <span class="h-2 w-2 rounded-full" :class="signupColor(g.color).swatch" />
          {{ g.name }}
        </button>
        <button
          v-if="hasUngrouped"
          type="button"
          :class="[chip, groupFilter === 'none' ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted']"
          @click="groupFilter = 'none'"
        >
          Ungrouped
        </button>
      </div>

      <form v-if="copying" class="mb-4 flex flex-wrap items-end gap-2 rounded-lg border p-3" @submit.prevent="runCopy">
        <label class="flex flex-col gap-1 text-xs text-muted-foreground">
          Copy sheets from
          <input v-model="copyFrom" type="month" required class="h-8 rounded-md border bg-background px-2 text-sm text-foreground">
        </label>
        <button type="submit" class="h-8 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50" :disabled="copyBusy || !copyFrom">
          {{ copyBusy ? 'Copying…' : `Copy to ${monthLabel(nextMonthKey(copyFrom || monthKey(new Date())))}` }}
        </button>
        <button type="button" class="h-8 px-2 text-sm text-muted-foreground" @click="copying = false">Cancel</button>
        <p class="w-full text-xs text-muted-foreground">
          <template v-if="filterGroupId !== null">Only {{ groupOf(filterGroupId)?.name }} sheets. </template>
          Names aren't copied. Sheets from a schedule follow its rule, and one-off sheets keep their place in the month (the 2nd Tuesday stays the 2nd Tuesday). Sheets already in the next month are skipped.
        </p>
      </form>

      <p v-if="notice" class="mb-3 rounded-md border bg-muted/40 px-3 py-2 text-sm">{{ notice }}</p>
      <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>
      <p v-if="yearArchived" class="mb-4 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
        {{ year }} is archived. Its sheets are read-only.
      </p>

      <!-- Schedules: the recurring sessions, and the button that makes a month of them. -->
      <section v-if="visibleSchedules.length" class="mb-8 space-y-2">
        <h2 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Schedules</h2>
        <div class="grid gap-3 lg:grid-cols-2">
          <div v-for="s in visibleSchedules" :key="s.id" class="rounded-xl border p-3">
            <div class="flex items-start gap-2">
              <Repeat class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
              <div class="min-w-0 flex-1">
                <p class="font-medium leading-tight">{{ s.title }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ describeRule(s.weekdays, s.weeks) }}
                  <template v-if="groupOf(s.group_id)"> · {{ groupOf(s.group_id)!.name }}</template>
                  <template v-if="s.fees.length"> · {{ formatMoney(feeTotal(s.fees)) }} per head</template>
                </p>
              </div>
              <template v-if="manage">
                <button type="button" class="grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-foreground" title="Edit schedule" @click="editSchedule(s)">
                  <Pencil class="h-3.5 w-3.5" />
                </button>
                <button type="button" class="grid h-7 w-7 place-items-center rounded text-muted-foreground hover:bg-muted hover:text-destructive" title="Delete schedule" @click="deletingSchedule = s">
                  <Trash2 class="h-3.5 w-3.5" />
                </button>
              </template>
            </div>
            <form v-if="manage" class="mt-3 flex flex-wrap items-center gap-2" @submit.prevent="runGenerate(s)">
              <input
                :value="monthFor(s.id)"
                type="month"
                required
                class="h-8 rounded-md border bg-background px-2 text-sm"
                aria-label="Month to make sheets for"
                @input="generateMonth[s.id] = ($event.target as HTMLInputElement).value"
              >
              <button type="submit" :class="toolBtn"><CalendarPlus class="h-3.5 w-3.5" /> Make sheets</button>
            </form>
          </div>
        </div>
      </section>

      <section v-for="m in byMonth" :key="m.key" class="mb-8 space-y-3">
        <h2 class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          {{ m.label }} <span class="font-normal">· {{ m.sheets.length }}</span>
        </h2>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <div v-for="s in m.sheets" :key="s.id" class="group relative">
            <button
              type="button"
              class="w-full rounded-xl border p-3 text-left transition-colors hover:bg-muted/60"
              @click="openSheet(s.id)"
            >
              <div class="flex items-start justify-between gap-2">
                <p class="font-medium leading-tight">{{ s.title }}</p>
                <span v-if="s.locked && !s.archived" class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-muted-foreground">Locked</span>
              </div>
              <p class="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                {{ formatDate(s.event_date) }}
                <Repeat v-if="s.series_id" class="h-3 w-3" aria-label="From a schedule" />
              </p>
              <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 pr-8 text-xs text-muted-foreground">
                <span v-if="groupOf(s.group_id)" class="flex items-center gap-1">
                  <span class="h-2 w-2 rounded-full" :class="signupColor(groupOf(s.group_id)!.color).swatch" />
                  {{ groupOf(s.group_id)!.name }}
                </span>
                <span><span class="font-semibold tabular-nums text-foreground">{{ attendingCount(s) }}</span> going</span>
                <span v-if="s.fees.length">{{ formatMoney(feeTotal(s.fees)) }} per head</span>
              </div>
            </button>
            <button
              v-if="manage"
              type="button"
              class="absolute bottom-2 right-2 grid h-7 w-7 place-items-center rounded-md border bg-background text-muted-foreground opacity-0 transition hover:text-foreground focus-visible:opacity-100 group-hover:opacity-100 max-sm:opacity-100"
              title="Copy sheet"
              :aria-label="`Copy ${s.title}`"
              @click="copyingSheet = s"
            >
              <Copy class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </section>

      <p v-if="!byMonth.length" class="rounded-xl border border-dashed px-3 py-10 text-center text-sm text-muted-foreground">
        No sign-up sheets here in {{ year }}.
        <template v-if="manage">Make one with <span class="font-medium text-foreground">New sheet</span>, or set up a recurring <span class="font-medium text-foreground">schedule</span>.</template>
      </p>
    </div>
  </div>
</template>
