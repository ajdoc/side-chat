<script setup lang="ts">
import { Loader2, MessageSquare, Send, Trash2 } from 'lucide-vue-next'

/**
 * A comment thread and a tag row, for any item in any app.
 *
 * Drop-in: give it the surface's base path, the item's morph name and its id, and it fetches,
 * renders and writes on its own. That's the payoff of making the tables polymorphic rather than
 * building the tracker its own — a calendar entry gets a discussion by adding this one tag to
 * its detail panel.
 *
 * Deliberately compact. This sits *inside* another app's detail panel, not beside it, so it
 * can't assume a column of its own — hence the small type, the collapsed-by-default thread and
 * no avatars larger than the surrounding text.
 */
const props = withDefaults(defineProps<{
  basePath: string
  /** The short morph name — 'calendar_event', 'canvas_item'. See App\Support\Apps\AppSubjects. */
  subject: string
  itemId: number | null
  canEdit?: boolean
  /** Tags are worth showing on some items and only noise on others. */
  showTags?: boolean
}>(), { canEdit: true, showTags: true })

const id = computed(() => props.itemId)

const { comments, tags, loading, loadTags, addComment, removeComment, attachTag, detachTag }
  = useAppItem(props.basePath, props.subject, id)

const { user } = useAuth()

const draft = ref('')
const tagDraft = ref('')
const addingTag = ref(false)

/**
 * The item's own tags, held here rather than read off the item.
 *
 * The host app's model doesn't carry them — a calendar event's resource knows nothing about
 * tags — so this component owns the list it renders. Reset when the open item changes.
 */
const mine = ref<{ id: number, label: string, color: any }[]>([])

watch(id, () => { mine.value = []; draft.value = '' })

onMounted(() => { if (props.showTags) void loadTags() })

async function submit() {
  const body = draft.value.trim()
  if (!body) return
  draft.value = ''
  await addComment(body)
}

async function submitTag() {
  const label = tagDraft.value.trim()
  if (!label) return
  tagDraft.value = ''
  addingTag.value = false
  const tag = await attachTag(label)
  if (tag && !mine.value.some(t => t.id === tag.id)) mine.value = [...mine.value, tag as any]
}

async function dropTag(tag: { id: number }) {
  mine.value = mine.value.filter(t => t.id !== tag.id)
  await detachTag(tag.id)
}

function when(iso: string) {
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m`
  if (mins < 1440) return `${Math.round(mins / 60)}h`
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div v-if="itemId != null" class="space-y-2 border-t pt-2">
    <div v-if="showTags" class="flex flex-wrap items-center gap-1">
      <TrackerTagChips :tags="mine as any" :removable="canEdit" @remove="dropTag" />
      <button
        v-if="canEdit && !addingTag"
        type="button"
        class="rounded-full border border-dashed px-2 py-0.5 text-[10px] text-muted-foreground transition-colors hover:bg-muted"
        @click="addingTag = true"
      >
        + Tag
      </button>
      <input
        v-else-if="canEdit"
        v-model="tagDraft"
        placeholder="tag name"
        class="w-24 rounded-full border bg-transparent px-2 py-0.5 text-[10px] outline-none focus:border-primary"
        @keydown.enter.prevent="submitTag"
        @keydown.esc="addingTag = false"
        @blur="addingTag = false"
      >
    </div>

    <div class="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground">
      <MessageSquare class="h-3 w-3" />
      Comments
      <Loader2 v-if="loading" class="h-3 w-3 animate-spin" />
      <span v-else-if="comments.length">({{ comments.length }})</span>
    </div>

    <div v-for="c in comments" :key="c.id" class="group flex items-start gap-1.5">
      <TrackerAvatar :user="c.user" size="xs" class="mt-0.5" />
      <div class="min-w-0 flex-1">
        <p class="flex items-center gap-1.5 text-[10px] text-muted-foreground">
          <span class="font-medium text-foreground">{{ c.user?.name ?? 'Someone' }}</span>
          {{ when(c.created_at) }}
          <button
            v-if="c.user?.id === user?.id"
            type="button"
            class="ml-auto opacity-0 transition-opacity hover:text-red-500 group-hover:opacity-100"
            title="Delete comment"
            @click="removeComment(c.id)"
          >
            <Trash2 class="h-2.5 w-2.5" />
          </button>
        </p>
        <p class="whitespace-pre-wrap break-words text-xs">{{ c.body }}</p>
      </div>
    </div>

    <form v-if="canEdit" class="flex items-center gap-1" @submit.prevent="submit">
      <input
        v-model="draft"
        placeholder="Comment…"
        class="min-w-0 flex-1 rounded border bg-transparent px-2 py-1 text-xs outline-none transition-colors focus:border-primary"
      >
      <button
        type="submit"
        class="grid h-6 w-6 shrink-0 place-items-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
        :disabled="!draft.trim()"
        title="Comment"
      >
        <Send class="h-3 w-3" />
      </button>
    </form>
  </div>
</template>
