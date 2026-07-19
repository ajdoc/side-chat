<script setup lang="ts">
import { Loader2, Search } from 'lucide-vue-next'
import type { GifResult } from '~/types'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

const emit = defineEmits<{ select: [gif: GifResult] }>()

// Kept in the parent's reach the same way EmojiPicker is, so the composer toolbar can stay
// put while the grid is open.
const open = defineModel<boolean>('open', { default: false })

const { results, providers, loading, unconfigured, featured, search } = useGifs()

// "Powered by GIPHY & KLIPY" — whichever the server has configured.
const attribution = computed(() => providers.value.join(' & '))

const query = ref('')
let debounce: ReturnType<typeof setTimeout> | null = null

// Trending on first open; re-running featured each open would throw away a search the user
// might reopen to, so only fetch when we have nothing yet.
watch(open, (isOpen) => {
  if (isOpen && results.value.length === 0 && !unconfigured.value) featured()
})

watch(query, (q) => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => search(q), 300)
})

function pick(gif: GifResult) {
  emit('select', gif)
  open.value = false
}

onBeforeUnmount(() => {
  if (debounce) clearTimeout(debounce)
})
</script>

<template>
  <DropdownMenu v-model:open="open">
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        tabindex="-1"
        class="rounded p-1.5 text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground"
        title="Add a GIF"
        aria-label="Add a GIF"
      >
        GIF
      </button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="w-80 p-2">
      <div v-if="unconfigured" class="px-2 py-6 text-center text-sm text-muted-foreground">
        GIFs aren’t configured on this server.
      </div>

      <template v-else>
        <div class="relative mb-2">
          <Search class="absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            v-model="query"
            type="text"
            placeholder="Search Giphy"
            class="w-full rounded-md border bg-transparent py-1.5 pl-7 pr-2 text-sm outline-none focus:ring-1 focus:ring-ring"
          >
        </div>

        <div v-if="loading && !results.length" class="flex justify-center py-8">
          <Loader2 class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>

        <p v-else-if="!results.length" class="py-8 text-center text-sm text-muted-foreground">
          No GIFs found.
        </p>

        <div v-else class="grid max-h-72 grid-cols-2 gap-1.5 overflow-y-auto">
          <button
            v-for="gif in results"
            :key="gif.id"
            type="button"
            class="overflow-hidden rounded-md border bg-muted/40 transition hover:ring-2 hover:ring-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :title="gif.title"
            @click="pick(gif)"
          >
            <img :src="gif.preview_url" :alt="gif.title" loading="lazy" class="h-full w-full object-cover">
          </button>
        </div>

        <p v-if="attribution" class="mt-2 text-center text-[10px] text-muted-foreground">
          Powered by {{ attribution }}
        </p>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
