<script setup lang="ts">
import { ChevronDown, Filter, Plus, Search, X } from 'lucide-vue-next'
import type { AppTag, TrackerPriority, TrackerProject, TrackerTask } from '~/types'
import { TRACKER_PRIORITIES, TRACKER_STATUSES, tagChip } from '~/lib/tracker'
import { Input } from '~/components/ui/input'

/**
 * A project's tasks, grouped by status.
 *
 * Groups collapse and are drawn in the server's status order, with empty ones hidden unless
 * something is being filtered — an empty "Done" on a fresh project is noise, but an empty group
 * *while filtering* is the answer to the question you asked, so it stays.
 *
 * Filtering happens here rather than over the API. Everything on screen is already held (see
 * useTracker), so a keystroke that re-queried the server would be slower and would flicker; the
 * endpoint's own filters exist for the cases the client can't answer, not for this box.
 */
const props = defineProps<{
  project: TrackerProject
  tasks: TrackerTask[]
  tags: AppTag[]
  canEdit: boolean
}>()

const emit = defineEmits<{
  open: [TrackerTask]
  add: [{ status: string, title: string }]
}>()

const query = ref('')
const filterOpen = ref(false)
const priorityFilter = ref<Set<TrackerPriority>>(new Set())
const tagFilter = ref<Set<number>>(new Set())
const mineOnly = ref(false)

const { user } = useAuth()

/** Which groups are folded away. Collapsed is the exception, so the set holds the closed ones. */
const collapsed = ref<Set<string>>(new Set())

function toggleGroup(id: string) {
  const next = new Set(collapsed.value)
  next.has(id) ? next.delete(id) : next.add(id)
  collapsed.value = next
}

const filtering = computed(() =>
  !!query.value.trim() || priorityFilter.value.size > 0 || tagFilter.value.size > 0 || mineOnly.value)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return props.tasks.filter((t) => {
    // Key as well as title, so pasting "HRIP-2" finds it — that's the reference people copy
    // out of chat, and searching only titles would make the obvious paste fail.
    if (q && !t.title.toLowerCase().includes(q) && !t.key.toLowerCase().includes(q)) return false
    if (priorityFilter.value.size && !priorityFilter.value.has(t.priority)) return false
    if (mineOnly.value && t.assignee?.id !== user.value?.id) return false
    // Tags are OR within the filter: picking "bug" and "design" means either, which is what
    // people expect from a chip filter. AND would return nothing almost every time.
    if (tagFilter.value.size && !(t.tags ?? []).some(g => tagFilter.value.has(g.id))) return false
    return true
  })
})

const groups = computed(() => TRACKER_STATUSES.map(s => ({
  ...s,
  tasks: filtered.value
    .filter(t => t.status === s.id)
    .sort((a, b) => a.position - b.position || a.id - b.id),
})).filter(g => g.tasks.length > 0 || filtering.value || g.id === 'todo'))

function togglePriority(p: TrackerPriority) {
  const next = new Set(priorityFilter.value)
  next.has(p) ? next.delete(p) : next.add(p)
  priorityFilter.value = next
}

function toggleTag(id: number) {
  const next = new Set(tagFilter.value)
  next.has(id) ? next.delete(id) : next.add(id)
  tagFilter.value = next
}

function clearFilters() {
  query.value = ''
  priorityFilter.value = new Set()
  tagFilter.value = new Set()
  mineOnly.value = false
}

// --- adding ---------------------------------------------------------------------------

/**
 * Which group has its inline composer open, if any.
 *
 * Inline and per group rather than one dialog, because the status is then implied by *where*
 * you typed — adding straight into "In Review" takes one click instead of a form field.
 */
const addingIn = ref<string | null>(null)
const newTitle = ref('')

function startAdd(status: string) {
  addingIn.value = status
  newTitle.value = ''
  // The row is only rendered once `addingIn` is set, so focusing has to wait for it to exist.
  nextTick(() => document.getElementById('tracker-add-input')?.focus())
}

function submitAdd() {
  const title = newTitle.value.trim()
  if (!title || !addingIn.value) return
  emit('add', { status: addingIn.value, title })
  // Kept open with the box cleared: adding tasks is something people do several at a time.
  newTitle.value = ''
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Search and filters. Right-aligned above the groups, as in the reference. -->
    <div class="flex shrink-0 items-center justify-end gap-2 px-3 py-2">
      <div class="relative w-full max-w-xs">
        <Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input v-model="query" placeholder="Search tasks..." class="h-8 pl-8 text-sm" />
      </div>

      <div class="relative">
        <button
          type="button"
          class="grid h-8 w-8 place-items-center rounded-md border transition-colors hover:bg-muted"
          :class="(priorityFilter.size || tagFilter.size || mineOnly) && 'border-primary text-primary'"
          title="Filter"
          @click="filterOpen = !filterOpen"
        >
          <Filter class="h-3.5 w-3.5" />
        </button>

        <!-- A plain popover rather than a dropdown-menu: the contents are multi-select
             toggles, and a menu that closes on every click would make picking two filters
             take two trips. -->
        <div
          v-if="filterOpen"
          class="absolute right-0 top-9 z-20 w-56 space-y-3 rounded-lg border bg-background p-3 shadow-lg"
        >
          <div class="space-y-1.5">
            <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Priority</p>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="p in TRACKER_PRIORITIES"
                :key="p.id"
                type="button"
                class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase transition-opacity"
                :class="[p.chip, priorityFilter.has(p.id) ? 'ring-2 ring-primary ring-offset-1 ring-offset-background' : 'opacity-60 hover:opacity-100']"
                @click="togglePriority(p.id)"
              >{{ p.label }}</button>
            </div>
          </div>

          <div v-if="tags.length" class="space-y-1.5">
            <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Tags</p>
            <div class="flex flex-wrap gap-1">
              <button
                v-for="tag in tags"
                :key="tag.id"
                type="button"
                class="rounded-full border px-2 py-0.5 text-[11px] transition-opacity"
                :class="[tagChip(tag.color), tagFilter.has(tag.id) ? 'ring-2 ring-primary ring-offset-1 ring-offset-background' : 'opacity-60 hover:opacity-100']"
                @click="toggleTag(tag.id)"
              >{{ tag.label }}</button>
            </div>
          </div>

          <label class="flex cursor-pointer items-center gap-2 text-sm">
            <input v-model="mineOnly" type="checkbox" class="h-3.5 w-3.5 rounded border-border">
            Assigned to me
          </label>

          <button
            v-if="filtering"
            type="button"
            class="flex w-full items-center justify-center gap-1 rounded border py-1 text-xs text-muted-foreground transition-colors hover:bg-muted"
            @click="clearFilters"
          >
            <X class="h-3 w-3" /> Clear
          </button>
        </div>
      </div>

      <button
        v-if="canEdit"
        type="button"
        class="grid h-8 w-8 place-items-center rounded-md border transition-colors hover:bg-muted"
        title="New task"
        @click="startAdd('todo')"
      >
        <Plus class="h-3.5 w-3.5" />
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-6">
      <div v-for="group in groups" :key="group.id" class="mb-1">
        <!-- The group header carries the status's own colour as a left rule and a tint, which
             is what makes the board scannable without a legend. -->
        <div
          class="flex items-center gap-2 rounded border-l-2 px-2 py-2"
          :class="[group.tint, group.text.replace('text-', 'border-')]"
        >
          <button
            type="button"
            class="grid h-5 w-5 shrink-0 place-items-center rounded transition-transform hover:bg-background/50"
            :class="collapsed.has(group.id) && '-rotate-90'"
            :title="collapsed.has(group.id) ? 'Expand' : 'Collapse'"
            @click="toggleGroup(group.id)"
          >
            <ChevronDown class="h-3.5 w-3.5" />
          </button>
          <component :is="group.icon" class="h-4 w-4 shrink-0" :class="group.text" />
          <span class="text-sm font-semibold">{{ group.label }}</span>
          <span class="rounded bg-background/60 px-1.5 text-[11px] text-muted-foreground">{{ group.tasks.length }}</span>
          <span class="flex-1" />
          <button
            v-if="canEdit"
            type="button"
            class="grid h-5 w-5 place-items-center rounded text-muted-foreground transition-colors hover:bg-background/60 hover:text-foreground"
            :title="`New task in ${group.label}`"
            @click="startAdd(group.id)"
          >
            <Plus class="h-3.5 w-3.5" />
          </button>
        </div>

        <div v-if="!collapsed.has(group.id)">
          <TrackerTaskRow
            v-for="task in group.tasks"
            :key="task.id"
            :task="task"
            @open="emit('open', $event)"
          />

          <form v-if="addingIn === group.id" class="px-2 py-1" @submit.prevent="submitAdd">
            <Input
              id="tracker-add-input"
              v-model="newTitle"
              placeholder="Task title, then Enter"
              class="h-8 text-sm"
              @keydown.esc="addingIn = null"
              @blur="!newTitle.trim() && (addingIn = null)"
            />
          </form>
        </div>
      </div>

      <p v-if="!groups.some(g => g.tasks.length)" class="px-3 py-8 text-center text-sm text-muted-foreground">
        {{ filtering ? 'No tasks match those filters.' : 'No tasks yet — add the first one.' }}
      </p>
    </div>
  </div>
</template>
