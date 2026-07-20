<script setup lang="ts">
import { ArrowLeft, Check, Download, FileSpreadsheet, FileText, FileType2, Loader2, MessageSquarePlus, Trash2, Upload } from 'lucide-vue-next'
import type { SpaceDocument, SpaceDocumentKind } from '~/types'

/**
 * The Docs app — a shelf of office files (PDF, Word, Excel). It lists the surface's own
 * uploads *and* the documents shared in its chat (`source: 'chat'`), so the two file views
 * agree; shelf uploads in turn surface in Info → Files. Uploading is gated by `canEdit`;
 * anyone who can read the surface can open a file. A PDF renders in a native iframe (no
 * library); sheets and Word docs go through {@link DocSheetViewer} / {@link DocWordViewer},
 * which load their parsers on demand.
 *
 * Only shelf files offer delete and "Send to chat" (a chat file is already in chat, and
 * isn't the shelf's to remove). Narrow-panel friendly: the list is the default view; opening
 * a file swaps to a full-height viewer with a back arrow.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  readonlyHint?: string
}>()

const { documents, uploading, load, upload, remove, sendToChat, subscribe, unsubscribe } = useDocuments(props.basePath, props.streamName)

// "Send to chat" posts into a channel timeline; it isn't wired for the side chat surface yet.
const isChannel = computed(() => props.basePath.includes('/channels/'))

// A document is keyed by source+id — a shelf and a chat file can share a numeric id.
const keyOf = (d: SpaceDocument) => `${d.source}-${d.id}`
const selectedKey = ref<string | null>(null)
const selected = computed(() => documents.value.find(d => keyOf(d) === selectedKey.value) ?? null)
const fileInput = ref<HTMLInputElement | null>(null)
const error = ref('')
const sentKey = ref<string | null>(null) // flashes a ✓ on the card just shared to chat

const ICONS: Record<SpaceDocumentKind, any> = {
  pdf: FileText,
  sheet: FileSpreadsheet,
  word: FileType2,
  other: FileText,
}

function fmtSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = '' // let the same file be re-picked later
  if (!file) return
  error.value = ''
  try {
    const doc = await upload(file)
    selectedKey.value = keyOf(doc)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Upload failed. Check the file type and size (max 20MB).'
  }
}

async function onRemove(doc: SpaceDocument) {
  if (selectedKey.value === keyOf(doc)) selectedKey.value = null
  try {
    await remove(doc.id)
  } catch {
    error.value = 'Couldn’t delete that document.'
  }
}

async function onSend(doc: SpaceDocument) {
  try {
    await sendToChat(doc.id)
    sentKey.value = keyOf(doc)
    setTimeout(() => { if (sentKey.value === keyOf(doc)) sentKey.value = null }, 2000)
  } catch {
    error.value = 'Couldn’t send that document to chat.'
  }
}

onMounted(async () => {
  await load()
  subscribe()
})
onBeforeUnmount(unsubscribe)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- VIEWER — a file is open -->
    <template v-if="selected">
      <header class="flex h-10 shrink-0 items-center gap-2 border-b px-2">
        <button class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" title="Back to files" @click="selectedKey = null">
          <ArrowLeft class="h-4 w-4" />
        </button>
        <component :is="ICONS[selected.kind]" class="h-4 w-4 shrink-0 text-muted-foreground" />
        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ selected.name }}</span>
        <a :href="selected.download_url" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" title="Download">
          <Download class="h-4 w-4" />
        </a>
      </header>

      <div class="min-h-0 flex-1 bg-muted/20">
        <iframe
          v-if="selected.kind === 'pdf'"
          :src="selected.url"
          class="h-full w-full border-0"
          :title="selected.name"
        />
        <DocSheetViewer v-else-if="selected.kind === 'sheet'" :key="keyOf(selected)" :document="selected" />
        <DocWordViewer v-else-if="selected.kind === 'word'" :key="keyOf(selected)" :document="selected" />
        <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
          <FileText class="h-7 w-7" />
          <p>No preview for this file type.</p>
          <a :href="selected.download_url" class="inline-flex items-center gap-1 text-primary hover:underline">
            <Download class="h-4 w-4" /> Download
          </a>
        </div>
      </div>
    </template>

    <!-- LIST — the default view -->
    <template v-else>
      <div class="flex shrink-0 items-center gap-2 border-b p-2">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded border px-2 py-1 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-40"
          :disabled="!canEdit || uploading"
          @click="fileInput?.click()"
        >
          <Loader2 v-if="uploading" class="h-4 w-4 animate-spin" />
          <Upload v-else class="h-4 w-4" />
          {{ uploading ? 'Uploading…' : 'Upload' }}
        </button>
        <span v-if="!canEdit && readonlyHint" class="text-xs text-muted-foreground">{{ readonlyHint }}</span>
        <input
          ref="fileInput"
          type="file"
          class="hidden"
          accept=".pdf,.doc,.docx,.xls,.xlsx,.csv"
          @change="onFile"
        >
      </div>

      <p v-if="error" class="shrink-0 bg-destructive/10 px-3 py-1.5 text-xs text-destructive">{{ error }}</p>

      <div class="min-h-0 flex-1 overflow-y-auto p-2">
        <div
          v-for="doc in documents"
          :key="keyOf(doc)"
          class="group flex w-full cursor-pointer items-center gap-2.5 rounded-lg p-2 text-left hover:bg-muted"
          role="button"
          tabindex="0"
          @click="selectedKey = keyOf(doc)"
          @keydown.enter="selectedKey = keyOf(doc)"
        >
          <component :is="ICONS[doc.kind]" class="h-5 w-5 shrink-0 text-muted-foreground" />
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
              <span class="truncate text-sm font-medium">{{ doc.name }}</span>
              <span v-if="doc.source === 'chat'" class="shrink-0 rounded bg-muted px-1 py-px text-[9px] font-medium uppercase text-muted-foreground">in chat</span>
            </span>
            <span class="block truncate text-xs text-muted-foreground">
              {{ fmtSize(doc.size) }}<template v-if="doc.uploaded_by"> · {{ doc.uploaded_by.name }}</template>
            </span>
          </span>

          <!-- Shelf files can be pushed into the chat timeline (channel surfaces only). -->
          <button
            v-if="canEdit && isChannel && doc.source === 'shelf'"
            class="shrink-0 rounded p-1 text-muted-foreground opacity-0 hover:text-foreground group-hover:opacity-100"
            :title="sentKey === keyOf(doc) ? 'Sent to chat' : 'Send to chat'"
            @click.stop="onSend(doc)"
          >
            <Check v-if="sentKey === keyOf(doc)" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
            <MessageSquarePlus v-else class="h-4 w-4" />
          </button>
          <button
            v-if="canEdit && doc.source === 'shelf'"
            class="shrink-0 rounded p-1 text-muted-foreground opacity-0 hover:text-destructive group-hover:opacity-100"
            title="Delete"
            @click.stop="onRemove(doc)"
          >
            <Trash2 class="h-4 w-4" />
          </button>
        </div>

        <p v-if="!documents.length" class="p-3 text-sm text-muted-foreground">
          {{ canEdit ? 'No documents yet. Upload a PDF, Word, or Excel file.' : 'No documents yet.' }}
        </p>
      </div>
    </template>
  </div>
</template>
