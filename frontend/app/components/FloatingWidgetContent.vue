<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next'
import type { FloatingWidgetWindow } from '~/composables/useFloatingWindows'
import type { Widget } from '~/types'

/**
 * The live body of a floating widget window. Widgets are channel-scoped, so the window holds
 * only an id and refetches the state — exactly what a timeline card does when it arrives as a
 * reference. State stays current by listening for `.WidgetUpdated` on the widget's own channel
 * stream and refetching; the handler is kept so teardown removes *only ours*, leaving the
 * timeline's listener (useMessages) on the same channel object untouched. See useMusicPin,
 * which does the same dance for the one music widget that gets a dedicated dock.
 */
const props = defineProps<{ win: FloatingWidgetWindow }>()

const { close } = useFloatingWindows()
const api = useApi()
const echo: any = import.meta.client ? useNuxtApp().$echo : null

const widget = ref<Widget | null>(null)
const gone = ref(false)

let channel: any = null
const onUpdated = (ref_: { id: number }) => { if (ref_.id === props.win.widgetId) void refresh() }

async function refresh() {
  try {
    const res = await api<{ data: Widget }>(`/api/widgets/${props.win.widgetId}`)
    widget.value = res.data
  } catch {
    // Gone, or no longer visible to us — say so rather than hold a dead player.
    gone.value = true
    widget.value = null
  }
}

onMounted(async () => {
  await refresh()
  if (echo && !gone.value) {
    channel = echo.private(`channel.${props.win.channelId}`)
    channel.listen('.WidgetUpdated', onUpdated)
  }
})
onBeforeUnmount(() => {
  if (channel) channel.stopListening('.WidgetUpdated', onUpdated)
  channel = null
})
</script>

<template>
  <div class="h-full overflow-y-auto p-2">
    <div v-if="gone" class="flex h-full flex-col items-center justify-center gap-2 px-4 text-center text-xs text-muted-foreground">
      <p>This widget is no longer available.</p>
      <button class="rounded border px-2 py-1 hover:bg-muted" @click="close(win.id)">Close window</button>
    </div>
    <div v-else-if="!widget" class="flex h-full items-center justify-center">
      <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
    </div>
    <WidgetCard v-else :widget="widget" />
  </div>
</template>
