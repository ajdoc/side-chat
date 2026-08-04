<script setup lang="ts">
import { AtSign, Columns3, Film, Flag, Gamepad2, Hash, LayoutGrid, ListMusic, Palette, Spade, Users, Vote } from 'lucide-vue-next'
import type { FloatingWindow } from '~/composables/useFloatingWindows'

/**
 * The floating-window shelf, rendered once. Mounted by the app layout beside the music dock, so
 * — like the dock — every window it holds lives outside the routed page and survives navigation.
 * That's the whole point: a floated video keeps playing and a floated chat keeps updating while
 * you move around. Content is dispatched by kind; the chrome is shared ({@link FloatingFrame}).
 *
 * Rendered on every screen size, phones included. It used to be withheld below `md`, which had
 * the side effect of breaking the music pin outright there — pinning stubbed the timeline card
 * and set the pin, but with no shelf there was no player, so the song stopped and the card
 * stayed a stub across reloads. The frame adapts instead: see {@link FloatingFrame}.
 */
const { windows, hydrate } = useFloatingWindows()
const { restore: restorePinnedMusic } = useMusicPin()

const WIDGET_ICON: Record<string, any> = {
  music: ListMusic, video: Film, kanban: Columns3, poll: Vote, shooter: Gamepad2, racing: Flag, skribbl: Palette, poker: Spade,
}
const CONVERSATION_ICON = { channel: Hash, dm: AtSign, group: Users }

function widgetIcon(w: Extract<FloatingWindow, { kind: 'widget' }>) {
  return WIDGET_ICON[w.widgetType] ?? Gamepad2
}

/** Whatever icon the app already wears on its desk tab — one registry, one look. */
function surfaceIcon(w: Extract<FloatingWindow, { kind: 'surface' }>) {
  return deskApp(w.app)?.icon ?? LayoutGrid
}

/** The icon for any window, for the title bar and the minimized bubble alike. */
function iconFor(w: FloatingWindow) {
  return w.kind === 'widget' ? widgetIcon(w) : w.kind === 'surface' ? surfaceIcon(w) : CONVERSATION_ICON[w.icon]
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
          <component :is="iconFor(w)" class="h-3.5 w-3.5 shrink-0 text-primary" />
          <span class="truncate">{{ w.title }}</span>
        </template>

        <!-- The face of the minimized bubble: just the window's icon. -->
        <template #bubble>
          <component :is="iconFor(w)" class="h-5 w-5" />
        </template>

        <!-- The face of the compact music bar. Asked for by the frame only when it's actually
             wearing that shape, so nothing else pays for it. -->
        <template v-if="w.kind === 'widget' && w.widgetType === 'music'" #bar="{ open }">
          <FloatingMusicBar :open="open" />
        </template>

        <FloatingMusicContent v-if="w.kind === 'widget' && w.widgetType === 'music'" :win="w" />
        <FloatingWidgetContent v-else-if="w.kind === 'widget'" :win="w" />
        <FloatingSurfaceContent v-else-if="w.kind === 'surface'" :win="w" />
        <FloatingConversationContent v-else :channel-id="w.channelId" :title="w.title" />
      </FloatingFrame>
    </div>
  </ClientOnly>
</template>
