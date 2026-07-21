<script setup lang="ts">
import { Mic, Paperclip, SendHorizontal, X } from 'lucide-vue-next'
import type { ChannelMember, GifResult } from '~/types'
import { Button } from '~/components/ui/button'
import { CHUNK_THRESHOLD, useChunkedUpload } from '~/composables/useChunkedUpload'

const props = defineProps<{
  placeholder?: string
  sending?: boolean
  maxFiles?: number
  /** Roster for the `@` autocomplete — passed straight through to the editor. */
  mentionMembers?: ChannelMember[]
  /** The channel this composer belongs to — the key its unsent draft is remembered under. */
  channelId?: number
}>()

const emit = defineEmits<{
  /** `uploadIds` names files already staged through the chunked path — see useChunkedUpload. */
  submit: [body: string, files: File[], gif?: GifResult, uploadIds?: string[]]
  /** Fires on every edit to the draft; the listener rate-limits it into a whisper. */
  typing: []
}>()

interface Pending {
  file: File
  preview: string | null // object URL for images
  // Object URL for a clip this browser can play, so a recording or a video can be checked
  // before it's sent. Null when it isn't playable — a dead player is worse than none.
  media: { url: string, kind: 'audio' | 'video' } | null
  /**
   * Set only for a file too big to ride inside the send request. It goes up in chunks the
   * moment it's picked, so the wait happens while you're still writing rather than after you
   * press send, and the message itself then carries just `id`. See useChunkedUpload.
   */
  upload: {
    id: string | null // the staged file's handle, once the server has issued one
    progress: number // 0–1
    failed: boolean
    error: string // why it failed, when the reason is worth reading (a size limit, say)
    abort: AbortController
  } | null
}

/**
 * Let go of a pending file: its object URLs, and any bytes it has already staged on the
 * server. Every removal path goes through here — an object URL or an abandoned upload that
 * outlives its card is a leak on one side or the other.
 */
function revoke(p: Pending) {
  if (p.preview) URL.revokeObjectURL(p.preview)
  if (p.media) URL.revokeObjectURL(p.media.url)
  if (!p.upload) return
  p.upload.abort.abort()
  if (p.upload.id) void cancel(p.upload.id)
}

/** Will this browser actually play the file? Same question AttachmentList asks of a sent one. */
function mediaFor(file: File): Pending['media'] {
  const kind = file.type.startsWith('audio/') ? 'audio' : file.type.startsWith('video/') ? 'video' : null
  if (!kind || !document.createElement(kind).canPlayType(file.type)) return null
  return { url: URL.createObjectURL(file), kind }
}

const { getDraft, setDraft } = useDrafts()
const { upload: uploadInChunks, cancel } = useChunkedUpload()

// Seeded from the stored draft, so reopening a channel you'd started typing in lands you
// right back on your unsent words. This is the *initial* ref value, not a change, so it
// doesn't trip the "typing" whisper below — you haven't typed, you've just arrived. (The
// composer is recreated per channel — both timelines key ChannelView by id — so this reads
// the right channel's draft once and never has to chase a changing channel.)
const draft = ref(props.channelId != null ? getDraft(props.channelId) : '')
const pending = ref<Pending[]>([])
const fileInput = ref<HTMLInputElement | null>(null)

const limit = computed(() => props.maxFiles ?? 10)
/** A big file is still on its way up; sending now would post a message missing its attachment. */
const staging = computed(() => pending.value.some(p => p.upload && !p.upload.id && !p.upload.failed))
const failedUpload = computed(() => pending.value.some(p => p.upload?.failed))
const canSend = computed(() =>
  (draft.value.trim().length > 0 || pending.value.length > 0)
  && !props.sending
  && !staging.value
  && !failedUpload.value,
)

function addFiles(list: FileList | File[] | null) {
  if (!list) return
  const incoming = Array.from(list).slice(0, limit.value - pending.value.length)
  const items: Pending[] = incoming.map(file => ({
    file,
    preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
    media: mediaFor(file),
    upload: file.size > CHUNK_THRESHOLD
      ? { id: null, progress: 0, failed: false, error: '', abort: new AbortController() }
      : null,
  }))
  pending.value = [...pending.value, ...items]

  // Start the big ones now rather than at send time: a 150MB file has minutes of transfer in
  // it, and those minutes may as well be spent while the message is still being written.
  // Staged through the *reactive* copies, not the raw objects above — progress written to a
  // raw object updates the value but never the card.
  for (const item of pending.value.slice(-items.length)) if (item.upload) void stage(item)
}

/** Push one oversized file up in chunks, keeping its card's progress in step. */
async function stage(item: Pending) {
  const state = item.upload!
  try {
    const id = await uploadInChunks(item.file, {
      signal: state.abort.signal,
      onProgress: (fraction) => { state.progress = fraction },
    })
    state.id = id
    state.progress = 1
  } catch (e: any) {
    // An abort means the card is already gone; anything else is a failure worth showing, and
    // the card offers a retry rather than silently dropping the file.
    if (state.abort.signal.aborted) return
    state.failed = true
    state.error = e?.data?.message ?? e?.message ?? ''
  }
}

/** Have another go at a staging that failed — a flaky line shouldn't cost you the whole pick. */
function retry(item: Pending) {
  if (!item.upload) return
  item.upload = { id: null, progress: 0, failed: false, error: '', abort: new AbortController() }
  void stage(item)
}

function fmtSize(bytes: number) {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function removePending(index: number) {
  const item = pending.value[index]
  if (item) revoke(item)
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
  // Two kinds of attachment leave here: small files travelling inside the request, and the
  // ids of big ones already sitting on the server.
  const direct = pending.value.filter(p => !p.upload).map(p => p.file)
  const staged = pending.value.map(p => p.upload?.id).filter((id): id is string => !!id)
  emit('submit', draft.value.trim(), direct, undefined, staged)
  // The staged files are claimed by the send now, so their cards must not cancel them.
  pending.value.forEach(p => revoke({ ...p, upload: null }))
  draft.value = ''
  pending.value = []
}

// A GIF sends the moment it's picked (like Slack) — no text or files needed alongside it.
function sendGif(gif: GifResult) {
  if (props.sending) return
  emit('submit', '', [], gif)
}

// Every edit does two things: persist the draft (so it survives leaving the channel or a
// reload) and, when there's real content, poke the typing whisper. Emptying the box saves
// nothing — setDraft drops the entry — so a sent or cleared message leaves no draft behind.
watch(draft, (value) => {
  if (props.channelId != null) setDraft(props.channelId, value)
  if (value.trim()) emit('typing')
})

onBeforeUnmount(() => {
  pending.value.forEach(revoke)
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
        <Mic v-else-if="p.file.type.startsWith('audio/')" class="h-5 w-5 text-muted-foreground" />
        <Paperclip v-else-if="!p.media" class="h-5 w-5 text-muted-foreground" />
        <!-- A recording or a clip is worth checking before you send it. -->
        <video v-if="p.media?.kind === 'video'" :src="p.media.url" controls class="max-h-24 max-w-[220px] rounded bg-black" />
        <audio v-else-if="p.media" :src="p.media.url" controls class="h-8 max-w-[220px]" />
        <span v-else class="max-w-[140px] truncate text-xs">{{ p.file.name }}</span>

        <!-- A big file is going up in the background; say where it's got to. -->
        <span v-if="p.upload" class="w-24 shrink-0">
          <template v-if="p.upload.failed">
            <button
              class="text-left text-xs text-destructive hover:underline"
              :title="p.upload.error || 'Try this upload again'"
              @click="retry(p)"
            >
              {{ p.upload.error || 'Upload failed' }} — retry
            </button>
          </template>
          <template v-else-if="p.upload.id">
            <span class="text-[11px] text-muted-foreground">{{ fmtSize(p.file.size) }} · ready</span>
          </template>
          <template v-else>
            <span class="block h-1 overflow-hidden rounded-full bg-muted">
              <span class="block h-full rounded-full bg-primary transition-[width]" :style="{ width: `${Math.round(p.upload.progress * 100)}%` }" />
            </span>
            <span class="mt-0.5 block text-[11px] text-muted-foreground">
              {{ Math.round(p.upload.progress * 100) }}% of {{ fmtSize(p.file.size) }}
            </span>
          </template>
        </span>
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
          <!-- A voice note lands in the pending list like any other file, so it can be heard,
               captioned or dropped before it goes. -->
          <VoiceRecorder :disabled="pending.length >= limit" @recorded="addFiles([$event])" />
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
              <template v-if="staging">Uploading… the message sends once its files are up.</template>
              <template v-else>
                <strong class="font-medium">Enter</strong> to send · <strong class="font-medium">Shift+Enter</strong> for a new line · markdown supported
              </template>
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
