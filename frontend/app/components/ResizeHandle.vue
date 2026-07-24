<script setup lang="ts">
/**
 * The thin draggable strip on a panel's border.
 *
 * Sits absolutely on one edge of a `relative` panel, invisible until hovered/dragged, and
 * just forwards the pointerdown to the panel's own `useResizable().startResize`. The visible
 * line is a hairline centred in a wider hit area, so it's easy to grab without a fat border.
 *
 * 'top'/'bottom' do the same for a full-width band whose *height* is being dragged — a Side
 * Space's map above its timeline. The only differences are which axis it stretches along and
 * which cursor it wears.
 */
const props = defineProps<{ edge?: 'left' | 'right' | 'top' | 'bottom' }>()
const emit = defineEmits<{ resize: [PointerEvent] }>()

const vertical = computed(() => props.edge === 'top' || props.edge === 'bottom')
</script>

<template>
  <div
    class="group absolute z-20 touch-none select-none"
    :class="vertical
      ? ['inset-x-0 h-2 cursor-row-resize', edge === 'bottom' ? '-bottom-1' : '-top-1']
      : ['inset-y-0 w-2 cursor-col-resize', edge === 'right' ? '-right-1' : '-left-1']"
    role="separator"
    :aria-orientation="vertical ? 'horizontal' : 'vertical'"
    aria-label="Resize panel"
    @pointerdown="emit('resize', $event)"
  >
    <div
      class="absolute bg-transparent transition-colors group-hover:bg-primary/60 group-active:bg-primary"
      :class="vertical
        ? 'inset-x-0 top-1/2 h-px -translate-y-1/2'
        : 'inset-y-0 left-1/2 w-px -translate-x-1/2'"
    />
  </div>
</template>
