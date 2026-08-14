<script setup lang="ts">
import { ChevronDown, ChevronUp, Loader2, Pencil, Plus, Send, Trash2 } from 'lucide-vue-next'
import type { AppTag, ChannelMember, TrackerTask } from '~/types'
import { TRACKER_PRIORITIES, TRACKER_STATUSES, activityLine, statusMeta, tagChip } from '~/lib/tracker'
import { Input } from '~/components/ui/input'

/**
 * One task, opened.
 *
 * Two columns: what the task *says* on the left (description, history, comments) and what it
 * *is* on the right (status, priority, assignee, due date, tags). That split is why the right
 * column is a stack of controls rather than a form with a Save button — every field writes on
 * change, so there is no unsaved state to lose and no button to forget.
 *
 * The task's own fields are edited through the shared tracker state, so the board row behind
 * this pane moves as you change them. Only the comments belong to this component — see
 * {@link useTrackerTask} for why they aren't held with everything else.
 */
const props = defineProps<{
  task: TrackerTask
  tags: AppTag[]
  members: ChannelMember[]
  canEdit: boolean
  /** Position in the list you opened it from, for the ↑/↓ pager. */
  index: number
  total: number
  comments: TrackerTask['comments']
  activity: TrackerTask['activity']
  loading?: boolean
}>()

const emit = defineEmits<{
  patch: [Partial<TrackerTask> & { tag_ids?: number[] }]
  comment: [string]
  /** A label the channel doesn't have yet — the parent creates it, then attaches it here. */
  'create-tag': [string]
  'edit-comment': [{ id: number, body: string }]
  'remove-comment': [number]
  remove: []
  step: [number]
}>()

const { user } = useAuth()

// --- description ----------------------------------------------------------------------

/**
 * The description is the one field that isn't saved on change.
 *
 * It's a paragraph, not a choice: saving per keystroke would be a request per character, and
 * saving on a timer would fight the cursor. So it's a local draft committed on blur, and only
 * when it actually differs — otherwise merely clicking into the box and out again would write
 * a no-op edit into everyone's view.
 */
const draft = ref(props.task.description ?? '')

watch(() => props.task.id, () => { draft.value = props.task.description ?? '' })
// A change arriving over broadcast should land in the box — unless you're mid-edit in it, in
// which case overwriting what you're typing is the worse of the two failures.
watch(() => props.task.description, (v) => {
  if (document.activeElement?.id !== 'tracker-description') draft.value = v ?? ''
})

function commitDescription() {
  const next = draft.value.trim()
  if (next === (props.task.description ?? '').trim()) return
  emit('patch', { description: next || null })
}

// --- title ----------------------------------------------------------------------------

const titleDraft = ref(props.task.title)
watch(() => props.task.id, () => { titleDraft.value = props.task.title })
watch(() => props.task.title, (v) => {
  if (document.activeElement?.id !== 'tracker-title') titleDraft.value = v
})

function commitTitle() {
  const next = titleDraft.value.trim()
  if (!next || next === props.task.title) {
    titleDraft.value = props.task.title
    return
  }
  emit('patch', { title: next })
}

// --- tags -----------------------------------------------------------------------------

const tagPickerOpen = ref(false)
const tagQuery = ref('')

const taskTagIds = computed(() => new Set((props.task.tags ?? []).map(t => t.id)))

/** The channel's vocabulary, minus what this task already wears, narrowed by what's typed. */
const availableTags = computed(() => {
  const q = tagQuery.value.trim().toLowerCase()
  return props.tags
    .filter(t => !taskTagIds.value.has(t.id))
    .filter(t => !q || t.label.toLowerCase().includes(q))
})

function addTag(tag: AppTag) {
  emit('patch', { tag_ids: [...taskTagIds.value, tag.id] })
  tagQuery.value = ''
}

function removeTag(tag: AppTag) {
  emit('patch', { tag_ids: [...taskTagIds.value].filter(id => id !== tag.id) })
}

/**
 * Whether what's typed would make a new tag.
 *
 * Compared against the channel's whole vocabulary rather than against `availableTags`, which
 * has this task's own tags filtered out — otherwise typing the name of a tag the task already
 * wears would offer to create a duplicate of it.
 */
const canCreateTag = computed(() => {
  const q = tagQuery.value.trim().toLowerCase()
  return !!q && !props.tags.some(t => t.label.toLowerCase() === q)
})

/**
 * Create the typed tag and put it on this task.
 *
 * Two steps, and the parent owns both: creating a tag writes to the channel's vocabulary, which
 * lives in the shared tracker state, so this component can only ask. It clears the box
 * optimistically — the round trip is the parent's problem, and leaving the text sitting there
 * makes it look as though nothing happened.
 */
function createTag() {
  const label = tagQuery.value.trim()
  if (!label) return
  emit('create-tag', label)
  tagQuery.value = ''
}

// --- comments -------------------------------------------------------------------------

const commentDraft = ref('')
const MAX_COMMENT = 2000

function submitComment() {
  const body = commentDraft.value.trim()
  if (!body || body.length > MAX_COMMENT) return
  emit('comment', body)
  commentDraft.value = ''
}

/**
 * Which comment is being edited, and its draft.
 *
 * One at a time by construction — an id rather than a per-comment flag — because two open
 * editors in one thread is a way to lose an edit you forgot you'd started.
 */
const editingId = ref<number | null>(null)
const editDraft = ref('')

function startEditComment(c: { id: number, body: string }) {
  editingId.value = c.id
  editDraft.value = c.body
}

function commitEditComment() {
  const id = editingId.value
  const body = editDraft.value.trim()
  if (id == null || !body || body.length > MAX_COMMENT) return
  emit('edit-comment', { id, body })
  editingId.value = null
}

function when(iso: string) {
  const d = new Date(iso)
  const mins = Math.round((Date.now() - d.getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.round(hours / 24)
  if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

const status = computed(() => statusMeta(props.task.status))
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Pager and delete, above everything: the position ("1 / 2") is the one piece of context
         that belongs to the *list* rather than to the task. -->
    <div class="flex shrink-0 items-center justify-end gap-1.5 border-b px-3 py-2">
      <span class="mr-1 text-xs text-muted-foreground">{{ index + 1 }} / {{ total }}</span>
      <button
        type="button"
        class="grid h-7 w-7 place-items-center rounded border transition-colors hover:bg-muted disabled:opacity-40"
        :disabled="index <= 0"
        title="Previous task"
        @click="emit('step', -1)"
      >
        <ChevronUp class="h-3.5 w-3.5" />
      </button>
      <button
        type="button"
        class="grid h-7 w-7 place-items-center rounded border transition-colors hover:bg-muted disabled:opacity-40"
        :disabled="index >= total - 1"
        title="Next task"
        @click="emit('step', 1)"
      >
        <ChevronDown class="h-3.5 w-3.5" />
      </button>
      <button
        v-if="canEdit"
        type="button"
        class="grid h-7 w-7 place-items-center rounded border text-red-500 transition-colors hover:bg-red-500/10"
        title="Delete this task"
        @click="emit('remove')"
      >
        <Trash2 class="h-3.5 w-3.5" />
      </button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div class="mx-auto flex max-w-5xl flex-col gap-6 p-4 lg:flex-row">
        <!-- Left: what the task says. -->
        <div class="min-w-0 flex-1 space-y-6">
          <div class="flex items-start gap-2">
            <span class="mt-1.5 shrink-0 font-mono text-xs text-muted-foreground">{{ task.key }}</span>
            <textarea
              id="tracker-title"
              v-model="titleDraft"
              rows="1"
              :readonly="!canEdit"
              class="min-w-0 flex-1 resize-none border-0 bg-transparent p-0 text-lg font-semibold leading-snug outline-none focus:ring-0"
              @blur="commitTitle"
              @keydown.enter.prevent="($event.target as HTMLTextAreaElement).blur()"
            />
          </div>

          <textarea
            id="tracker-description"
            v-model="draft"
            :readonly="!canEdit"
            placeholder="Add a description..."
            class="min-h-[160px] w-full resize-y rounded-lg border bg-transparent p-3 text-sm outline-none transition-colors focus:border-primary"
            @blur="commitDescription"
          />

          <!-- History. Read-only by construction — there is no endpoint that edits it. -->
          <section class="space-y-3">
            <h3 class="text-sm font-semibold">Activity</h3>
            <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-muted-foreground" />
            <p v-else-if="!activity?.length" class="text-xs text-muted-foreground">Nothing yet.</p>
            <div v-for="entry in activity" :key="entry.id" class="flex items-center gap-2 text-xs">
              <TrackerAvatar :user="entry.user" size="md" />
              <span class="font-medium text-foreground">{{ entry.user?.name ?? 'Someone' }}</span>
              <span class="text-muted-foreground">{{ activityLine(entry) }}</span>
              <span class="text-muted-foreground/60">• {{ when(entry.created_at) }}</span>
            </div>
          </section>

          <section class="space-y-3">
            <h3 class="text-sm font-semibold">Comments</h3>

            <div v-for="c in comments" :key="c.id" class="group flex gap-2">
              <TrackerAvatar :user="c.user" size="md" />
              <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-xs">
                  <span class="font-medium">{{ c.user?.name ?? 'Someone' }}</span>
                  <span class="text-muted-foreground/60">{{ when(c.created_at) }}</span>
                  <span v-if="c.edited_at" class="text-muted-foreground/60">(edited)</span>
                  <!-- Author only. Staff can delete too, but the server is what enforces that;
                       showing the button to everyone would just produce 403s. -->
                  <!-- Author only, for both. The server enforces it either way; showing the
                       buttons to everyone would just produce 403s. -->
                  <span v-if="c.user?.id === user?.id" class="ml-auto flex items-center gap-1.5 opacity-0 transition-opacity group-hover:opacity-100">
                    <button type="button" title="Edit comment" class="hover:text-foreground" @click="startEditComment(c)">
                      <Pencil class="h-3 w-3" />
                    </button>
                    <button type="button" title="Delete comment" class="hover:text-red-500" @click="emit('remove-comment', c.id)">
                      <Trash2 class="h-3 w-3" />
                    </button>
                  </span>
                </p>

                <!-- Editing happens in place, replacing the text with the same words in a box,
                     so the comment never leaves the thread it belongs to. -->
                <form v-if="editingId === c.id" class="mt-1 space-y-1" @submit.prevent="commitEditComment">
                  <textarea
                    v-model="editDraft"
                    rows="2"
                    class="w-full resize-y rounded border bg-transparent p-2 text-sm outline-none focus:border-primary"
                    @keydown.esc="editingId = null"
                    @keydown.enter.meta.prevent="commitEditComment"
                    @keydown.enter.ctrl.prevent="commitEditComment"
                  />
                  <div class="flex items-center gap-2">
                    <button type="submit" class="rounded bg-primary px-2 py-0.5 text-[11px] font-medium text-primary-foreground disabled:opacity-50" :disabled="!editDraft.trim()">
                      Save
                    </button>
                    <button type="button" class="text-[11px] text-muted-foreground" @click="editingId = null">Cancel</button>
                  </div>
                </form>
                <p v-else class="whitespace-pre-wrap break-words text-sm">{{ c.body }}</p>
              </div>
            </div>

            <form v-if="canEdit" class="rounded-lg border" @submit.prevent="submitComment">
              <textarea
                v-model="commentDraft"
                placeholder="Leave a comment"
                rows="3"
                class="w-full resize-y bg-transparent p-3 text-sm outline-none"
                @keydown.enter.meta.prevent="submitComment"
                @keydown.enter.ctrl.prevent="submitComment"
              />
              <div class="flex items-center justify-end gap-2 border-t px-2 py-1.5">
                <span
                  class="text-[11px]"
                  :class="commentDraft.length > MAX_COMMENT ? 'text-red-500' : 'text-muted-foreground'"
                >{{ commentDraft.length }}/{{ MAX_COMMENT }}</span>
                <button
                  type="submit"
                  class="grid h-6 w-6 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
                  :disabled="!commentDraft.trim() || commentDraft.length > MAX_COMMENT"
                  title="Comment"
                >
                  <Send class="h-3.5 w-3.5" />
                </button>
              </div>
            </form>
          </section>
        </div>

        <!-- Right: what the task is. Every control writes on change. -->
        <aside class="w-full shrink-0 space-y-4 lg:w-64">
          <div class="space-y-1.5">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Status</label>
            <div class="relative">
              <component :is="status.icon" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2" :class="status.text" />
              <select
                :value="task.status"
                :disabled="!canEdit"
                class="h-9 w-full rounded-md border bg-background pl-8 pr-2 text-sm outline-none transition-colors focus:border-primary"
                @change="emit('patch', { status: ($event.target as HTMLSelectElement).value as any })"
              >
                <option v-for="s in TRACKER_STATUSES" :key="s.id" :value="s.id">{{ s.label }}</option>
              </select>
            </div>
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Priority</label>
            <select
              :value="task.priority"
              :disabled="!canEdit"
              class="h-9 w-full rounded-md border bg-background px-2 text-sm outline-none transition-colors focus:border-primary"
              @change="emit('patch', { priority: ($event.target as HTMLSelectElement).value as any })"
            >
              <option v-for="p in TRACKER_PRIORITIES" :key="p.id" :value="p.id">{{ p.label }}</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Assignee</label>
            <select
              :value="task.assignee?.id ?? ''"
              :disabled="!canEdit"
              class="h-9 w-full rounded-md border bg-background px-2 text-sm outline-none transition-colors focus:border-primary"
              @change="emit('patch', { assignee_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null } as any)"
            >
              <option value="">Unassigned</option>
              <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Due date</label>
            <Input
              type="date"
              :model-value="task.due_date ?? ''"
              :disabled="!canEdit"
              class="h-9 text-sm"
              @update:model-value="emit('patch', { due_date: ($event as string) || null })"
            />
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Tags</label>
            <TrackerTagChips :tags="task.tags ?? []" removable @remove="removeTag" />

            <div v-if="canEdit" class="relative">
              <button
                type="button"
                class="grid h-6 w-6 place-items-center rounded border border-dashed text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                title="Add a tag"
                @click="tagPickerOpen = !tagPickerOpen"
              >
                <Plus class="h-3 w-3" />
              </button>

              <!-- A combo box, not a menu: typing a name that doesn't exist creates it, which
                   is how a vocabulary grows without a separate "manage tags" trip. -->
              <div
                v-if="tagPickerOpen"
                class="absolute left-0 top-8 z-20 w-52 space-y-2 rounded-lg border bg-background p-2 shadow-lg"
              >
                <Input v-model="tagQuery" placeholder="Find or create..." class="h-7 text-xs" />
                <div class="max-h-40 space-y-1 overflow-y-auto">
                  <button
                    v-for="tag in availableTags"
                    :key="tag.id"
                    type="button"
                    class="block w-full rounded border px-2 py-0.5 text-left text-[11px]"
                    :class="tagChip(tag.color)"
                    @click="addTag(tag)"
                  >{{ tag.label }}</button>
                  <p v-if="!availableTags.length && !tagQuery.trim()" class="px-1 text-[11px] text-muted-foreground">
                    No tags yet.
                  </p>
                </div>
                <button
                  v-if="canCreateTag"
                  type="button"
                  class="w-full rounded border border-dashed px-2 py-1 text-[11px] text-muted-foreground transition-colors hover:bg-muted"
                  @click="createTag"
                >
                  Create “{{ tagQuery.trim() }}”
                </button>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </div>
</template>
