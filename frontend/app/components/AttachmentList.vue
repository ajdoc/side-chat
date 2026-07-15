<script setup lang="ts">
import {
  Download,
  Eye,
  File as FileIcon,
  FileArchive,
  FileAudio,
  FileCode,
  FileSpreadsheet,
  FileText,
  FileVideo,
  X,
} from 'lucide-vue-next'
import type { Attachment } from '~/types'

const props = defineProps<{
  attachments: Attachment[]
  /** Edit mode: show an X on each attachment. */
  removable?: boolean
  /** Ids currently marked for removal (rendered dimmed). */
  markedForRemoval?: number[]
}>()

const emit = defineEmits<{ remove: [id: number] }>()

const images = computed(() => props.attachments.filter(a => a.is_image))
const files = computed(() => props.attachments.filter(a => !a.is_image))

function isMarked(id: number) {
  return props.markedForRemoval?.includes(id) ?? false
}

function iconFor(a: Attachment) {
  const ext = (a.extension ?? '').toLowerCase()
  if (a.is_pdf || ['doc', 'docx', 'txt', 'rtf', 'md'].includes(ext)) return FileText
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return FileArchive
  if (['xls', 'xlsx', 'csv'].includes(ext)) return FileSpreadsheet
  if (['json', 'xml', 'js', 'ts', 'php', 'html', 'css'].includes(ext)) return FileCode
  if (a.mime_type.startsWith('audio/')) return FileAudio
  if (a.mime_type.startsWith('video/')) return FileVideo
  return FileIcon
}

function formatSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function triggerDownload(a: Attachment) {
  const link = document.createElement('a')
  link.href = a.download_url
  link.download = a.name
  document.body.appendChild(link)
  link.click()
  link.remove()
}

/**
 * Images and PDFs can be viewed in the browser; anything else has no viewer, so
 * "view" simply downloads it.
 */
function onView(a: Attachment) {
  if (a.is_image || a.is_pdf) {
    window.open(a.url, '_blank', 'noopener')
  } else {
    triggerDownload(a)
  }
}
</script>

<template>
  <div v-if="attachments.length" class="mt-2 space-y-2">
    <!-- Images embed inline -->
    <div v-if="images.length" class="flex flex-wrap gap-2">
      <div
        v-for="img in images"
        :key="img.id"
        class="group/att relative"
        :class="isMarked(img.id) ? 'opacity-30' : ''"
      >
        <img
          :src="img.url"
          :alt="img.name"
          class="max-h-60 max-w-xs cursor-zoom-in rounded-lg border object-cover"
          @click="onView(img)"
        >
        <div class="absolute right-1 top-1 hidden gap-1 group-hover/att:flex">
          <button
            class="rounded bg-background/90 p-1 text-muted-foreground shadow hover:text-foreground"
            title="Download"
            @click.stop="triggerDownload(img)"
          >
            <Download class="h-3.5 w-3.5" />
          </button>
          <button
            v-if="removable"
            class="rounded bg-background/90 p-1 text-muted-foreground shadow hover:text-destructive"
            title="Remove"
            @click.stop="emit('remove', img.id)"
          >
            <X class="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
    </div>

    <!-- Non-images: a card per file with view + download -->
    <div
      v-for="f in files"
      :key="f.id"
      class="flex max-w-sm items-center gap-3 rounded-lg border bg-muted/30 p-2"
      :class="isMarked(f.id) ? 'opacity-30' : ''"
    >
      <component :is="iconFor(f)" class="h-8 w-8 shrink-0 text-muted-foreground" />
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-medium">{{ f.name }}</p>
        <p class="text-xs text-muted-foreground">
          {{ (f.extension ?? '').toUpperCase() }} · {{ formatSize(f.size) }}
        </p>
      </div>
      <button
        class="rounded p-1.5 text-muted-foreground hover:text-foreground"
        :title="f.is_pdf ? 'View PDF' : 'Open (downloads)'"
        @click="onView(f)"
      >
        <Eye class="h-4 w-4" />
      </button>
      <button
        class="rounded p-1.5 text-muted-foreground hover:text-foreground"
        title="Download"
        @click="triggerDownload(f)"
      >
        <Download class="h-4 w-4" />
      </button>
      <button
        v-if="removable"
        class="rounded p-1.5 text-muted-foreground hover:text-destructive"
        title="Remove"
        @click="emit('remove', f.id)"
      >
        <X class="h-4 w-4" />
      </button>
    </div>
  </div>
</template>
