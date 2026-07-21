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

/**
 * Sound and video play where they land — a voice message you have to download to hear isn't a
 * message. The player is *added* to the file card rather than replacing it: the name, the size
 * and the download stay exactly where they were for every attachment, playable or not.
 *
 * "Playable" is the browser's call, not ours — codecs differ per engine, and a dead <video>
 * with a broken-file icon is worse than the plain card. Extensions only come into it when the
 * upload arrived without a usable mime type, which some clients do.
 */
const EXT_MIME: Record<string, string> = {
  mp3: 'audio/mpeg',
  wav: 'audio/wav',
  ogg: 'audio/ogg',
  oga: 'audio/ogg',
  opus: 'audio/ogg',
  m4a: 'audio/mp4',
  aac: 'audio/aac',
  flac: 'audio/flac',
  mp4: 'video/mp4',
  m4v: 'video/mp4',
  webm: 'video/webm',
  ogv: 'video/ogg',
  mov: 'video/quicktime',
}

// canPlayType needs a document, so nothing is playable until we're on the client. One probe
// element per kind, reused: creating one per attachment per render adds up in a long timeline.
const clientReady = ref(false)
onMounted(() => { clientReady.value = true })
const probes: Partial<Record<'audio' | 'video', HTMLMediaElement>> = {}

/** The media type to play this attachment as, or null to leave it as a plain file card. */
function playerFor(a: Attachment): 'audio' | 'video' | null {
  if (!clientReady.value) return null

  const mime = a.mime_type.startsWith('audio/') || a.mime_type.startsWith('video/')
    ? a.mime_type
    : EXT_MIME[(a.extension ?? '').toLowerCase()] ?? ''
  if (!mime) return null

  const kind = mime.startsWith('audio/') ? 'audio' : 'video'
  const probe = probes[kind] ??= document.createElement(kind)

  return probe.canPlayType(mime) ? kind : null
}

/** Worked out once per list rather than per read, since the template asks more than once. */
const players = computed(() => {
  const map = new Map<number, 'audio' | 'video'>()
  for (const f of files.value) {
    const kind = playerFor(f)
    if (kind) map.set(f.id, kind)
  }
  return map
})

// Lightbox carousel state — opened by clicking an inline image/GIF, paging across the
// images in this message rather than opening a raw file URL in a new tab.
const lightboxOpen = ref(false)
const lightboxIndex = ref(0)

function openLightbox(id: number) {
  const at = images.value.findIndex(img => img.id === id)
  lightboxIndex.value = at < 0 ? 0 : at
  lightboxOpen.value = true
}

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
 * File cards only (images open in the lightbox instead). PDFs view in the browser;
 * anything else has no viewer, so "view" simply downloads it.
 */
function onView(a: Attachment) {
  if (a.is_pdf) {
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
          @click="openLightbox(img.id)"
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

    <!-- Non-images: a card per file with view + download, plus a player when it's playable -->
    <div
      v-for="f in files"
      :key="f.id"
      class="rounded-lg border bg-muted/30 p-2"
      :class="[isMarked(f.id) ? 'opacity-30' : '', players.get(f.id) === 'video' ? 'max-w-md' : 'max-w-sm']"
    >
      <div class="flex items-center gap-3">
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

      <!-- `preload="metadata"` so a timeline full of clips fetches durations, not the clips. -->
      <video
        v-if="players.get(f.id) === 'video'"
        :src="f.url"
        controls
        preload="metadata"
        class="mt-2 max-h-72 w-full rounded bg-black"
      />
      <audio
        v-else-if="players.get(f.id) === 'audio'"
        :src="f.url"
        controls
        preload="metadata"
        class="mt-2 w-full"
      />
    </div>

    <ImageLightbox
      v-if="images.length"
      v-model:open="lightboxOpen"
      :images="images"
      :start-index="lightboxIndex"
    />
  </div>
</template>
