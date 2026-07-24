<script setup lang="ts">
import type { CanvasItem } from '~/types'

/**
 * A `note` card's body — a plain autosaving text area filling the card. Edits are debounced
 * and emitted up as a `content` change; the host ({@link SideDeskCanvas}) persists them.
 * Remote edits are adopted only while this textarea is unfocused, so a save landing from
 * someone else never moves your cursor.
 */
const props = defineProps<{ item: CanvasItem, canEdit: boolean }>()
const emit = defineEmits<{ change: [Record<string, any>] }>()

const textarea = ref<HTMLTextAreaElement | null>(null)
const text = ref<string>(props.item.content.text ?? '')
let timer: ReturnType<typeof setTimeout> | undefined

watch(() => props.item.content.text, (t) => {
  if (document.activeElement !== textarea.value) text.value = t ?? ''
})

function onInput() {
  clearTimeout(timer)
  timer = setTimeout(() => emit('change', { text: text.value }), 500)
}
function flush() {
  clearTimeout(timer)
  if (text.value !== (props.item.content.text ?? '')) emit('change', { text: text.value })
}
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <textarea
    ref="textarea"
    v-model="text"
    :readonly="!canEdit"
    placeholder="Note…"
    class="h-full w-full resize-none bg-transparent p-2 text-sm leading-snug outline-none placeholder:text-muted-foreground read-only:cursor-default"
    @input="onInput"
    @blur="flush"
    @pointerdown.stop
  />
</template>
