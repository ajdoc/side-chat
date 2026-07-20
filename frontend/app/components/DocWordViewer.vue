<script setup lang="ts">
import '@vue-office/docx/lib/index.css'
import { AlertTriangle, Download, Loader2 } from 'lucide-vue-next'
import type { SpaceDocument } from '~/types'

/**
 * Renders a Word document (doc/docx) with the {@link https://github.com/501351981/vue-office
 * vue-office} docx viewer (docx-preview under the hood) — real page layout, styles, tables and
 * images, not the flattened text mammoth produced. Lazy-loaded so the engine only downloads
 * when a Word file is actually opened. Any failure degrades to a download link.
 *
 * `:src` is the document's signed URL; the component fetches it, so the file route must allow
 * cross-origin requests (see the `attachments/*` + `space-documents/*` entries in cors.php).
 */
const VueOfficeDocx = defineAsyncComponent(() => import('@vue-office/docx'))

const props = defineProps<{ document: SpaceDocument }>()

const loading = ref(true)
const error = ref('')

function onRendered() {
  loading.value = false
}
function onError() {
  loading.value = false
  error.value = 'This document couldn’t be previewed.'
}
</script>

<template>
  <div class="relative h-full overflow-auto bg-white">
    <div v-if="loading" class="absolute inset-0 z-10 flex items-center justify-center bg-white">
      <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
    </div>

    <div v-if="error" class="flex h-full flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
      <AlertTriangle class="h-6 w-6" />
      <p>{{ error }}</p>
      <a :href="document.download_url" class="inline-flex items-center gap-1 text-primary hover:underline">
        <Download class="h-4 w-4" /> Download instead
      </a>
    </div>

    <VueOfficeDocx
      v-else
      :key="document.id"
      :src="document.url"
      class="h-full"
      @rendered="onRendered"
      @error="onError"
    />
  </div>
</template>
