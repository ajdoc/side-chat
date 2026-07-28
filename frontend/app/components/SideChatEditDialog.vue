<script setup lang="ts">
import { X } from 'lucide-vue-next'
import type { SideChat } from '~/types'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

/**
 * Edit a side chat post's title and tags — the forum layer's "edit post".
 *
 * Title and tags are edited together because they're the same decision: both are how the
 * post presents itself in the list, and you don't change one without looking at the other.
 * The dialog always sends *both* fields, so clearing every tag is expressible; the
 * composable's `updateSideChat` documents why that matters.
 *
 * Tags are typed as free text, one per Enter or comma. There's no picker because there's
 * no catalogue yet — a tag is a word somebody chose (see the backend migration). The
 * server lowercases and dedupes, so the chips here are only a preview of that.
 */
const props = defineProps<{ sideChat: SideChat }>()
const emit = defineEmits<{ close: [], saved: [SideChat] }>()

// `tagCounts` is every tag already in use in this channel, so an existing one can be reused
// with a click instead of retyped — the difference between a tag list and a pile of
// near-duplicate spellings of the same word.
const { updateSideChat, tagCounts } = useSideChats()
// The groups this channel offers. Only listed here — creating one is the staff's, and it
// happens in the panel's toolbar, not inside an edit-a-post dialog.
const { forums } = useSideChatForums()

const name = ref(props.sideChat.name)
const tags = ref<string[]>([...(props.sideChat.tags ?? [])])
/**
 * Which group the post is filed under. Bound as a string because that's what a `<select>`
 * gives back; `''` is the empty option, which means Uncategorised — a real choice, and the
 * one every post starts with.
 */
const forumId = ref(props.sideChat.side_chat_forum_id ? String(props.sideChat.side_chat_forum_id) : '')
const draft = ref('')
const saving = ref(false)
const error = ref<string | null>(null)

const MAX_TAGS = 8

const suggestions = computed(() =>
  tagCounts.value.filter(t => !tags.value.includes(t.tag)).slice(0, 12),
)

function addTag(raw: string) {
  const tag = raw.trim().toLowerCase()
  if (!tag || tags.value.includes(tag) || tags.value.length >= MAX_TAGS) return
  tags.value = [...tags.value, tag]
}

/** Enter or comma commits the draft — the two things people reach for interchangeably. */
function onTagKey(e: KeyboardEvent) {
  if (e.key !== 'Enter' && e.key !== ',') return
  e.preventDefault()
  addTag(draft.value)
  draft.value = ''
}

function removeTag(tag: string) {
  tags.value = tags.value.filter(t => t !== tag)
}

async function save() {
  const trimmed = name.value.trim()
  if (!trimmed || saving.value) return
  saving.value = true
  error.value = null
  try {
    // Fold in whatever is still sitting in the input: nobody expects a tag they typed to
    // be dropped because they pressed Save instead of Enter.
    if (draft.value.trim()) { addTag(draft.value); draft.value = '' }
    const next = await updateSideChat(props.sideChat.id, {
      name: trimmed,
      tags: tags.value,
      // Always sent, null included: null is "move it back to Uncategorised", which has to
      // be as expressible as filing it. Same argument as tags, above.
      side_chat_forum_id: forumId.value ? Number(forumId.value) : null,
    })
    emit('saved', next)
    emit('close')
  } catch {
    error.value = "Couldn't save those changes."
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="w-full max-w-md rounded-xl border bg-background p-4 shadow-lg">
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-semibold">Edit side chat</h2>
        <button class="text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </div>

      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Title</label>
      <Input v-model="name" placeholder="Side chat title" autofocus />

      <!-- Only offered where there is somewhere to file it: with no groups made yet this is
           a control whose every option is "no". -->
      <template v-if="forums.length">
        <label for="side-chat-forum" class="mb-1 mt-4 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Group</label>
        <select
          id="side-chat-forum"
          v-model="forumId"
          class="w-full rounded-lg border bg-background px-2 py-2 text-sm outline-none focus:ring-1 focus:ring-ring"
        >
          <option value="">Uncategorised</option>
          <option v-for="f in forums" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
        </select>
      </template>

      <label class="mb-1 mt-4 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        Tags <span class="normal-case font-normal">({{ tags.length }}/{{ MAX_TAGS }})</span>
      </label>
      <div class="flex flex-wrap items-center gap-1 rounded-lg border bg-background px-2 py-1.5">
        <span
          v-for="tag in tags"
          :key="tag"
          class="flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
        >
          {{ tag }}
          <button class="hover:text-foreground" :aria-label="`Remove ${tag}`" @click="removeTag(tag)">
            <X class="h-3 w-3" />
          </button>
        </span>
        <input
          v-model="draft"
          :disabled="tags.length >= MAX_TAGS"
          :placeholder="tags.length >= MAX_TAGS ? 'Tag limit reached' : 'Add a tag…'"
          class="min-w-24 flex-1 bg-transparent py-0.5 text-sm outline-none placeholder:text-muted-foreground"
          @keydown="onTagKey"
        >
      </div>

      <div v-if="suggestions.length" class="mt-2 flex flex-wrap gap-1">
        <button
          v-for="s in suggestions"
          :key="s.tag"
          class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          :title="`${s.count} side chat${s.count === 1 ? '' : 's'} tagged ${s.tag}`"
          @click="addTag(s.tag)"
        >
          {{ s.tag }}
        </button>
      </div>

      <p v-if="error" class="mt-2 text-xs text-destructive">{{ error }}</p>

      <div class="mt-4 flex justify-end gap-2">
        <Button variant="ghost" @click="emit('close')">Cancel</Button>
        <Button :disabled="!name.trim() || saving" @click="save">{{ saving ? 'Saving…' : 'Save' }}</Button>
      </div>
    </div>
  </div>
</template>
