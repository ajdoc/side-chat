<script setup lang="ts">
/**
 * The little status dot on a user's avatar: green when online and active, amber when idle,
 * nothing at all when offline. Sizing and positioning are the caller's — it usually drops this
 * into a `relative` avatar wrapper as `absolute bottom-0 right-0 ...` — so the same dot serves
 * a sidebar row, a participant list and a chat header. Reads the shared presence map, so every
 * dot updates together as people come, go and drift idle. See {@link usePresence}.
 */
const props = defineProps<{ userId: number }>()

const { statusOf } = usePresence()
const status = computed(() => statusOf(props.userId))
</script>

<template>
  <span
    v-if="status !== 'offline'"
    class="block rounded-full ring-2 ring-background"
    :class="status === 'idle' ? 'bg-amber-400' : 'bg-green-500'"
    :title="status === 'idle' ? 'Idle' : 'Online'"
  />
</template>
