<script setup lang="ts">
import { Download, Loader2, Search } from 'lucide-vue-next'
import type { AppImportSource, SideDeskAppId } from '~/types'
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from '~/components/ui/dialog'
import { Button } from '~/components/ui/button'

/**
 * "Bring this app's content in from another channel."
 *
 * One dialog for every app, because import means the same thing everywhere — the difference is
 * entirely server-side, in `App\Support\Apps\AppImports`. So this asks the server two questions
 * ("can this app be imported, and which channels have any?") and never itself learns what a
 * board or a shelf is.
 *
 * ## What it promises, in the copy
 *
 * Both facts people need before clicking are on screen rather than in a tooltip: **the source
 * keeps everything** (this is a copy) and **it adds to what's here** (nothing is replaced). Both
 * are true of every importer, which is why they can be stated once here.
 *
 * The list only offers channels that actually hold something, and each row carries its count —
 * "Design · 24 cards" is how you tell two similarly named channels apart, and the number is the
 * thing you were going to check anyway.
 */
const props = defineProps<{
  /** The surface being filled — `/api/channels/12`. */
  basePath: string
  /** Named for a Side Desk tab; omitted in an app channel, where the channel *is* the app. */
  app?: SideDeskAppId | null
  label: string
}>()

const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ imported: [count: number] }>()

const api = useApi()

const sources = ref<AppImportSource[]>([])
const importable = ref(true)
const loading = ref(false)
const busyId = ref<number | null>(null)
const error = ref('')
const done = ref<number | null>(null)
const query = ref('')

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return q ? sources.value.filter(s => s.name.toLowerCase().includes(q)) : sources.value
})

async function load() {
  loading.value = true
  error.value = ''
  done.value = null
  try {
    const res = await api<{ importable: boolean, sources: AppImportSource[] }>(
      `${props.basePath}/apps/import/sources`,
      { query: props.app ? { app: props.app } : undefined },
    )
    importable.value = res.importable
    sources.value = res.sources
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not look for anything to import.'
  }
  finally {
    loading.value = false
  }
}

// Loaded on each open rather than once: what another channel holds changes while you're away,
// and a stale count is the one number in this dialog nobody would think to doubt.
watch(open, isOpen => {
  if (isOpen) void load()
})

async function run(source: AppImportSource) {
  busyId.value = source.id
  error.value = ''
  try {
    const res = await api<{ imported: number }>(`${props.basePath}/apps/import`, {
      method: 'POST',
      body: { source_channel_id: source.id, ...(props.app ? { app: props.app } : {}) },
    })
    done.value = res.imported
    emit('imported', res.imported)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'That import didn’t go through.'
  }
  finally {
    busyId.value = null
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-md">
      <DialogHeader>
        <DialogTitle>Import into {{ label }}</DialogTitle>
        <DialogDescription>
          Copies another channel’s {{ label.toLowerCase() }} in here. The other channel keeps
          everything, and nothing already here is replaced.
        </DialogDescription>
      </DialogHeader>

      <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">Looking…</p>

      <p v-else-if="!importable" class="py-6 text-center text-sm text-muted-foreground">
        This app has nothing that can be imported.
      </p>

      <p v-else-if="sources.length === 0" class="py-6 text-center text-sm text-muted-foreground">
        None of the channels you’re in has anything in this app yet.
      </p>

      <template v-else>
        <div v-if="sources.length > 6" class="relative">
          <Search class="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            v-model="query"
            placeholder="Find a channel…"
            class="w-full rounded-md border bg-background py-1.5 pl-7 pr-2 text-sm"
          >
        </div>

        <ul class="max-h-72 space-y-1 overflow-y-auto">
          <li
            v-for="source in filtered"
            :key="source.id"
            class="flex items-center gap-2 rounded-md border p-2"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ source.name }}</p>
              <p class="truncate text-xs text-muted-foreground">
                <span v-if="source.server">{{ source.server }} · </span>{{ source.count }} items
              </p>
            </div>
            <Button size="sm" variant="outline" :disabled="busyId !== null" @click="run(source)">
              <Loader2 v-if="busyId === source.id" class="h-3.5 w-3.5 animate-spin" />
              <Download v-else class="h-3.5 w-3.5" />
              Import
            </Button>
          </li>
        </ul>
      </template>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      <p v-else-if="done !== null" class="text-sm text-muted-foreground">
        Imported {{ done }} item{{ done === 1 ? '' : 's' }}. Import again from somewhere else, or
        close this.
      </p>
    </DialogContent>
  </Dialog>
</template>
