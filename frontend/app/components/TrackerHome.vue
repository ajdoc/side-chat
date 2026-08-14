<script setup lang="ts">
import { ChevronRight, FolderKanban, MoreVertical, Plus } from 'lucide-vue-next'
import type { TrackerProject, TrackerTask } from '~/types'
import { Input } from '~/components/ui/input'

/**
 * The tracker's front page: what's on *your* plate, and what projects exist.
 *
 * Your tasks lead, because the question somebody opens a tracker with is almost always "what
 * am I meant to be doing" rather than "what projects are there". Finished tasks are left out —
 * this is a to-do list, not a record — and so are tasks in the backlog, which by definition
 * aren't being asked of you yet.
 */
const props = defineProps<{
  projects: TrackerProject[]
  tasks: TrackerTask[]
  canEdit: boolean
}>()

const emit = defineEmits<{
  open: [TrackerTask]
  'open-project': [TrackerProject]
  'add-project': [{ name: string, key: string }]
  'remove-project': [TrackerProject]
}>()

const { user } = useAuth()

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 18) return 'Good afternoon'
  return 'Good evening'
})

const today = computed(() =>
  new Date().toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }))

const myTasks = computed(() => props.tasks
  .filter(t => t.assignee?.id === user.value?.id && t.status !== 'done' && t.status !== 'backlog')
  // Urgent first, then by how soon it's due — the two things that decide what you do next.
  .sort((a, b) => {
    const rank = { urgent: 0, high: 1, mid: 2, low: 3 } as const
    const byPriority = rank[a.priority] - rank[b.priority]
    if (byPriority !== 0) return byPriority
    return (a.due_date ?? '9999').localeCompare(b.due_date ?? '9999')
  }))

const activeProjects = computed(() => props.projects.filter(p => !p.archived))

/** Done over total, as a percentage. Zero tasks is 0%, not a division by zero. */
function progress(p: TrackerProject) {
  const total = p.task_count ?? 0
  const done = p.done_count ?? 0
  return { total, done, pct: total === 0 ? 0 : Math.round((done / total) * 100) }
}

function updated(p: TrackerProject) {
  const days = Math.floor((Date.now() - new Date(p.updated_at).getTime()) / 86400000)
  if (days <= 0) return 'Updated today'
  if (days === 1) return 'Updated yesterday'
  if (days < 30) return `Updated ${days} days ago`
  return `Updated ${new Date(p.updated_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`
}

// --- creating a project ---------------------------------------------------------------

const creating = ref(false)
const name = ref('')
const key = ref('')
/** True once the key has been typed into, so the suggestion stops overwriting a real choice. */
const keyTouched = ref(false)

/**
 * Suggest a key from the name as it's typed: "HRIPS Yuck" → HRIP.
 *
 * The first word truncated, not the initials — the same rule the server uses in
 * `TrackerProject::suggestKey`, and duplicated here rather than fetched because the field has
 * to fill in as you type. The server's copy is the one that matters; this only has to agree.
 */
watch(name, (v) => {
  if (keyTouched.value) return
  const first = v.split(/[^A-Za-z0-9]+/).find(w => /^[A-Za-z]/.test(w))
  key.value = first ? first.slice(0, 4).toUpperCase() : ''
})

function submit() {
  if (!name.value.trim() || !key.value.trim()) return
  emit('add-project', { name: name.value.trim(), key: key.value.trim().toUpperCase() })
  name.value = ''
  key.value = ''
  keyTouched.value = false
  creating.value = false
}

/** Which project's ⋯ menu is open. */
const menuFor = ref<number | null>(null)
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto">
    <div class="mx-auto max-w-5xl space-y-8 p-4 sm:p-6">
      <header>
        <h1 class="text-2xl font-bold">{{ greeting }}, {{ user?.name ?? 'there' }}</h1>
        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ today }}</p>
      </header>

      <section class="space-y-2">
        <h2 class="text-sm font-semibold">Your tasks</h2>
        <div v-if="myTasks.length" class="overflow-hidden rounded-lg">
          <TrackerTaskRow
            v-for="task in myTasks"
            :key="task.id"
            :task="task"
            show-status
            class="bg-muted/30"
            @open="emit('open', $event)"
          />
        </div>
        <p v-else class="rounded-lg bg-muted/30 px-3 py-6 text-center text-sm text-muted-foreground">
          Nothing assigned to you.
        </p>
      </section>

      <section class="space-y-3">
        <div class="flex items-center gap-2">
          <h2 class="text-sm font-semibold">Your projects</h2>
          <span class="text-xs text-muted-foreground">{{ activeProjects.length }} active</span>
          <span class="flex-1" />
          <button
            v-if="canEdit"
            type="button"
            class="flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs transition-colors hover:bg-muted"
            @click="creating = !creating"
          >
            <Plus class="h-3.5 w-3.5" /> New project
          </button>
        </div>

        <form v-if="creating" class="flex flex-wrap items-end gap-2 rounded-lg border p-3" @submit.prevent="submit">
          <div class="min-w-[12rem] flex-1 space-y-1">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Name</label>
            <Input v-model="name" placeholder="Website Redesign" class="h-8 text-sm" autofocus />
          </div>
          <div class="w-24 space-y-1">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Key</label>
            <Input
              v-model="key"
              placeholder="WEBS"
              maxlength="10"
              class="h-8 font-mono text-sm uppercase"
              @input="keyTouched = true"
            />
          </div>
          <button
            type="submit"
            class="h-8 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
            :disabled="!name.trim() || !key.trim()"
          >
            Create
          </button>
          <button type="button" class="h-8 rounded-md px-3 text-sm text-muted-foreground" @click="creating = false">
            Cancel
          </button>
        </form>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div
            v-for="p in activeProjects"
            :key="p.id"
            class="group relative cursor-pointer rounded-lg border p-3 transition-colors hover:border-primary/50 hover:bg-muted/30"
            @click="emit('open-project', p)"
          >
            <div class="flex items-start gap-2.5">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/15 text-primary">
                <FolderKanban class="h-4 w-4" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="truncate font-semibold leading-tight">{{ p.name }}</p>
                <p class="text-[11px] text-muted-foreground">{{ updated(p) }}</p>
              </div>
              <button
                v-if="canEdit"
                type="button"
                class="grid h-6 w-6 shrink-0 place-items-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted group-hover:opacity-100"
                title="Project actions"
                @click.stop="menuFor = menuFor === p.id ? null : p.id"
              >
                <MoreVertical class="h-3.5 w-3.5" />
              </button>

              <div
                v-if="menuFor === p.id"
                class="absolute right-2 top-10 z-20 w-44 rounded-lg border bg-background p-1 shadow-lg"
                @click.stop
              >
                <button
                  type="button"
                  class="w-full rounded px-2 py-1.5 text-left text-xs text-red-500 transition-colors hover:bg-red-500/10"
                  @click="menuFor = null; emit('remove-project', p)"
                >
                  Delete project
                </button>
              </div>
            </div>

            <div class="mt-3 space-y-1">
              <p class="text-[11px] font-medium">
                {{ progress(p).done }} / {{ progress(p).total }} · {{ progress(p).pct }}%
              </p>
              <!-- A two-tone bar: filled for done, and the remainder tinted rather than empty,
                   so a project with nothing finished still reads as having work in it. -->
              <div class="flex h-1.5 overflow-hidden rounded-full bg-muted">
                <div class="bg-primary transition-all" :style="{ width: `${progress(p).pct}%` }" />
                <div class="flex-1 bg-amber-500/60" />
              </div>
              <p v-if="p.description" class="line-clamp-2 pt-1 text-[11px] text-muted-foreground">
                {{ p.description }}
              </p>
            </div>

            <ChevronRight class="absolute bottom-3 right-3 h-3.5 w-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
          </div>
        </div>

        <p v-if="!activeProjects.length && !creating" class="rounded-lg border border-dashed px-3 py-8 text-center text-sm text-muted-foreground">
          No projects yet.
        </p>
      </section>
    </div>
  </div>
</template>
