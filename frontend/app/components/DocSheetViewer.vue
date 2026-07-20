<script setup lang="ts">
import { AlertTriangle, Download, Loader2 } from 'lucide-vue-next'
import type { SpaceDocument } from '~/types'

/**
 * Renders a spreadsheet (xls/xlsx/csv) with the {@link https://github.com/501351981/vue-office
 * vue-office} excel viewer — a full-fidelity grid with every sheet as a tab, cell styles,
 * merged cells and column widths preserved, not the flat first-sheet table SheetJS produced.
 * Lazy-loaded so the engine only downloads when a spreadsheet is opened; failures degrade to
 * a download link.
 *
 * `:src` is the document's signed URL; the component fetches it, so the file route must allow
 * cross-origin requests (see the `attachments/*` + `space-documents/*` entries in cors.php).
 */
const VueOfficeExcel = defineAsyncComponent(() => import('@vue-office/excel'))

const props = defineProps<{ document: SpaceDocument }>()

const loading = ref(true)
const error = ref('')

function onRendered() {
  loading.value = false
}
function onError() {
  loading.value = false
  error.value = 'This spreadsheet couldn’t be previewed.'
}
</script>

<template>
  <div class="relative h-full overflow-hidden bg-white">
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

    <VueOfficeExcel
      v-else
      :key="document.id"
      :src="document.url"
      class="h-full"
      @rendered="onRendered"
      @error="onError"
    />
  </div>
</template>
