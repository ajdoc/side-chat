<script setup lang="ts">
import { Paperclip, SendHorizontal, X } from 'lucide-vue-next'
import type { ChannelMember, GifResult } from '~/types'
import { Button } from '~/components/ui/button'

const props = defineProps<{
  placeholder?: string
  sending?: boolean
  maxFiles?: number
  /** Roster for the `@` autocomplete — passed straight through to the editor. */
  mentionMembers?: ChannelMember[]
}>()

const emit = defineEmits<{
  submit: [body: string, files: File[], gif?: GifResult]
  /** Fires on every edit to the draft; the listener rate-limits it into a whisper. */
  typing: []
}>()

interface Pending {
  file: File
  preview: string | null // object URL for images
}

const draft = ref('')
const pending = ref<Pending[]>([])
const fileInput = ref<HTMLInputElement | null>(null)

const limit = computed(() => props.maxFiles ?? 10)
const canSend = computed(() => (draft.value.trim().length > 0 || pending.value.length > 0) && !props.sending)

function addFiles(list: FileList | File[] | null) {
  if (!list) return
  const incoming = Array.from(list).slice(0, limit.value - pending.value.length)
  pending.value = [
    ...pending.value,
    ...incoming.map(file => ({
      file,
      preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
    })),
  ]
}

function removePending(index: number) {
  const item = pending.value[index]
  if (item?.preview) URL.revokeObjectURL(item.preview)
  pending.value = pending.value.filter((_, i) => i !== index)
}

/** Ctrl+V: pull any images (or files) straight out of the clipboard. */
function onPaste(event: ClipboardEvent) {
  const items = event.clipboardData?.items
  if (!items) return

  const pasted: File[] = []
  for (const item of items) {
    if (item.kind === 'file') {
      const file = item.getAsFile()
      if (file) pasted.push(file)
    }
  }

  if (pasted.length) {
    event.preventDefault() // don't also paste the filename as text
    addFiles(pasted)
  }
}

function onPick(event: Event) {
  addFiles((event.target as HTMLInputElement).files)
  if (fileInput.value) fileInput.value.value = '' // allow re-picking the same file
}

function onDrop(event: DragEvent) {
  addFiles(event.dataTransfer?.files ?? null)
}

function submit() {
  if (!canSend.value) return
  emit('submit', draft.value.trim(), pending.value.map(p => p.file))
  pending.value.forEach(p => p.preview && URL.revokeObjectURL(p.preview))
  draft.value = ''
  pending.value = []
}

// A GIF sends the moment it's picked (like Slack) — no text or files needed alongside it.
function sendGif(gif: GifResult) {
  if (props.sending) return
  emit('submit', '', [], gif)
}

// Clearing the box (or sending) isn't "typing" — only actual content is.
watch(draft, (value) => {
  if (value.trim()) emit('typing')
})

onBeforeUnmount(() => {
  pending.value.forEach(p => p.preview && URL.revokeObjectURL(p.preview))
})
</script>

<template>
  <div @drop.prevent="onDrop" @dragover.prevent>
    <!-- Pending attachments -->
    <div v-if="pending.length" class="flex flex-wrap gap-2 px-3 pt-2">
      <div
        v-for="(p, i) in pending"
        :key="i"
        class="relative flex items-center gap-2 rounded-md border bg-muted/40 p-1.5"
      >
        <img v-if="p.preview" :src="p.preview" class="h-12 w-12 rounded object-cover" :alt="p.file.name">
        <Paperclip v-else class="h-5 w-5 text-muted-foreground" />
        <span class="max-w-[140px] truncate text-xs">{{ p.file.name }}</span>
        <button class="text-muted-foreground hover:text-destructive" title="Remove" @click="removePending(i)">
          <X class="h-3.5 w-3.5" />
        </button>
      </div>
    </div>

    <form class="p-3" @submit.prevent="submit">
      <input ref="fileInput" type="file" multiple class="hidden" @change="onPick">

      <MarkdownEditor
        v-model="draft"
        :placeholder="placeholder ?? 'Message'"
        :mention-members="mentionMembers"
        @submit="submit"
        @paste="onPaste"
      >
        <template #toolbar-end>
          <GifPicker @select="sendGif" />
          <button
            type="button"
            tabindex="-1"
            class="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            title="Attach files"
            @click="fileInput?.click()"
          >
            <Paperclip class="h-3.5 w-3.5" />
          </button>
        </template>

        <template #footer>
          <div class="flex items-center justify-between gap-2 px-2 pb-2">
            <span class="text-xs text-muted-foreground">
              <strong class="font-medium">Enter</strong> to send · <strong class="font-medium">Shift+Enter</strong> for a new line · markdown supported
            </span>
            <Button type="submit" size="icon" class="h-7 w-7 shrink-0" :disabled="!canSend" aria-label="Send">
              <SendHorizontal class="h-3.5 w-3.5" />
            </Button>
          </div>
        </template>
      </MarkdownEditor>
    </form>
  </div>
</template>
