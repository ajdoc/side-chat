<script setup lang="ts">
import { X } from 'lucide-vue-next'
import type { AppTag } from '~/types'
import { tagChip } from '~/lib/tracker'

/**
 * A row of tag chips.
 *
 * Read-only on a task row, removable in the detail pane — one component for both, because the
 * chips have to look identical in the two places or the board stops matching the thing it links
 * to. `removable` only adds the ✕; it doesn't change the chip.
 */
defineProps<{
  tags: AppTag[]
  removable?: boolean
}>()

const emit = defineEmits<{ remove: [AppTag] }>()
</script>

<template>
  <div v-if="tags.length" class="flex flex-wrap items-center gap-1">
    <span
      v-for="tag in tags"
      :key="tag.id"
      class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium leading-none"
      :class="tagChip(tag.color)"
    >
      {{ tag.label }}
      <button
        v-if="removable"
        type="button"
        class="-mr-0.5 rounded-full opacity-60 transition-opacity hover:opacity-100"
        :title="`Remove ${tag.label}`"
        @click.stop="emit('remove', tag)"
      >
        <X class="h-2.5 w-2.5" />
      </button>
    </span>
  </div>
</template>
