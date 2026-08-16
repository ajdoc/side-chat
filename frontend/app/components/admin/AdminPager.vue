<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'

/**
 * Prev / next over a Laravel paginator.
 *
 * Deliberately not numbered pages: the tables here are sorted newest-first over data other
 * people are changing while you read it, so "page 7" isn't a place you can return to. Two
 * buttons and a count is the honest interface for that.
 */
defineProps<{ page: number, lastPage: number, total: number, busy?: boolean }>()
defineEmits<{ 'update:page': [value: number] }>()
</script>

<template>
  <div v-if="total > 0" class="mt-4 flex items-center justify-between gap-3 text-sm text-muted-foreground">
    <span>{{ total.toLocaleString() }} total · page {{ page }} of {{ lastPage }}</span>
    <div class="flex gap-2">
      <Button variant="outline" size="sm" :disabled="busy || page <= 1" @click="$emit('update:page', page - 1)">
        <ChevronLeft class="h-4 w-4" /> Prev
      </Button>
      <Button variant="outline" size="sm" :disabled="busy || page >= lastPage" @click="$emit('update:page', page + 1)">
        Next <ChevronRight class="h-4 w-4" />
      </Button>
    </div>
  </div>
</template>
