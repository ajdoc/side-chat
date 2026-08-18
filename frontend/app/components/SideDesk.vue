<script setup lang="ts">
import { ExternalLink, Plus } from 'lucide-vue-next'
import type { SideDeskAppId } from '~/types'
import { channelPath as scopedChannelPath, channelStream as scopedChannelStream } from '~/lib/deskScope'

/**
 * The Side Desk — a tabbed workspace that hangs beside a chat and houses the *apps* a place
 * builds things with.
 *
 * It grew out of the whiteboard: the board became the first app among several, and the tab strip
 * is now a *catalogue* rather than four fixed tabs. Every interactive widget (Kanban, a poll,
 * the games) can be a full app here, and the workspace apps (board, notes, calendar) can be
 * cards on the Open Canvas — the two used to be separate lists of the same idea. See
 * {@link useDeskApps} for the registry and why an app and its widget can't fall out of sync.
 *
 * Which apps a surface shows is stored per surface and shared by everyone on it, so the strip
 * reads the same for all of them. The Open Canvas is pinned first and can't be removed: it's
 * where the other apps get placed.
 *
 * Surface-agnostic, the same way {@link Whiteboard} is: the host hands a REST base path and the
 * private stream this space lives on, so one component drives a channel's desk, a DM's and a
 * side chat's alike. Each app hangs its own endpoints off `basePath`. `canEdit` gates authoring;
 * when false apps are read-only and `readonlyHint` says why. The active tab is owned by the host
 * (so it can live in the URL), passed in and emitted back out.
 */
const props = defineProps<{
  basePath: string
  streamName: string
  canEdit: boolean
  activeApp: SideDeskAppId
  /**
   * The channel widgets resolve against. A channel's desk passes its own id; a side chat passes
   * its *parent* channel's, because widgets are channel-scoped — the same scoping its canvas
   * cards have always used.
   */
  channelId: number
  readonlyHint?: string
  /**
   * An editor to open on arrival, for the Calendar — see SideDeskPanel, which is where the URL
   * that carries it is read. Absent everywhere else, which is why it's optional.
   */
  compose?: { meeting: boolean, eventId: number | null }
}>()

const emit = defineEmits<{
  'update:activeApp': [SideDeskAppId]
  /** The `compose` intent has been acted on and should be cleared from the URL. */
  'composed': []
  /** Docs asking the host timeline to scroll to the message a chat file arrived in. */
  'jump': [messageId: number]
}>()

/**
 * Where the *channel-scoped* apps hang, which is not always this surface.
 *
 * The Tracker, the Polls wall and the Sticker Wall store their rows against a channel and have
 * no side-chat endpoints at all, so on a side chat's desk they resolve to its parent channel —
 * the same rule the widget apps have always followed. The rule itself (and why) lives in
 * lib/deskScope, where it's testable; this is only where it's applied.
 */
const channelPath = computed(() => scopedChannelPath(props.channelId))
const channelStream = computed(() => scopedChannelStream(props.channelId))

const { apps, toggle, enabled, open } = useDeskAppList(props.basePath, props.streamName)

open()

const managing = ref(false)

const tabs = computed(() => apps.value.map(id => deskApp(id)!).filter(Boolean))

/**
 * Fall back to the Canvas when the active tab isn't in the strip.
 *
 * Happens for real: someone else removes the app you're looking at, or a stale `?desk=` in the
 * URL names one this surface never had. Without this the desk renders nothing at all, which
 * reads as broken rather than as "that tab is gone".
 */
const resolved = computed<SideDeskAppId>(() =>
  enabled.value.has(props.activeApp) ? props.activeApp : 'canvas')

watch(resolved, id => {
  if (id !== props.activeApp) emit('update:activeApp', id)
})

/**
 * Pop the open app out into a floating window that outlives this panel — the same shelf a
 * widget card pops out onto, and the same affordance, so "float it" means one thing everywhere.
 *
 * Offered for the surface apps only: a widget app already has that button on its card, and the
 * window it opens is the widget's, not the desk's. Both windows render live state shared with
 * the tab they came from (see useSurfaceStore), so floating one isn't a snapshot — it's a
 * second view of the thing you were already looking at.
 */
const { open: openFloating, isSurfaceFloating } = useFloatingWindows()
const floatable = computed(() => !isWidgetApp(resolved.value))

function popOut() {
  const app = resolved.value
  if (isWidgetApp(app)) return

  openFloating({
    kind: 'surface',
    app,
    basePath: props.basePath,
    streamName: props.streamName,
    canEdit: props.canEdit,
    title: deskApp(app)?.label ?? 'App',
  })
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!--
      The tab strip.

      A horizontal scroller, not a wrapping row: the strip is open-ended now (a surface can add
      a dozen apps), and wrapping would push the workspace down by a line every few additions —
      worst exactly on the phone, where the desk covers the window and vertical space is the
      scarce thing. `scroll-strip` hides the scrollbar while keeping the finger-drag, which is
      the same trick the header action rows use.

      Tabs size to their own label rather than splitting the width evenly, so four apps don't
      stretch into a sparse row and twelve don't crush into unreadable slivers. The Add button
      is pinned outside the scroller so it's always reachable, however long the strip gets.
    -->
    <nav class="flex shrink-0 items-stretch border-b">
      <div class="scroll-strip flex min-w-0 flex-1">
        <button
          v-for="a in tabs"
          :key="a.id"
          type="button"
          class="flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm transition-colors"
          :class="resolved === a.id
            ? 'border-primary font-medium text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground'"
          @click="emit('update:activeApp', a.id)"
        >
          <component :is="a.icon" class="h-4 w-4 shrink-0" /> {{ a.label }}
        </button>
      </div>

      <!-- Float the open app: it leaves the panel and follows you around the app. -->
      <button
        v-if="floatable"
        type="button"
        class="flex shrink-0 items-center border-b-2 border-transparent px-2.5 text-muted-foreground transition-colors hover:text-foreground"
        :title="isSurfaceFloating(basePath, resolved) ? 'Already floating — brings it to the front' : 'Pop out into a floating window'"
        aria-label="Pop out into a floating window"
        @click="popOut"
      >
        <ExternalLink class="h-4 w-4" />
      </button>

      <button
        type="button"
        class="flex shrink-0 items-center gap-1 border-b-2 border-transparent px-2.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
        :disabled="!canEdit"
        title="Add or remove apps"
        aria-label="Add or remove apps"
        @click="managing = true"
      >
        <Plus class="h-4 w-4" />
      </button>
    </nav>

    <!-- Open Canvas — a free 2D board of cards: notes, checklists, widgets, and the workspace
         apps themselves. Keyed by base path so switching surfaces remounts. -->
    <SideDeskCanvas
      v-if="resolved === 'canvas'"
      :key="`${basePath}-canvas`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
    />

    <!-- Board — the shared whiteboard. -->
    <Whiteboard
      v-else-if="resolved === 'board'"
      :key="basePath"
      :base-path="`${basePath}/whiteboard`"
      :stream-name="streamName"
      :can-draw="canEdit"
      :readonly-hint="readonlyHint"
    />

    <!-- Notes — the surface's one shared markdown document. -->
    <SideDeskNotes
      v-else-if="resolved === 'notes'"
      :key="`${basePath}-notes`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
      :channel-id="channelId"
    />

    <!-- Calendar — the surface's shared schedule. -->
    <SideDeskCalendar
      v-else-if="resolved === 'calendar'"
      :key="`${basePath}-calendar`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
      :compose="compose"
      @composed="emit('composed')"
    />

    <!-- Tracker — projects and their tasks. The same component an app channel fills its whole
         window with; in a side panel it simply has less room. -->
    <TrackerApp
      v-else-if="resolved === 'tracker'"
      :key="`${channelPath}-tracker`"
      :base-path="channelPath"
      :stream-name="channelStream"
      :can-edit="canEdit"
      :channel-id="channelId"
    />

    <!-- Polls — the channel's wall of questions. -->
    <AppPollsApp
      v-else-if="resolved === 'polls'"
      :key="`${channelPath}-polls`"
      :base-path="channelPath"
      :stream-name="channelStream"
      :can-edit="canEdit"
    />

    <!-- Sticker Wall — the shared collage. -->
    <StickerWallApp
      v-else-if="resolved === 'stickers'"
      :key="`${channelPath}-stickers`"
      :base-path="channelPath"
      :stream-name="channelStream"
      :can-edit="canEdit"
    />

    <!-- Docs — a view-only shelf of uploaded PDF / Word / Excel files. -->
    <SideDeskDocs
      v-else-if="resolved === 'docs'"
      :key="`${basePath}-docs`"
      :base-path="basePath"
      :stream-name="streamName"
      :can-edit="canEdit"
      :readonly-hint="readonlyHint"
      @jump="emit('jump', $event)"
    />

    <!-- Everything else is a widget promoted to an app: one branch for all seven, because a
         widget tab is only ever "the channel's widget of this type, full width". -->
    <SideDeskWidgetApp
      v-else
      :key="`${basePath}-${resolved}`"
      :type="resolved as any"
      :channel-id="channelId"
    />

    <DeskAppManager
      v-model:open="managing"
      :enabled="enabled"
      @toggle="toggle"
    />
  </div>
</template>
