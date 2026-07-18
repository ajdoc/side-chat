<script setup lang="ts">
import { Rocket } from 'lucide-vue-next'

/**
 * The header entry to Side Chats — the app's signature surface, so it's branded rather than
 * a plain ghost action like Threads or Info sitting beside it. It carries a live count of
 * the channel's side chats (kept fresh over the channel stream via the shared
 * 'channel:sideChats' state) so the feature announces itself even before you open it.
 *
 * Self-navigating: opens the side chats list for the current route. The count is populated
 * by ChannelView on channel open; this button only reads it.
 */
defineProps<{ channelId: number }>()

const route = useRoute()
const { sideChats } = useSideChats()

const count = computed(() => sideChats.value.length)

function open() {
  navigateTo({ path: route.path, query: { sidechats: '1' } })
}
</script>

<template>
  <button
    type="button"
    class="flex h-8 shrink-0 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 text-sm font-medium text-primary transition-colors hover:bg-primary/20"
    title="Side Chats"
    @click="open"
  >
    <Rocket class="h-4 w-4" />
    <span>Side Chats</span>
    <span
      v-if="count"
      class="ml-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-xs font-semibold leading-none text-primary-foreground"
    >
      {{ count }}
    </span>
  </button>
</template>
