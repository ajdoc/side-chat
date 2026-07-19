<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download, ExternalLink, X } from 'lucide-vue-next'
import type { Attachment } from '~/types'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '~/components/ui/dialog'

/**
 * A pop-up carousel for images and GIFs — opens in place instead of navigating away to a
 * raw file URL in a new tab. Given the full set of images in a message and the one that was
 * clicked, it lets you page left/right through them without leaving the conversation.
 */
const props = defineProps<{
  images: Attachment[]
  /** Index within `images` to open on. */
  startIndex: number
}>()

const open = defineModel<boolean>('open', { default: false })

const index = ref(props.startIndex)

// Each fresh open re-syncs to whichever image was clicked.
watch(open, (isOpen) => {
  if (isOpen) index.value = props.startIndex
})

const current = computed(() => props.images[index.value] ?? null)
const hasMultiple = computed(() => props.images.length > 1)

function prev() {
  index.value = (index.value - 1 + props.images.length) % props.images.length
}
function next() {
  index.value = (index.value + 1) % props.images.length
}

function onKey(event: KeyboardEvent) {
  if (!open.value || !hasMultiple.value) return
  if (event.key === 'ArrowLeft') prev()
  else if (event.key === 'ArrowRight') next()
}

function triggerDownload(a: Attachment) {
  const link = document.createElement('a')
  link.href = a.download_url
  link.download = a.name
  document.body.appendChild(link)
  link.click()
  link.remove()
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent
      class="max-w-4xl border-0 bg-transparent p-0 shadow-none sm:max-w-4xl"
      :show-close-button="false"
    >
      <!-- Present but visually hidden: reka's Dialog requires a title/description for a11y. -->
      <DialogTitle class="sr-only">{{ current?.name ?? 'Image' }}</DialogTitle>
      <DialogDescription class="sr-only">
        Image {{ index + 1 }} of {{ images.length }}
      </DialogDescription>

      <div v-if="current" class="relative flex items-center justify-center">
        <img
          :src="current.url"
          :alt="current.name"
          class="max-h-[80vh] max-w-full rounded-lg object-contain"
        >

        <!-- top-right controls -->
        <div class="absolute right-2 top-2 flex gap-1">
          <a
            :href="current.url"
            target="_blank"
            rel="noopener"
            class="rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
            title="Open in new tab"
          >
            <ExternalLink class="h-4 w-4" />
          </a>
          <button
            type="button"
            class="rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
            title="Download"
            @click="triggerDownload(current)"
          >
            <Download class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
            title="Close"
            @click="open = false"
          >
            <X class="h-4 w-4" />
          </button>
        </div>

        <!-- prev / next, only when there's more than one -->
        <template v-if="hasMultiple">
          <button
            type="button"
            class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
            title="Previous"
            @click="prev"
          >
            <ChevronLeft class="h-5 w-5" />
          </button>
          <button
            type="button"
            class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-black/60 p-2 text-white transition hover:bg-black/80"
            title="Next"
            @click="next"
          >
            <ChevronRight class="h-5 w-5" />
          </button>
          <div class="absolute bottom-2 left-1/2 -translate-x-1/2 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white tabular-nums">
            {{ index + 1 }} / {{ images.length }}
          </div>
        </template>
      </div>
    </DialogContent>
  </Dialog>
</template>
