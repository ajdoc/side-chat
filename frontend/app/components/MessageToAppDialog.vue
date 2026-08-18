<script setup lang="ts">
import { Check, ChevronLeft, Loader2 } from 'lucide-vue-next'
import type { KanbanBoard, Message, SideDeskAppId, TrackerProject } from '~/types'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'
import { deskApp } from '~/composables/useDeskApps'

/**
 * "Add this message to an app" — a card on a board, a task, a poll, a line in the notes.
 *
 * ## Two questions, in the order people answer them
 *
 * **Which app**, then **where** — because "make this a task" is the thought somebody has, and
 * "in the roadmap channel rather than this one" is the refinement. Reversing it would ask you to
 * pick a destination for a thing you haven't decided on yet.
 *
 * "Where" is always a channel: *this* chat (its Side Desk tab, its widget, its canvas — all one
 * channel's storage) or an app channel running that app. The server takes one `target_channel_id`
 * for both, because that is all either of them ever was.
 *
 * ## The preview is the server's
 *
 * A poll's question and options are parsed out of the message's markdown list, and what's shown
 * here is what the server parsed, not a second implementation of the same rule. The dialog can't
 * promise something the create path then disagrees with.
 *
 * ## The extras
 *
 * Only the ones a message genuinely can't answer: which project a task belongs under, which
 * column a card lands in, when a calendar entry starts. Everything else is read from the text.
 */
const props = defineProps<{ message: Message }>()

const open = defineModel<boolean>('open', { default: false })

const api = useApi()

interface TargetChannel {
  id: number
  name: string
  /** 'text' | 'voice' | 'space' | 'app' — what kind of room it is, for the label. */
  type: string
  /** The server it's in, or the group chat it belongs to. */
  where?: string | null
}

interface Targets {
  apps: SideDeskAppId[]
  unsupported: Record<string, string>
  here: TargetChannel | null
  app_channels: Record<string, TargetChannel[]>
  channels: TargetChannel[]
  /** True for a message whose stored body is ciphertext — nothing here can take it. */
  encrypted?: boolean
  preview: {
    title: string
    body: string
    poll: { question: string, options: string[] }
    files: number
  } | null
}

const targets = ref<Targets | null>(null)
const loading = ref(false)
const error = ref('')
const done = ref('')

const app = ref<SideDeskAppId | null>(null)
const targetId = ref<number | null>(null)
const busy = ref(false)

/** Per-app extras, fetched only once a target is chosen — they belong to *that* channel. */
const projects = ref<TrackerProject[]>([])
const columns = ref<{ key: string, label: string }[]>([])
const trackerAs = ref<'task' | 'project'>('task')
const projectId = ref<number | null>(null)
const column = ref<string | null>(null)
const startsAt = ref('')

const appList = computed(() =>
  (targets.value?.apps ?? []).map(id => ({ id, meta: deskApp(id) })).filter(a => a.meta))

/**
 * Where this can go, in three groups.
 *
 * **Any** channel is a target, not just app channels: a text channel, a DM, a voice room and a
 * Side Space all carry a Side Desk, and their board is the same storage an app channel's is.
 * The groups exist because they answer different questions — "here", "the channel that *is* this
 * app", "some other room" — and not because the server treats them differently. It doesn't.
 */
const groups = computed(() => {
  const t = targets.value
  if (!t || !app.value) return []

  return [
    { label: 'This chat', rows: t.here ? [t.here] : [] },
    { label: 'App channels', rows: t.app_channels[app.value] ?? [] },
    { label: 'Other channels', rows: t.channels ?? [] },
  ].filter(g => g.rows.length)
})

/** A room's kind, spelled out — a Side Space and a text channel are very different places. */
const KIND_LABELS: Record<string, string> = {
  voice: 'voice', space: 'Side Space', app: 'app channel', text: '',
}

function sublabel(row: TargetChannel) {
  return [KIND_LABELS[row.type] ?? row.type, row.where].filter(Boolean).join(' · ')
}

const preview = computed(() => targets.value?.preview)

/** What this message will look like as the chosen app's item — the sentence under the picker. */
const shape = computed(() => {
  const p = preview.value
  if (!p || !app.value) return ''
  switch (app.value) {
    case 'polls': return p.poll.options.length
      ? `A poll: “${p.poll.question}” with ${p.poll.options.length} options.`
      : `A yes/no poll: “${p.poll.question || p.title}”.`
    case 'tracker': return trackerAs.value === 'project'
      ? `A project called “${p.title}”.`
      : `A task called “${p.title}”.`
    case 'calendar': return `An entry called “${p.title}”.`
    case 'docs': return p.files ? `${p.files} file${p.files === 1 ? '' : 's'} from this message.` : 'This message has no files on it.'
    case 'notes': return 'Appended to the shared note, with a byline.'
    case 'kanban': return 'A card with the message text.'
    case 'canvas': return 'A note card on the canvas.'
    default: return ''
  }
})

async function load() {
  loading.value = true
  error.value = ''
  done.value = ''
  try {
    targets.value = await api<Targets>(`/api/messages/${props.message.id}/app-targets`)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not work out where this could go.'
  }
  finally {
    loading.value = false
  }
}

/**
 * `immediate`, because the parent mounts this with `v-if` — `open` is already true on the first
 * render, so a plain watcher never sees a change and the dialog opens on an empty list. The same
 * reason MessageInfoDialog's fetch is immediate. Keeping the watch as well as the immediate run
 * covers reopening, since the component is torn down and rebuilt each time.
 */
watch(open, (isOpen) => {
  if (!isOpen) return
  app.value = null
  targetId.value = null
  void load()
}, { immediate: true })

/**
 * Choosing an app preselects this chat.
 *
 * It's the answer most of the time — you're filing something said here — and it means the
 * common path is two clicks rather than three. Every other room is one line down in the list.
 */
watch(app, () => {
  targetId.value = targets.value?.here?.id ?? null
})

/** Picking a target is what makes its projects and columns knowable — they're that channel's. */
watch([app, targetId], async () => {
  projects.value = []
  columns.value = []
  projectId.value = null
  column.value = null
  const id = targetId.value

  if (id == null) return

  if (app.value === 'tracker') {
    const res = await api<{ data: TrackerProject[] }>(`/api/channels/${id}/tracker/projects`)
    projects.value = res.data
    projectId.value = res.data[0]?.id ?? null
    // Nowhere to put a task means the only coherent shape is a project.
    if (!projects.value.length) trackerAs.value = 'project'
  }

  if (app.value === 'kanban') {
    const res = await api<{ data: KanbanBoard }>(`/api/channels/${id}/kanban`)
    columns.value = res.data.columns
    column.value = res.data.columns[0]?.key ?? null
  }
})

const ready = computed(() => {
  if (!app.value || targetId.value == null) return false
  if (app.value === 'tracker' && trackerAs.value === 'task') return projectId.value != null
  if (app.value === 'docs') return (preview.value?.files ?? 0) > 0
  return true
})

async function submit() {
  if (!ready.value) return
  busy.value = true
  error.value = ''
  try {
    const options: Record<string, unknown> = {}
    if (app.value === 'tracker') {
      options.as = trackerAs.value
      if (trackerAs.value === 'task') options.project_id = projectId.value
    }
    if (app.value === 'kanban' && column.value) options.column = column.value
    if (app.value === 'calendar' && startsAt.value) options.starts_at = new Date(startsAt.value).toISOString()

    const res = await api<{ message: string }>(`/api/messages/${props.message.id}/app-items`, {
      method: 'POST',
      body: { app: app.value, target_channel_id: targetId.value, options },
    })
    done.value = res.message
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'That didn’t go through.'
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle class="flex items-center gap-2">
          <button
            v-if="app && !done"
            type="button"
            class="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            title="Back to the app list"
            @click="app = null; targetId = null"
          >
            <ChevronLeft class="h-4 w-4" />
          </button>
          Add to app
        </DialogTitle>
        <DialogDescription>
          The message stays where it is — this makes a copy of it in the app you pick.
        </DialogDescription>
      </DialogHeader>

      <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Looking…</p>

      <!-- The fetch failed. Said here rather than under an empty grid, which reads as "there
           are no apps" — a different and much more alarming thing than "that didn't load". -->
      <p v-else-if="error && !app" class="py-6 text-center text-sm text-destructive">{{ error }}</p>

      <!-- Said plainly rather than offering seven apps that would all refuse. -->
      <p v-else-if="targets?.encrypted" class="py-6 text-center text-sm text-muted-foreground">
        This message is encrypted, so it can’t be filed into an app — the apps aren’t encrypted,
        and the server only has the envelope.
      </p>

      <!-- Done. Kept on screen rather than closing, so "which board did that go to" is still
           answerable, and a second filing (the same message often belongs in two places) is
           one click away. -->
      <div v-else-if="done" class="space-y-3 py-2">
        <p class="flex items-center gap-2 text-sm">
          <Check class="h-4 w-4 text-emerald-600 dark:text-emerald-400" /> {{ done }}
        </p>
        <Button size="sm" variant="outline" @click="done = ''; app = null; targetId = null">
          Add somewhere else
        </Button>
      </div>

      <!-- Step one: which app. -->
      <div v-else-if="!app && appList.length" class="grid grid-cols-2 gap-2">
        <button
          v-for="entry in appList"
          :key="entry.id"
          type="button"
          class="flex items-center gap-2 rounded-md border p-2.5 text-sm transition-colors hover:bg-muted"
          @click="app = entry.id"
        >
          <component :is="entry.meta!.icon" class="h-4 w-4 shrink-0 text-primary" />
          <span class="truncate">{{ entry.meta!.label }}</span>
        </button>
      </div>

      <p v-else-if="!app" class="py-6 text-center text-sm text-muted-foreground">
        This version of the app doesn’t know about any of the apps this message could go to.
      </p>

      <!-- Step two: where, and the few things the message can't say for itself. -->
      <div v-else class="space-y-3">
        <p v-if="shape" class="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">{{ shape }}</p>

        <!-- The parsed poll, shown in full: it's the one app whose result is a *structure*
             read out of the text, and seeing it beforehand is what makes that trustworthy. -->
        <ul v-if="app === 'polls' && preview?.poll.options.length" class="space-y-1 text-xs">
          <li v-for="(option, i) in preview.poll.options" :key="i" class="rounded border px-2 py-1">
            {{ option }}
          </li>
        </ul>

        <label class="block space-y-1">
          <span class="text-xs font-medium">Where</span>
          <!-- Grouped rather than one long list: with every visible channel offered, the three
               kinds are what makes the list scannable. `optgroup` rather than a custom menu so
               it stays a native picker on a phone. -->
          <select v-model.number="targetId" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
            <option :value="null" disabled>Pick a channel…</option>
            <optgroup v-for="group in groups" :key="group.label" :label="group.label">
              <option v-for="row in group.rows" :key="row.id" :value="row.id">
                {{ row.name }}<template v-if="sublabel(row)"> · {{ sublabel(row) }}</template>
              </option>
            </optgroup>
          </select>
        </label>

        <template v-if="app === 'tracker' && targetId != null">
          <div class="flex gap-2 text-sm">
            <button
              v-for="mode in (['task', 'project'] as const)"
              :key="mode"
              type="button"
              class="flex-1 rounded-md border px-2 py-1.5 text-xs capitalize transition-colors"
              :class="trackerAs === mode ? 'border-primary bg-primary/10 text-primary' : 'hover:bg-muted'"
              :disabled="mode === 'task' && projects.length === 0"
              @click="trackerAs = mode"
            >
              As a {{ mode }}
            </button>
          </div>

          <label v-if="trackerAs === 'task'" class="block space-y-1">
            <span class="text-xs font-medium">Project</span>
            <select v-model.number="projectId" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
              <option v-for="project in projects" :key="project.id" :value="project.id">
                {{ project.key }} · {{ project.name }}
              </option>
            </select>
            <span v-if="!projects.length" class="text-xs text-muted-foreground">
              That channel has no projects yet — add this as one instead.
            </span>
          </label>
        </template>

        <label v-if="app === 'kanban' && columns.length" class="block space-y-1">
          <span class="text-xs font-medium">Column</span>
          <select v-model="column" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
            <option v-for="col in columns" :key="col.key" :value="col.key">{{ col.label }}</option>
          </select>
        </label>

        <label v-if="app === 'calendar'" class="block space-y-1">
          <span class="text-xs font-medium">Starts</span>
          <input v-model="startsAt" type="datetime-local" class="w-full rounded-md border bg-background px-2 py-1.5 text-sm">
          <!-- No date guessed out of the prose: "Tuesday" doesn't say which one, and on a
               calendar a confident wrong answer is a meeting nobody attends. -->
          <span class="text-xs text-muted-foreground">Left empty, it lands at the time the message was sent.</span>
        </label>

        <div class="flex items-center gap-2">
          <Button size="sm" :disabled="!ready || busy" @click="submit">
            <Loader2 v-if="busy" class="h-3.5 w-3.5 animate-spin" />
            Add
          </Button>
          <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
