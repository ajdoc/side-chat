<script setup lang="ts">
import { ChevronLeft, Download, Dumbbell, FileUp, Plus, Settings2 } from 'lucide-vue-next'
import { Input } from '~/components/ui/input'
import type { TrainingContent, TrainingTemplate } from '~/types'
import { currentWeek, dateFor, parseDate, programStatus, shortDate } from '~/lib/training'
import { describeImportErrors, extractProgram, programFileName } from '~/lib/trainingImport'

/**
 * The Training app — multi-week programs a channel follows together. Staff start one from a
 * template; everyone ticks off their own blocks and sees the team's progress.
 *
 * Screens rather than routes, like Sign-ups: this can be a channel, a desk tab or a floating
 * window, and only one of those has a URL.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
}>()

const training = useTraining(props.basePath, props.streamName)
const { programs, templates, canManage, loaded, current } = training

training.open()

const manage = computed(() => canManage.value && props.canEdit)

type Screen = 'list' | 'program' | 'new' | 'settings'
const screen = ref<Screen>('list')
const tab = ref<'plan' | 'team'>('plan')
const openId = ref<number | null>(null)
const error = ref('')

function message(e: any) {
  return e?.data?.message ?? 'Something went wrong. Try again.'
}

/** A 422 on a program document names each problem; everything else is one line. */
const problems = ref<string[]>([])

function fail(e: any) {
  problems.value = describeImportErrors(e?.data?.errors)
  error.value = problems.value.length ? 'The program has problems:' : message(e)
}

function clearErrors() {
  error.value = ''
  problems.value = []
}

/** Read a picked file into a program document, or say why not. */
async function readFile(event: Event): Promise<TrainingContent | null> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return null
  clearErrors()
  if (file.size > 2_000_000) {
    error.value = 'That file is too big to be a program.'
    return null
  }
  try {
    return extractProgram(await file.text(), file.name)
  }
  catch (e: any) {
    error.value = e?.message ?? 'Couldn’t read that file.'
    return null
  }
}

async function openProgram(id: number) {
  error.value = ''
  openId.value = id
  tab.value = 'plan'
  screen.value = 'program'
  try {
    await training.openProgram(id)
  }
  catch (e) {
    error.value = message(e)
  }
}

function back() {
  clearErrors()
  if (screen.value === 'settings') {
    screen.value = 'program'
    return
  }
  openId.value = null
  screen.value = 'list'
  training.closeProgram()
}

// Most channels follow one program: go straight into it, once.
let autoOpened = false
watch(loaded, (ready) => {
  if (!ready || autoOpened) return
  autoOpened = true
  if (programs.value.length === 1 && screen.value === 'list') void openProgram(programs.value[0]!.id)
}, { immediate: true })

// The open program was deleted, here or elsewhere.
watch(current, (p) => {
  if (p === null && openId.value !== null && !programs.value.some(x => x.id === openId.value)) {
    openId.value = null
    screen.value = 'list'
  }
})

function statusLabel(startsOn: string, weeks: number) {
  const status = programStatus(startsOn, weeks)
  if (status === 'upcoming') return `Starts ${shortDate(parseDate(startsOn))}`
  if (status === 'finished') return 'Finished'
  return `Week ${currentWeek(startsOn, weeks)} of ${weeks}`
}

function endDate(startsOn: string, weeks: number) {
  return shortDate(dateFor(startsOn, weeks, 5))
}

// --- new program ----------------------------------------------------------------------------

/** This week's Monday, as `YYYY-MM-DD` in local time. */
function thisMonday() {
  const d = new Date()
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const draft = reactive({ source: 'template' as 'template' | 'file', template: '', title: '', starts_on: '' })
/** The document read from a picked file, when starting from one. */
const imported = shallowRef<TrainingContent | null>(null)
const importedName = ref('')
const saving = ref(false)

function pickTemplate(t: TrainingTemplate) {
  draft.source = 'template'
  draft.template = t.id
  draft.title = t.title
  // A template's own start date only makes sense while it's still ahead or running.
  draft.starts_on = t.default_start && programStatus(t.default_start, t.weeks) !== 'finished' ? t.default_start : thisMonday()
}

async function pickFile(event: Event) {
  const name = (event.target as HTMLInputElement).files?.[0]?.name ?? ''
  const content = await readFile(event)
  if (!content) return
  imported.value = content
  importedName.value = name
  draft.source = 'file'
  draft.title = content.meta?.title ?? name.replace(/\.(html?|json)$/i, '')
  const start = content.meta?.default_start
  draft.starts_on = start && /^\d{4}-\d{2}-\d{2}$/.test(start) && programStatus(start, content.weeks ?? 1) !== 'finished' ? start : thisMonday()
}

const importedSummary = computed(() => {
  const c = imported.value
  if (!c) return ''
  const sessions = c.sessions && typeof c.sessions === 'object' ? Object.keys(c.sessions).length : 0
  return `${c.weeks ?? '?'} weeks, ${c.days?.length ?? 0} days a week, ${sessions} sessions`
})

function startNew() {
  clearErrors()
  imported.value = null
  importedName.value = ''
  const first = templates.value[0]
  if (first) pickTemplate(first)
  screen.value = 'new'
}

const canCreate = computed(() => (draft.source === 'template' ? !!draft.template : !!imported.value))

async function create() {
  if (!canCreate.value) return
  saving.value = true
  clearErrors()
  try {
    const source = draft.source === 'template' ? { template: draft.template } : { content: imported.value! }
    const p = await training.createProgram({ ...source, title: draft.title.trim() || null, starts_on: draft.starts_on || null })
    openId.value = p.id
    tab.value = 'plan'
    screen.value = 'program'
  }
  catch (e) {
    fail(e)
  }
  finally {
    saving.value = false
  }
}

// --- settings -------------------------------------------------------------------------------

const settings = reactive({ title: '', starts_on: '' })

function openSettings() {
  if (!current.value) return
  settings.title = current.value.title
  settings.starts_on = current.value.starts_on
  error.value = ''
  screen.value = 'settings'
}

async function saveSettings() {
  if (!current.value) return
  saving.value = true
  error.value = ''
  try {
    await training.updateProgram(current.value.id, { title: settings.title.trim(), starts_on: settings.starts_on })
    screen.value = 'program'
  }
  catch (e) {
    error.value = message(e)
  }
  finally {
    saving.value = false
  }
}

// Editing and replacing the document.

async function saveContent(content: TrainingContent) {
  if (!current.value) return
  saving.value = true
  clearErrors()
  try {
    await training.replaceContent(current.value.id, content)
    screen.value = 'program'
  }
  catch (e) {
    fail(e)
  }
  finally {
    saving.value = false
  }
}

/** Parsed from a picked file, waiting on the confirm dialog. */
const replacement = shallowRef<TrainingContent | null>(null)

async function pickReplacement(event: Event) {
  replacement.value = await readFile(event)
}

async function confirmReplace() {
  const content = replacement.value
  replacement.value = null
  if (content) await saveContent(content)
}

function download() {
  if (!current.value) return
  const doc = { ...current.value.content, meta: { ...current.value.content.meta, title: current.value.title } }
  const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = programFileName(current.value.title)
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 0)
}

const deleting = ref(false)
const deleteBusy = ref(false)
const deleteError = ref('')

async function confirmDelete() {
  if (!current.value) return
  deleteBusy.value = true
  deleteError.value = ''
  try {
    await training.deleteProgram(current.value.id)
    deleting.value = false
    back()
  }
  catch (e) {
    deleteError.value = message(e)
  }
  finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <ConfirmDialog
    v-model:open="deleting"
    :title="`Delete ${current?.title ?? 'this program'}?`"
    description="The program and everyone’s ticks on it are deleted. This can’t be undone."
    confirm-label="Delete program"
    busy-label="Deleting…"
    :busy="deleteBusy"
    :error="deleteError"
    @confirm="confirmDelete"
  />

  <ConfirmDialog
    :open="replacement !== null"
    :title="`Replace ${current?.title ?? 'this program'}?`"
    description="Its sessions, schedule and notes are replaced by the file's. Ticks stay on exercises whose ids match the file's. If the file doesn't set ids, ticks line up by position within each day."
    confirm-label="Replace program"
    busy-label="Replacing…"
    :busy="saving"
    variant="default"
    @update:open="(v: boolean) => { if (!v) replacement = null }"
    @confirm="confirmReplace"
  />

  <div class="flex min-h-0 flex-1 flex-col">
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-3">
      <button
        v-if="screen !== 'list' && (screen !== 'program' || programs.length > 1 || manage)"
        type="button"
        class="flex items-center gap-1 rounded-md border px-2 py-1.5 text-sm transition-colors hover:bg-muted"
        @click="back"
      >
        <ChevronLeft class="h-4 w-4" /> {{ screen === 'settings' ? 'Back' : 'Programs' }}
      </button>

      <p v-if="screen === 'list'" class="font-semibold">Training</p>
      <p v-else-if="screen === 'new'" class="font-semibold">New program</p>
      <p v-else-if="screen === 'settings'" class="font-semibold">Program settings</p>
      <template v-else-if="screen === 'program'">
        <p class="min-w-0 truncate font-semibold">{{ current?.title ?? '' }}</p>
        <nav class="ml-1 flex shrink-0 gap-1">
          <button
            v-for="t in (['plan', 'team'] as const)"
            :key="t"
            type="button"
            class="rounded-md px-2.5 py-1 text-sm capitalize transition-colors"
            :class="tab === t ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'"
            @click="tab = t"
          >
            {{ t }}
          </button>
        </nav>
        <button
          v-if="manage && current"
          type="button"
          class="shrink-0 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          title="Program settings"
          @click="openSettings"
        >
          <Settings2 class="h-4 w-4" />
        </button>
      </template>
    </header>

    <div v-if="!loaded" class="grid flex-1 place-items-center text-sm text-muted-foreground">Loading…</div>

    <!-- The list of programs. -->
    <div v-else-if="screen === 'list'" class="min-h-0 flex-1 overflow-y-auto p-4">
      <div class="mx-auto max-w-2xl">
        <p v-if="error" class="mb-3 text-sm text-destructive">{{ error }}</p>

        <div v-if="!programs.length" class="mt-10 flex flex-col items-center text-center">
          <Dumbbell class="h-10 w-10 text-muted-foreground" />
          <p class="mt-3 font-semibold">No training programs yet</p>
          <p class="mt-1 max-w-sm text-sm text-muted-foreground">
            {{ manage
              ? 'Start one from a template. Everyone in the channel can then follow it and tick off their sessions.'
              : 'When staff start a program, it shows up here for everyone to follow.' }}
          </p>
          <button
            v-if="manage"
            type="button"
            class="mt-4 flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
            @click="startNew"
          >
            <Plus class="h-4 w-4" /> New program
          </button>
        </div>

        <template v-else>
          <button
            v-for="p in programs"
            :key="p.id"
            type="button"
            class="mb-2 flex w-full items-center gap-3 rounded-lg border bg-card p-3 text-left transition-colors hover:bg-muted"
            @click="openProgram(p.id)"
          >
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-md bg-emerald-800 text-white"><Dumbbell class="h-5 w-5" /></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-medium">{{ p.title }}</span>
              <span class="block text-sm text-muted-foreground">
                {{ p.weeks }} weeks, {{ shortDate(parseDate(p.starts_on)) }} to {{ endDate(p.starts_on, p.weeks) }}
              </span>
            </span>
            <span class="shrink-0 text-sm text-muted-foreground">{{ statusLabel(p.starts_on, p.weeks) }}</span>
          </button>
          <button
            v-if="manage"
            type="button"
            class="mt-2 flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm hover:bg-muted"
            @click="startNew"
          >
            <Plus class="h-4 w-4" /> New program
          </button>
        </template>
      </div>
    </div>

    <!-- New program from a template. -->
    <div v-else-if="screen === 'new'" class="min-h-0 flex-1 overflow-y-auto p-4">
      <form class="mx-auto max-w-xl space-y-5" @submit.prevent="create">
        <fieldset>
          <legend class="text-sm font-medium">Start from</legend>
          <label
            v-for="t in templates"
            :key="t.id"
            class="mt-2 flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
            :class="draft.template === t.id ? 'border-emerald-600 bg-emerald-500/10' : 'hover:bg-muted'"
          >
            <input type="radio" name="template" class="mt-1 accent-emerald-700" :checked="draft.template === t.id" @change="pickTemplate(t)">
            <span>
              <span class="block font-medium">{{ t.title }}</span>
              <span class="block text-sm text-muted-foreground">{{ t.description }}</span>
              <span class="mt-1 block text-xs text-muted-foreground">{{ t.weeks }} weeks</span>
            </span>
          </label>
          <label
            class="mt-2 flex cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
            :class="draft.source === 'file' ? 'border-emerald-600 bg-emerald-500/10' : 'hover:bg-muted'"
          >
            <FileUp class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <span class="min-w-0">
              <span class="block font-medium">{{ imported ? importedName : 'Import a file' }}</span>
              <span class="block text-sm text-muted-foreground">
                {{ imported ? importedSummary : 'An HTML page with a training-program data block, or a program .json file.' }}
              </span>
              <span v-if="imported" class="mt-1 block text-xs text-muted-foreground underline">Choose a different file</span>
            </span>
            <input type="file" accept=".html,.htm,.json,text/html,application/json" class="sr-only" @change="pickFile">
          </label>
        </fieldset>

        <label class="block">
          <span class="text-sm font-medium">Name</span>
          <Input v-model="draft.title" class="mt-1" maxlength="120" />
        </label>

        <label class="block">
          <span class="text-sm font-medium">Starts</span>
          <Input v-model="draft.starts_on" type="date" class="mt-1 w-48" />
          <span class="mt-1 block text-xs text-muted-foreground">Week 1 starts on the Monday of the week you pick.</span>
        </label>

        <div v-if="error" class="text-sm text-destructive">
          <p>{{ error }}</p>
          <ul v-if="problems.length" class="mt-1 list-disc pl-5"><li v-for="p in problems" :key="p">{{ p }}</li></ul>
        </div>

        <div class="flex gap-2">
          <button type="submit" :disabled="saving || !canCreate" class="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
            {{ saving ? 'Starting…' : 'Start program' }}
          </button>
          <button type="button" class="rounded-md border px-3 py-2 text-sm hover:bg-muted" @click="back">Cancel</button>
        </div>
      </form>
    </div>

    <!-- Settings: rename, reschedule, delete. -->
    <div v-else-if="screen === 'settings' && current" class="min-h-0 flex-1 overflow-y-auto p-4">
      <form class="mx-auto max-w-xl space-y-5" @submit.prevent="saveSettings">
        <label class="block">
          <span class="text-sm font-medium">Name</span>
          <Input v-model="settings.title" class="mt-1" maxlength="120" required />
        </label>
        <label class="block">
          <span class="text-sm font-medium">Starts</span>
          <Input v-model="settings.starts_on" type="date" class="mt-1 w-48" required />
          <span class="mt-1 block text-xs text-muted-foreground">Moving the start keeps everyone’s ticks; they stay on the same week and day.</span>
        </label>

        <div v-if="error" class="text-sm text-destructive">
          <p>{{ error }}</p>
          <ul v-if="problems.length" class="mt-1 list-disc pl-5"><li v-for="p in problems" :key="p">{{ p }}</li></ul>
        </div>

        <div class="flex gap-2">
          <button type="submit" :disabled="saving" class="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
            {{ saving ? 'Saving…' : 'Save' }}
          </button>
          <button type="button" class="rounded-md border px-3 py-2 text-sm hover:bg-muted" @click="back">Cancel</button>
        </div>

        <div class="border-t pt-5">
          <p class="text-sm font-medium">The program itself</p>
          <p class="mt-0.5 text-xs text-muted-foreground">To change weeks, days, sessions or exercises, use <b>Edit program</b> on the program page.</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <label class="flex cursor-pointer items-center gap-1.5 rounded-md border px-3 py-2 text-sm hover:bg-muted">
              <FileUp class="h-4 w-4" /> Replace from file
              <input type="file" accept=".html,.htm,.json,text/html,application/json" class="sr-only" @change="pickReplacement">
            </label>
            <button type="button" class="flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm hover:bg-muted" @click="download">
              <Download class="h-4 w-4" /> Download .json
            </button>
          </div>
        </div>

        <div class="border-t pt-5">
          <button type="button" class="rounded-md border border-destructive/50 px-3 py-2 text-sm text-destructive hover:bg-destructive/10" @click="deleting = true">
            Delete program
          </button>
        </div>
      </form>
    </div>

    <!-- A program. -->
    <div v-else-if="screen === 'program'" class="min-h-0 flex-1 overflow-y-auto">
      <p v-if="error" class="p-4 text-sm text-destructive">{{ error }}</p>
      <template v-else-if="current">
        <TrainingProgramView v-if="tab === 'plan'" :key="current.id" :program="current" :training="training" :can-edit="canEdit" :can-manage="manage" />
        <TrainingTeamView v-else :key="`team-${current.id}`" :program="current" :training="training" />
      </template>
      <div v-else class="grid h-full place-items-center text-sm text-muted-foreground">Loading…</div>
    </div>
  </div>
</template>
