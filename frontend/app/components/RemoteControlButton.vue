<script setup lang="ts">
import { MousePointer2, Unplug } from 'lucide-vue-next'

/**
 * "Request control" beside a screen you're watching — and "Release" once you have it.
 *
 * Its own component because both stages need it: the voice channel's and the Side Space call
 * dock's. Renders nothing when there's nothing sensible to offer, so a caller can drop it into
 * a controls row without guarding.
 */
const props = defineProps<{ peerId: number }>()

const { controlling, awaiting, requestControl, releaseControl } = useRemoteControl()

const holding = computed(() => controlling.value === props.peerId)
const asking = computed(() => awaiting.value === props.peerId)

// Somebody else's screen already has our hands on it — asking for a second would put us in two
// places at once, and the state machine only tracks one.
const busyElsewhere = computed(() => controlling.value !== null && !holding.value)
</script>

<template>
  <button
    v-if="!busyElsewhere"
    type="button"
    class="flex shrink-0 items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition"
    :class="holding
      ? 'border-primary bg-primary text-primary-foreground hover:opacity-90'
      : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
    :disabled="asking"
    :title="holding
      ? 'Hand control back'
      : 'Ask to control this screen — they have to allow it'"
    @click="holding ? releaseControl() : requestControl(peerId)"
  >
    <component :is="holding ? Unplug : MousePointer2" class="h-3.5 w-3.5" />
    <span>{{ holding ? 'Release control' : asking ? 'Asking…' : 'Request control' }}</span>
  </button>
</template>
