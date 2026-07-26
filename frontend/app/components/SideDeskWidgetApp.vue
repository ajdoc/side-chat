<script setup lang="ts">
import { AlertCircle } from 'lucide-vue-next'
import type { Widget, WidgetType } from '~/types'

/**
 * A widget, rendered as a full Side Desk tab.
 *
 * The other half of the apps-and-widgets unification: the canvas could always place a widget as
 * a card, and now the tab strip can open the same widget full-width. There is deliberately no
 * new state here — this resolves the *channel's* widget of the type (creating it on first open,
 * exactly as dropping a canvas card or typing `m!p` does) and hands it to the same
 * {@link WidgetCard} the timeline uses.
 *
 * So the tab, the canvas card and the chat message are three placements of one row. Nothing
 * keeps them in sync because nothing has to.
 *
 * Note the scope: widgets are channel-scoped, so a side chat's Kanban tab is its *parent
 * channel's* kanban board — the same scoping its canvas cards have always had.
 */
const props = defineProps<{
  type: WidgetType
  /** The channel the widget belongs to — a side chat passes its parent's id. */
  channelId: number
}>()

const api = useApi()
const echo: any = useNuxtApp().$echo

const widget = ref<Widget | null>(null)
const error = ref('')

/** Pull fresh state after a `WidgetUpdated` reference lands (the state is too big for the socket). */
async function refresh(id: number) {
  try {
    const res = await api<{ data: Widget }>(`/api/widgets/${id}`)
    widget.value = res.data
  } catch {
    // A missed refresh just means a slightly stale card until the next update.
  }
}

const onWidgetUpdated = (ref_: { id: number }) => {
  if (ref_.id === widget.value?.id) void refresh(ref_.id)
}

let channel: any = null

onMounted(async () => {
  try {
    const res = await api<{ data: Widget }>(`/api/channels/${props.channelId}/widgets/ensure`, {
      method: 'POST',
      body: { type: props.type },
    })
    widget.value = res.data
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not open this app.'
    return
  }

  if (!echo) return
  channel = echo.private(`channel.${props.channelId}`)
  // Scoped handler, removed by reference on teardown: the timeline (useMessages) listens for
  // this same event on this same channel object, and a bare stopListening would kill its
  // handler too.
  channel.listen('.WidgetUpdated', onWidgetUpdated)
})

onBeforeUnmount(() => {
  channel?.stopListening('.WidgetUpdated', onWidgetUpdated)
  channel = null
})
</script>

<template>
  <div class="min-h-0 flex-1 overflow-auto p-3">
    <p v-if="error" class="flex items-start gap-2 text-sm text-destructive">
      <AlertCircle class="mt-px h-4 w-4 flex-none" /> {{ error }}
    </p>
    <!-- Held until the widget resolves, so the tab doesn't flash empty on open. -->
    <div v-else-if="!widget" class="h-24 animate-pulse rounded-lg bg-muted" />
    <WidgetCard v-else :widget="widget" />
  </div>
</template>
