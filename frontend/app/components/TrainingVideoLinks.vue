<script setup lang="ts">
import { Play } from 'lucide-vue-next'
import type { TrainingVideo } from '~/types'
import { videoHref } from '~/lib/training'

/** The "watch how it's done" pills under a block or warm-up step. Each opens in a new tab. */
defineProps<{ videos: TrainingVideo[] }>()
</script>

<template>
  <span v-if="videos.length" class="mt-2 flex flex-wrap gap-1.5">
    <template v-for="v in videos" :key="v.label">
      <a
        v-if="videoHref(v)"
        :href="videoHref(v)!"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 rounded-full border bg-background py-1 pl-2 pr-2.5 text-xs leading-tight no-underline transition-colors hover:border-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400"
        :aria-label="`Watch videos: ${v.label} (opens YouTube)`"
      >
        <Play class="h-3 w-3 fill-emerald-600 text-emerald-600" /> {{ v.label }}
      </a>
    </template>
  </span>
</template>
