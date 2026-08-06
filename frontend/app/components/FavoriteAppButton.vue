<script setup lang="ts">
import { Button } from '~/components/ui/button'

/**
 * One app, one press — the app you reach for most, sitting in the channel header.
 *
 * Which app that is, is a local preference (see {@link useAppLauncher}); it defaults to Music
 * because that's the one people open again and again, and because the pin makes it the app
 * most worth a single press: the player follows you across channels, DMs and servers. Set a
 * different favourite from the star beside any app in the Apps menu next door.
 *
 * Deliberately *not* branded like {@link SideChatsButton}, which leads the row — that button is
 * the app's signature surface and should stay the loudest thing in it. This one follows as a
 * quiet icon with a label, collapsing to the icon alone on a phone like every other header
 * action.
 */
const props = defineProps<{
  channelId: number
  /** Force the icon-only form — the docked pane is narrow by definition, whatever the viewport is. */
  iconOnly?: boolean
}>()

const { favorite, launch } = useAppLauncher()
const { narrow } = useNavDrawer()
const { isPinned, widget: pinnedWidget } = useMusicPin()
const { isWidgetFloating, isSurfaceFloating } = useFloatingWindows()

const compact = computed(() => props.iconOnly || narrow.value)

const app = computed(() => deskApp(favorite.value) ?? deskApp('music')!)

/** Lit when the favourite is already up — pressing again just brings its window forward. */
const active = computed(() => {
  if (app.value.id === 'music') return !!pinnedWidget.value && isPinned(pinnedWidget.value.id)
  if (!isWidgetApp(app.value.id)) return isSurfaceFloating(`/api/channels/${props.channelId}`, app.value.id)
  // A widget's window is keyed by widget id, which we don't hold until it's been opened once —
  // so the widget family gets no lit state rather than a wrong one.
  return false
})

const busy = ref(false)
const error = ref('')

async function open() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  error.value = (await launch(app.value.id, props.channelId)) ?? ''
  busy.value = false
}
</script>

<template>
  <Button
    variant="ghost"
    size="sm"
    class="gap-2"
    :class="[active ? 'text-primary' : 'text-muted-foreground', compact && 'px-2']"
    :disabled="busy"
    :title="error || `${app.label} — your favourite app. Change it from Apps.`"
    :aria-label="`Open ${app.label}`"
    @click="open"
  >
    <component :is="app.icon" class="h-4 w-4" />
    <span v-if="!compact">{{ app.label }}</span>
  </Button>
</template>
