<script setup lang="ts">
import { AtSign, Columns3, Film, Flag, Gamepad2, Hash, ListMusic, Palette, Users, Vote } from 'lucide-vue-next'
import type { FloatingWindow } from '~/composables/useFloatingWindows'

/**
 * The floating-window shelf, rendered once. Mounted by the app layout beside the music dock, so
 * — like the dock — every window it holds lives outside the routed page and survives navigation.
 * That's the whole point: a floated video keeps playing and a floated chat keeps updating while
 * you move around. Content is dispatched by kind; the chrome is shared ({@link FloatingFrame}).
 */
const { windows, hydrate } = useFloatingWindows()
const { restore: restorePinnedMusic } = useMusicPin()

const WIDGET_ICON: Record<string, any> = {
  music: ListMusic, video: Film, kanban: Columns3, poll: Vote, shooter: Gamepad2, racing: Flag, skribbl: Palette,
}
const CONVERSATION_ICON = { channel: Hash, dm: AtSign, group: Users }

function widgetIcon(w: Extract<FloatingWindow, { kind: 'widget' }>) {
  return WIDGET_ICON[w.widgetType] ?? Gamepad2
}

// Only ever runs on the client (the shelf is client-only UI). Re-seat the saved windows, then
// re-pin whatever music was playing before a reload — pin() re-opens its window on the shelf.
onMounted(() => {
  hydrate()
  void restorePinnedMusic()
})
</script>

<template>
  <!-- A pass-through overlay: it catches nothing itself (windows re-enable pointer events), so
       the app underneath stays fully usable around the floating panels. -->
  <ClientOnly>
    <div class="pointer-events-none fixed inset-0 z-40">
      <FloatingFrame v-for="w in windows" :key="w.id" :win="w">
        <template #title>
          <template v-if="w.kind === 'widget'">
            <component :is="widgetIcon(w)" class="h-3.5 w-3.5 shrink-0 text-primary" />
            <span class="truncate">{{ w.title }}</span>
          </template>
          <template v-else>
            <component :is="CONVERSATION_ICON[w.icon]" class="h-3.5 w-3.5 shrink-0 text-primary" />
            <span class="truncate">{{ w.title }}</span>
          </template>
        </template>

        <!-- The face of the minimized bubble: just the window's icon. -->
        <template #bubble>
          <component :is="w.kind === 'widget' ? widgetIcon(w) : CONVERSATION_ICON[w.icon]" class="h-5 w-5" />
        </template>

        <FloatingMusicContent v-if="w.kind === 'widget' && w.widgetType === 'music'" :win="w" />
        <FloatingWidgetContent v-else-if="w.kind === 'widget'" :win="w" />
        <FloatingConversationContent v-else :win="w" />
      </FloatingFrame>
    </div>
  </ClientOnly>
</template>
