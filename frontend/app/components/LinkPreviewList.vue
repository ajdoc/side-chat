<script setup lang="ts">
import type { LinkPreview } from '~/types'

defineProps<{ previews: LinkPreview[] }>()

/** Images that 404 after unfurling would otherwise leave a broken-image box in the card. */
const broken = ref<Set<number>>(new Set())

function markBroken(id: number) {
  broken.value = new Set(broken.value).add(id)
}
</script>

<template>
  <div v-if="previews.length" class="mt-1.5 flex flex-col gap-1.5">
    <template v-for="preview in previews" :key="preview.id">
      <!-- A link straight to an image is its own preview. -->
      <a
        v-if="preview.kind === 'image' && preview.image_url && !broken.has(preview.id)"
        :href="preview.url"
        target="_blank"
        rel="noopener noreferrer nofollow"
        class="block w-fit"
      >
        <img
          :src="preview.image_url"
          :alt="preview.url"
          loading="lazy"
          class="max-h-80 max-w-sm rounded-md border object-contain"
          @error="markBroken(preview.id)"
        >
      </a>

      <!-- Everything else: the Open Graph card. -->
      <a
        v-else-if="preview.kind === 'link'"
        :href="preview.url"
        target="_blank"
        rel="noopener noreferrer nofollow"
        class="flex max-w-md gap-3 rounded-md border border-l-2 border-l-primary bg-muted/30 p-2.5 no-underline transition hover:bg-muted/60"
      >
        <img
          v-if="preview.image_url && !broken.has(preview.id)"
          :src="preview.image_url"
          alt=""
          loading="lazy"
          class="h-14 w-14 shrink-0 rounded object-cover"
          @error="markBroken(preview.id)"
        >

        <div class="min-w-0">
          <p v-if="preview.site_name" class="truncate text-xs text-muted-foreground">
            {{ preview.site_name }}
          </p>
          <p class="truncate text-sm font-medium text-foreground">
            {{ preview.title }}
          </p>
          <p v-if="preview.description" class="line-clamp-2 text-xs text-muted-foreground">
            {{ preview.description }}
          </p>
        </div>
      </a>
    </template>
  </div>
</template>
