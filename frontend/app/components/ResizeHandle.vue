<script setup lang="ts">
/**
 * The thin draggable strip on a panel's border.
 *
 * Sits absolutely on one edge of a `relative` panel, invisible until hovered/dragged, and
 * just forwards the pointerdown to the panel's own `useResizable().startResize`. The visible
 * line is a hairline centred in a wider hit area, so it's easy to grab without a fat border.
 */
defineProps<{ edge?: 'left' | 'right' }>()
const emit = defineEmits<{ resize: [PointerEvent] }>()
</script>

<template>
  <div
    class="group absolute inset-y-0 z-20 w-2 cursor-col-resize touch-none select-none"
    :class="edge === 'right' ? '-right-1' : '-left-1'"
    role="separator"
    aria-orientation="vertical"
    aria-label="Resize panel"
    @pointerdown="emit('resize', $event)"
  >
    <div class="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-transparent transition-colors group-hover:bg-primary/60 group-active:bg-primary" />
  </div>
</template>
