<script setup lang="ts">
import { ChevronLeft } from 'lucide-vue-next'
import type { ChannelMember, TrackerProject, TrackerTask } from '~/types'

/**
 * The Tracker — projects, their tasks, and one task open.
 *
 * Three screens with one way back, which is the shape the app is built around: home → a
 * project's board → a task. The Back button is always the previous screen rather than browser
 * history, because the tracker can be a channel *or* a Side Desk tab *or* a floating window,
 * and only one of those has a URL to go back through.
 *
 * Surface-agnostic like every other app here: the host hands a REST base path and the private
 * stream, so this drives a tracker app channel and a Side Desk tab identically. `canEdit` gates
 * authoring.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  /** The channel whose members fill the assignee picker. */
  channelId: number
}>()

const {
  projects, tasks, tags, loaded,
  open: openTracker,
  addProject, patchProject, removeProject,
  addTask, patchTask, removeTask,
  addTag,
} = useTracker(props.basePath, props.streamName)

openTracker()

/**
 * Where we are, as two ids rather than a route.
 *
 * Null project is the home; a project with no task is its board; both set is a task open. One
 * piece of state for three screens keeps "Back" a single subtraction — clear the task, then
 * clear the project — instead of a stack to maintain.
 */
const openProjectId = ref<number | null>(null)
const openTaskId = ref<number | null>(null)

const project = computed(() => projects.value.find((p: TrackerProject) => p.id === openProjectId.value) ?? null)

/** The board's tasks — also the list the task pager steps through, so the two can't disagree. */
const projectTasks = computed(() => tasks.value
  .filter((t: TrackerTask) => t.project_id === openProjectId.value)
  .sort((a: TrackerTask, b: TrackerTask) => a.position - b.position || a.id - b.id))

const openTask = computed(() => tasks.value.find((t: TrackerTask) => t.id === openTaskId.value) ?? null)

const taskIndex = computed(() => projectTasks.value.findIndex((t: TrackerTask) => t.id === openTaskId.value))

// The detail's own load — comments and history, which the board listing deliberately omits.
const { task: detail, loading: detailLoading, addComment, editComment, removeComment } = useTrackerTask(
  props.basePath, props.streamName, openTaskId,
)

// The assignee picker's options. Members are the channel's, so a task can't be assigned to
// somebody who couldn't open it.
const members = ref<ChannelMember[]>([])
const api = useApi()

onMounted(async () => {
  try {
    const res = await api<{ data: ChannelMember[] }>(`/api/channels/${props.channelId}/members`)
    members.value = res.data
  }
  catch {
    // A failed member list costs the assignee dropdown its options and nothing else, so the
    // tracker still opens. Better than an error screen over a working board.
    members.value = []
  }
})

function back() {
  if (openTaskId.value !== null) openTaskId.value = null
  else openProjectId.value = null
}

const title = computed(() => {
  if (openTask.value) return openTask.value.key
  if (project.value) return project.value.name
  return 'Tracker'
})

// --- renaming the open project ----------------------------------------------------------

const projectNameDraft = ref('')

// Follows whichever project is open, and follows a rename arriving from somebody else —
// except while you're typing in the box, where overwriting your edit is the worse failure.
watch(project, (p) => {
  if (p && document.activeElement?.tagName !== 'INPUT') projectNameDraft.value = p.name
}, { immediate: true })

function commitProjectName() {
  const p = project.value
  if (!p) return
  const next = projectNameDraft.value.trim()
  if (!next || next === p.name) {
    projectNameDraft.value = p.name
    return
  }
  void patchProject(p.id, { name: next })
}

function openProject(p: TrackerProject) {
  openProjectId.value = p.id
  openTaskId.value = null
}

function openTaskRow(t: TrackerTask) {
  // Opening a task from the home screen may cross into another project — the pager and the
  // Back button both read `openProjectId`, so it has to follow.
  openProjectId.value = t.project_id
  openTaskId.value = t.id
}

/** ↑/↓ in the detail pane, bounded so the ends simply don't move. */
function step(by: number) {
  const next = projectTasks.value[taskIndex.value + by]
  if (next) openTaskId.value = next.id
}

async function onAddTask(input: { status: string, title: string }) {
  if (!openProjectId.value) return
  await addTask({ project_id: openProjectId.value, title: input.title, status: input.status as any })
}

async function onRemoveTask() {
  const id = openTaskId.value
  if (id == null) return
  // Back to the board first: deleting the task the pane is pointed at would otherwise leave it
  // rendering a task that no longer exists for as long as the request takes.
  openTaskId.value = null
  await removeTask(id)
}

async function onRemoveProject(p: TrackerProject) {
  // eslint-disable-next-line no-alert
  const ok = window.confirm(
    `Delete “${p.name}”?\n\nThis permanently deletes the project, its tasks, and their comments and history. This cannot be undone.`,
  )
  if (!ok) return
  if (openProjectId.value === p.id) {
    openProjectId.value = null
    openTaskId.value = null
  }
  await removeProject(p.id)
}

/** Create a tag the channel doesn't have yet, then put it on the open task. */
async function onCreateTag(label: string) {
  const task = openTask.value
  if (!task) return
  const tag = await addTag(label)
  const existing = (task.tags ?? []).map(t => t.id)
  if (!existing.includes(tag.id)) await patchTask(task.id, { tag_ids: [...existing, tag.id] })
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- The one header for all three screens. Back appears exactly when there's somewhere to
         go, so the home screen has no dead button on it. -->
    <header class="flex h-12 shrink-0 items-center gap-2 border-b px-2 sm:px-3">
      <button
        v-if="openProjectId !== null"
        type="button"
        class="flex items-center gap-1 rounded-md border px-2 py-1.5 text-sm transition-colors hover:bg-muted"
        @click="back"
      >
        <ChevronLeft class="h-4 w-4" /> Back
      </button>
      <!--
        The project's name is editable in place, on the board where you're looking at it.

        An input rather than a dialog behind a menu, on the same reasoning as the task title:
        renaming is a correction, and a correction shouldn't cost two clicks and a modal. Saves
        on blur and on Enter, reverts on Escape and on an empty value — a project with no name
        is not a rename, it's a mistake.
      -->
      <input
        v-if="project && !openTask && canEdit"
        v-model="projectNameDraft"
        class="min-w-0 flex-1 truncate rounded border border-transparent bg-transparent px-1 py-0.5 font-semibold outline-none transition-colors hover:border-border focus:border-primary"
        :title="`Rename ${project.name}`"
        @blur="commitProjectName"
        @keydown.enter="($event.target as HTMLInputElement).blur()"
        @keydown.esc="projectNameDraft = project!.name; ($event.target as HTMLInputElement).blur()"
      >
      <p v-else class="truncate font-semibold">{{ title }}</p>
      <span class="flex-1" />
    </header>

    <!-- The project's description sits under the header on the board, as a subtitle to the
         name above it. -->
    <p
      v-if="project && !openTask && project.description"
      class="shrink-0 px-3 py-2 text-xs text-muted-foreground"
    >
      {{ project.description }}
    </p>

    <div v-if="!loaded" class="grid flex-1 place-items-center text-sm text-muted-foreground">
      Loading…
    </div>

    <TrackerTaskDetail
      v-else-if="openTask"
      :task="openTask"
      :tags="tags"
      :members="members"
      :can-edit="canEdit"
      :index="taskIndex"
      :total="projectTasks.length"
      :comments="detail?.comments ?? []"
      :activity="detail?.activity ?? []"
      :loading="detailLoading"
      @patch="patchTask(openTask.id, $event)"
      @comment="addComment"
      @edit-comment="editComment($event.id, $event.body)"
      @create-tag="onCreateTag"
      @remove-comment="removeComment"
      @remove="onRemoveTask"
      @step="step"
    />

    <TrackerBoard
      v-else-if="project"
      :project="project"
      :tasks="projectTasks"
      :tags="tags"
      :can-edit="canEdit"
      @open="openTaskRow"
      @add="onAddTask"
    />

    <TrackerHome
      v-else
      :projects="projects"
      :tasks="tasks"
      :can-edit="canEdit"
      @open="openTaskRow"
      @open-project="openProject"
      @add-project="addProject($event)"
      @remove-project="onRemoveProject"
    />
  </div>
</template>
