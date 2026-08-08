<script setup lang="ts">
import { ExternalLink, Hash, Info, LayoutPanelLeft, Map as MapIcon, MessagesSquare, Volume2, X } from 'lucide-vue-next'
import type { SplitPane } from '~/composables/useSplitView'
import { Button } from '~/components/ui/button'
import { provideLocalSurfaceRoute } from '~/composables/useSurfaceRoute'
import { provideChannelScope } from '~/composables/useChannelScope'

/**
 * The docked half of the split view: one channel, full height, beside the page.
 *
 * It renders the *real* {@link ChannelView} — the same timeline, threads, side chats, Info
 * and Side Desk the main column gets — rather than a cut-down copy. What makes that possible
 * is the surface route: the panels inside used to read `?sidechat=` straight off the URL, so
 * two of them on screen would have opened the same post in both and closed it in both. Here
 * they're given an in-memory one of their own instead ({@link provideLocalSurfaceRoute}), so
 * the two columns keep entirely separate ideas of what's open while sharing every component.
 *
 * That state is intentionally not linkable or persisted: a split is how you've arranged your
 * window, and the thing a URL should point at is the channel you're actually standing in.
 */
const props = defineProps<{ pane: SplitPane }>()
const emit = defineEmits<{ close: [], promote: [] }>()

// Seeded empty: a freshly docked channel shows its conversation, not somebody's last thread.
const { state: surfaceState, patch: patchSurface, replace: replaceSurface } = provideLocalSurfaceRoute()

/**
 * Open one of the channel's side surfaces *in the dock*.
 *
 * Deliberately the same rules the channel page follows: Threads stands beside whatever else is
 * up (a side chat keeps its column) and clears the full-column pair, while Info and the Side
 * Desk each replace the lot — they're full-column surfaces and there's no room in a dock for
 * two of those. Toggling: pressing the button a surface is already showing puts it away, which
 * matters more here than on the page, where the alternative is a column you can't dismiss
 * without a keyboard shortcut you'd have to know about.
 */
function openThreads() {
  if (surfaceState.value.threads || surfaceState.value.thread) {
    patchSurface({ threads: null, thread: null, from: null })
    return
  }
  patchSurface({ threads: '1', thread: null, from: null, info: null, desk: null })
}
function openInfo() {
  replaceSurface(surfaceState.value.info === '1' ? {} : { info: '1' })
}
function openDesk() {
  replaceSurface(surfaceState.value.desk ? {} : { desk: 'canvas' })
}

/**
 * A second set of the channel-scoped stores — side chats, threads, pins, forum groups.
 *
 * Those are deliberately shared `useState` so that the timeline, the panels and the header
 * badge can't disagree about one channel. With two channels on screen that same sharing
 * makes the later load overwrite the earlier one, and the dock ends up listing the main
 * column's side chats. See useChannelScope.
 */
provideChannelScope('split:')

// Re-seed when the docked channel changes, so a side chat opened in the old one doesn't
// carry over as a stale query key aimed at a channel that never had it.
watch(() => props.pane.channelId, () => { surfaceState.value = {} })

const { findChannel } = useServer()

/**
 * The full channel record, when we happen to have it.
 *
 * ChannelView needs the object, not just an id — but the sidebar can hand us a channel from
 * a server we aren't standing in, whose list isn't loaded. Rather than fetch (and hold) a
 * second channel list purely for the dock, the missing case is reconstructed from the pane
 * itself: the id, name and type are exactly what the drag carried, and they're all the
 * timeline reads. The fields left out are the ones only the *page* uses.
 */
const channel = computed(() => {
  const known = findChannel(props.pane.channelId)
  if (known) return known

  return {
    id: props.pane.channelId,
    server_id: null,
    conversation_id: null,
    // A pane always carries a discussion (that's what a timeline is), but we may not hold the
    // tree it came from — and nothing the pane draws needs to know which channel it's under.
    parent_id: null,
    name: props.pane.title,
    type: props.pane.type,
    position: 0,
  }
})

/**
 * A Side Space is not drawn in the dock, and neither is a call.
 *
 * Not a rendering limit — the stage folds to a phone's width happily. It's that both are
 * places you *are*, singular: one avatar with one position, one call with one roster. A
 * second stage beside the first would be a room you can see but aren't in, which is a new
 * idea rather than a new layout, and a second call surface is two rosters disagreeing about
 * where you're standing.
 *
 * So the dock shows the thing every channel type genuinely has — the timeline, and every
 * surface hanging off it — and offers the door for the rest.
 */
const isSpace = computed(() => props.pane.type === 'space')
const isVoice = computed(() => props.pane.type === 'voice')
</script>

<template>
  <section class="flex min-w-0 flex-1 flex-col border-l bg-background">
    <header class="flex h-9 shrink-0 items-center gap-2 border-b bg-muted/30 px-3">
      <MapIcon v-if="isSpace" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <Volume2 v-else-if="isVoice" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <Hash v-else class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      <span class="min-w-0 flex-1 truncate text-xs font-medium text-muted-foreground">Split view</span>

      <Button variant="ghost" size="sm" class="h-6 gap-1 px-2 text-xs text-muted-foreground" title="Swap this into the main column" @click="emit('promote')">
        <ExternalLink class="h-3 w-3" /> Open
      </Button>
      <button class="rounded p-1 text-muted-foreground hover:text-foreground" title="Close split view" aria-label="Close split view" @click="emit('close')">
        <X class="h-3.5 w-3.5" />
      </button>
    </header>

    <!--
      Keyed on the channel so switching what's docked rebuilds the view outright rather than
      trying to migrate a timeline, a subscription and five panels onto a different channel.
    -->
    <ChannelView
      :key="channel.id"
      :channel="channel"
      :title="pane.title"
      :prefix="isVoice || isSpace ? '' : '#'"
    >
      <template #icon>
        <MapIcon v-if="isSpace" class="h-5 w-5 shrink-0 text-muted-foreground" />
        <Volume2 v-else-if="isVoice" class="h-5 w-5 shrink-0 text-muted-foreground" />
        <Hash v-else class="h-5 w-5 shrink-0 text-muted-foreground" />
      </template>

      <template #actions>
        <!--
          The same row the channel page carries — the app launchers, this channel's surfaces,
          with Side Chats leading it. They were missing here for no better reason
          than that the page owns them and the dock isn't the page — so a docked channel could
          show a thread or the Side Desk (the panels read the pane's own surface route quite
          happily) but had no way to open one. These write that local route instead of
          navigating, which is the whole of the difference.

          Icons only, always: the dock is the narrow half by definition, and a row of labelled
          buttons plus a title doesn't fit in it even on a wide monitor — which is what the
          launchers' `icon-only` says, since the *viewport* here isn't narrow.
        -->
        <SideChatsButton :channel-id="channel.id" />
        <FavoriteAppButton :channel-id="channel.id" icon-only />
        <AppsButton :channel-id="channel.id" icon-only />
        <Button variant="ghost" size="sm" class="gap-2 px-2 text-muted-foreground" title="Threads" @click="openThreads">
          <MessagesSquare class="h-4 w-4" />
        </Button>
        <Button variant="ghost" size="sm" class="gap-2 px-2 text-muted-foreground" title="Side Desk" @click="openDesk">
          <LayoutPanelLeft class="h-4 w-4" />
        </Button>
        <Button variant="ghost" size="sm" class="gap-2 px-2 text-muted-foreground" title="Info" @click="openInfo">
          <Info class="h-4 w-4" />
        </Button>
      </template>

      <!-- The room / the call, replaced by the door to it. Occupies the same slot the real
           stage would, so the explanation sits exactly where the thing it explains isn't. -->
      <template v-if="isSpace || isVoice" #call>
        <div class="flex shrink-0 items-center gap-2 border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
          <MapIcon v-if="isSpace" class="h-3.5 w-3.5 shrink-0" />
          <Volume2 v-else class="h-3.5 w-3.5 shrink-0" />
          <span class="min-w-0 flex-1">
            {{ isSpace ? 'The room' : 'The call' }} stays in the main column — you can only be in one at a time.
          </span>
          <button class="shrink-0 font-medium text-primary hover:underline" @click="emit('promote')">Go there</button>
        </div>
      </template>
    </ChannelView>
  </section>
</template>
