<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { Maximize2, Minimize2, PictureInPicture2, Tv, Users, Volume2, X } from 'lucide-vue-next'
import { watchKey } from '~/composables/useCallStage'
import type { Peer } from '~/types'

/**
 * The people half of a Side Space: everyone in earshot, their cameras, whatever screen is
 * being shared, and the volume of each.
 *
 * ## Why it filters, when `peers` looks like it already has
 *
 * A Side Space only connects you to people within `CONNECT_TILES`, so `peers` is roughly "people
 * near you" — and this component used to render it whole on the strength of that. Roughly isn't
 * near enough. The connect radius is deliberately *wider* than earshot (it pre-dials, so the first
 * word out of someone walking towards you isn't lost) and a sealed zone silences people at any
 * distance at all. Both leave you holding a live connection to somebody you cannot hear, and what
 * you got was their tile in the list, their camera playing and their screen taking the stage from
 * across the room — proximity that governed volume and nothing else.
 *
 * So the list is everyone {@link useVoice.outOfEarshot} says you can actually hear. The connection
 * carries on underneath, which is the point of having opened it early; what changes is that a
 * person you can't hear isn't shown either. Walk over and they turn up, whole.
 *
 * Every control here is {@link VoiceTile}'s or {@link useVoice}'s — per-peer mute, per-peer
 * volume, the separate volume for what they're sharing, the watch/stop-watching stage. None of
 * it is new; a Side Space simply had no surface for it until now.
 */
const props = defineProps<{
  canModerate?: boolean
  /**
   * A sheet over the room rather than a rail beside it — what a phone-width window asks for.
   *
   * The panel is unchanged in what it holds; every control here (a screen, its volume, each
   * person's volume, muting them for yourself, an owner's force-mute) matters as much on a phone
   * as anywhere, so none of it is dropped. What changes is that it's opened deliberately, fills
   * the room's area while it's up, and closes with the X — instead of being permanently squeezed
   * into a strip too short to show a screen and a slider at once.
   */
  sheet?: boolean
  /**
   * Put away — the rail is hidden, or the phone's sheet is closed.
   *
   * The panel stays mounted when it's stowed rather than being torn down, which is what lets a
   * screen you're watching keep playing over the room after you've closed the panel you started
   * watching it from. Everything else in here is hidden by the parent's `v-show`; the stage is
   * teleported out, so it survives.
   */
  stowed?: boolean
}>()

const emit = defineEmits<{ close: [], resize: [PointerEvent] }>()

const { user } = useAuth()
const {
  peers,
  selfSpeaking,
  micOpen,
  selfDeafened,
  screenStream,
  cameraStream,
  isSharing,
  isCameraOn,
  isAudioSharing,
  togglePeerMute,
  setPeerVolume,
  setPeerScreenVolume,
  togglePeerScreenMute,
  disconnectUser,
  muteUser,
  outOfEarshot,
} = useVoice()

const { liveIds } = useSpaceProximity()

/**
 * Everyone in earshot — the only people this panel shows anything about.
 *
 * One computed, used everywhere below in place of `peers`, so there's a single place where "near
 * enough to be in the room with you" is decided and no surface can quietly forget to ask.
 *
 * Whoever is live on a stage comes first. They're in earshot by the same rule as everybody else
 * (the room hears them, so they aren't out of it), but a rail ordered by nothing in particular
 * would bury the person currently talking to the entire room somewhere below the people stood
 * next to you — and their tile is the one you want a camera in.
 */
const nearby = computed(() => {
  const live = liveIds.value

  return peers.value
    .filter(p => !outOfEarshot(p))
    .sort((a, b) => Number(live.includes(b.id)) - Number(live.includes(a.id)))
})

/**
 * Whose chat you've stopped seeing over their head, and the switch for it.
 *
 * Belongs on this panel rather than on the room because it's the same kind of decision as the
 * volume slider beside it — about one person, yours alone, and never sent anywhere. The
 * everybody-at-once version is in the stage's own menu. See useSpaceChatBubbles.
 */
const { isMuted: bubblesMutedFor, toggleMuted: toggleBubbleMute } = useSpaceChatBubbles()


/**
 * Watch the screen over the room instead of inside this rail.
 *
 * A rail is sized for faces and sliders; a shared screen in one is unreadable, and the only way
 * up used to be fullscreen — which hides the room, so you stop being able to see who's near you
 * or walk anywhere. Theatre is the middle: the stage is teleported into
 * `#space-screen-theater` (see {@link SideSpaceStage}), spanning the room's width at the top of
 * it, with the lower part of the map still visible underneath.
 *
 * On by default, because somebody sharing has said "look at this" and a stamp in a rail is not
 * looking. Remembered, because whoever prefers the rail prefers it every time.
 *
 * On a phone it depends on whether the sheet is up. An open sheet *is* the room's whole area, so
 * there is nothing to teleport over and the screen is already full-width inside it; teleporting
 * would only put it on top of the very list you opened. Close the sheet to walk and it moves out
 * over the room, which is the case a phone actually needed — the sheet used to be all-or-nothing,
 * so watching meant standing still behind a panel covering everything.
 *
 * Off on the first render regardless, since `<Teleport>` resolves its target selector
 * immediately and the room's element doesn't exist during SSR or before this panel's own mount.
 */
const wantsTheater = useLocalStorage('space-screen-theater', true)
const mounted = ref(false)
const theater = computed(() => mounted.value && wantsTheater.value && (!props.sheet || !!props.stowed))

/**
 * Theatre, out of the way: a thumbnail in the room's bottom corner rather than a band across
 * its top. For when what you're watching matters but the half of the map behind it matters more
 * — someone's walking over, or you are. Still the same stage, so it keeps playing and its
 * controls keep working; the labels and the sharer picker are what go, being unreadable at that
 * size anyway.
 *
 * Not remembered: unlike the rail-or-room choice, this is about the next thirty seconds.
 */
const minimized = ref(false)

/** A tile for yourself, so the grid is uniform and your own mic and camera are visible. */
const selfPeer = computed<Peer>(() => ({
  id: user.value?.id ?? 0,
  name: user.value?.name ?? 'You',
  avatar: user.value?.avatar ?? null,
  // Played straight back from the capture rather than round-tripped through a peer connection,
  // so your self-view is the one picture in the room with no latency.
  camera: cameraStream.value,
  screen: null,
  connection: 'connected',
  speaking: selfSpeaking.value,
  muted: !micOpen.value,
  deafened: selfDeafened.value,
  screenSharing: isSharing.value,
  cameraOn: isCameraOn.value,
  audioSharing: isAudioSharing.value,
  localMuted: false,
  volume: 1,
  screenVolume: 1,
  screenMuted: false,
  proximity: 1,
}))

/**
 * The big screen: which screen or which face is on it, and how it got there.
 *
 * Only people in earshot are eligible, which is the room's whole rule restated — you can put
 * somebody's face on your main screen for the same reason you can hear them, and a stranger
 * three rooms away is no more watchable than they are audible. Whoever is live on a Side Space
 * stage is passed as `priority`, so stepping up in front of the room claims an empty stage the
 * way pressing Share does. See useCallStage.
 */
const { watchables, watching, stages, stage, stageFull, isWatching, screenPeerFor, toggleWatch, clearWatching } = useCallStage({
  peers: () => nearby.value,
  self: () => ({
    sharing: isSharing.value,
    screen: screenStream.value,
    cameraOn: isCameraOn.value,
    camera: cameraStream.value,
  }),
  priority: () => liveIds.value,
})

/** Anybody sharing sound with no picture — there's nothing to watch, so they get a mention. */
const audioSharers = computed(() => nearby.value.filter(p => p.audioSharing && !p.screenSharing))

// A screen you shrank away and then closed shouldn't come back shrunk when the next one starts:
// the next share is a fresh "look at this". Only once the stage empties completely — shrinking is
// about the band over the room, and the band is still there while anything is on it.
watch(() => stages.value.length, n => { if (!n) minimized.value = false })

/**
 * How the tiles are laid out over the room.
 *
 * A single screen keeps the whole band, and beyond that it's two columns. The band is 62% of the
 * room's height, so four tiles in it are small — but they're four things you asked to see at once,
 * and the alternative (a column of them, most below the fold) is worse. Fullscreen and the rail
 * are both a click away for the one you actually want to read.
 */
const stageColumns = computed(() => stages.value.length > 1 ? 'grid-cols-2' : 'grid-cols-1')

/**
 * Whether fullscreen exists here at all.
 *
 * iOS' web view has no Element.requestFullscreen — `document.fullscreenEnabled` is how it says so
 * — and calling it there rejects into nothing, so the button is hidden rather than left to fail.
 * Asked once, here, and handed to every tile; the tiles do the toggling, since with a grid up
 * "fullscreen" means one particular screen. See CallStageTile.
 */
const canFullscreen = ref(false)

onMounted(() => {
  mounted.value = true
  canFullscreen.value = !!document.fullscreenEnabled && typeof Element.prototype.requestFullscreen === 'function'
})
</script>

<template>
  <aside
    class="relative flex min-h-0 flex-col"
    :class="sheet ? 'border-l-0 bg-background shadow-xl' : 'border-l bg-card/40'"
  >
    <!-- Trade width with the room. A rail only: a sheet is as wide as the window. -->
    <ResizeHandle v-if="!sheet" edge="left" @resize="emit('resize', $event)" />
    <header
      class="flex shrink-0 items-center justify-between gap-2 border-b px-2.5"
      :class="sheet ? 'h-11' : 'h-9'"
    >
      <span class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
        <Users class="h-3.5 w-3.5" />
        In earshot
        <span class="tabular-nums">{{ nearby.length }}</span>
      </span>
      <!-- Gone entirely, not folded to a stub: the header button that opened it brings it back. -->
      <button
        type="button"
        class="rounded p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
        :aria-label="sheet ? 'Close' : 'Hide the people panel'"
        :title="sheet ? 'Close' : 'Hide the people panel'"
        @click="emit('close')"
      >
        <X class="h-4 w-4" />
      </button>
    </header>

    <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-2">
      <!-- A screen is showing, but over the room rather than in here. Said so, with the way back. -->
      <button
        v-if="stage && theater"
        type="button"
        class="flex shrink-0 items-center gap-1.5 rounded-md border bg-muted/40 px-2 py-1.5 text-left text-[11px] text-muted-foreground transition hover:bg-muted"
        title="Watch it in this panel instead"
        @click="wantsTheater = false"
      >
        <Tv class="h-3.5 w-3.5 shrink-0" />
        <span class="min-w-0 flex-1 truncate">
          {{ stages.length > 1 ? `${stages.length} screens` : stage?.name }} —
          {{ minimized ? 'in the corner of the room' : 'showing over the room' }}
        </span>
        <PictureInPicture2 class="h-3.5 w-3.5 shrink-0" />
      </button>

      <!--
        Whatever's being shared near you.

        Teleported over the room when theatre is on, which is where it's actually legible; the
        markup is the same either way, so every control (stop watching, fullscreen, remote
        control, the screen's own volume) comes along instead of being a fullscreen-only
        privilege. `v-if` sits on the section rather than the Teleport so an absent target is
        never asked for.
      -->
      <Teleport to="#space-screen-theater" :disabled="!theater">
        <section
          v-if="stages.length"
          class="flex flex-col gap-1.5"
          :class="theater
            ? (minimized
              ? 'pointer-events-auto absolute bottom-2 right-2 w-[min(60%,16rem)] rounded-lg border bg-background/95 p-1 shadow-xl backdrop-blur'
              : 'pointer-events-auto absolute inset-x-0 top-0 h-[62%] max-h-full border-b bg-background/95 p-2 shadow-xl backdrop-blur')
            : 'shrink-0'"
        >
          <!-- Everything you're watching near you. Over the room it stretches to fill the band;
               in the rail each tile keeps its own 16:9 and the column scrolls. -->
          <div
            class="grid gap-1.5"
            :class="[stageColumns, theater && !minimized ? 'min-h-0 flex-1' : '']"
          >
            <CallStageTile
              v-for="w in stages"
              :key="w.key"
              :watchable="w"
              :screen-peer="screenPeerFor(w)"
              :compact="minimized || !theater"
              :no-fullscreen="!canFullscreen"
              @close="toggleWatch(w.owner, w.kind)"
            >
              <!-- Where the stage lives, rather than what's on it — so these stay here rather
                   than moving into the tile. Only on the first tile: one band moves and shrinks
                   as a whole, and four copies of the same two buttons is just clutter. -->
              <template v-if="w.key === stages[0]?.key" #actions="{ fullscreen }">
                <!-- Out of the way without being closed. Only over the room: in the rail the
                     screens are already as small as they get, with nowhere to go. -->
                <button
                  v-if="theater && !fullscreen"
                  type="button"
                  class="grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
                  :title="minimized ? 'Show it full size again' : 'Shrink it into the corner'"
                  @click="minimized = !minimized"
                >
                  <Maximize2 v-if="minimized" class="h-3.5 w-3.5" />
                  <Minimize2 v-else class="h-3.5 w-3.5" />
                </button>

                <!-- Between the rail and the room. Hidden while minimized (three buttons don't fit
                     across a thumbnail), while the sheet is up — a phone watching in the sheet has
                     nowhere else to put it — and in fullscreen, where it would mean nothing. -->
                <button
                  v-if="!sheet && !fullscreen && !minimized"
                  type="button"
                  class="grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
                  :title="theater ? 'Watch in the side panel' : 'Watch over the room'"
                  @click="wantsTheater = !wantsTheater"
                >
                  <PictureInPicture2 v-if="theater" class="h-3.5 w-3.5" />
                  <Tv v-else class="h-3.5 w-3.5" />
                </button>
              </template>
            </CallStageTile>
          </div>

          <!-- Illegible on a thumbnail, and every one of these controls is a tile or a button
               away in the panel. -->
          <div v-if="!minimized" class="flex shrink-0 flex-wrap items-center gap-1">
            <button
              v-if="stages.length > 1"
              type="button"
              class="shrink-0 rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition hover:bg-muted hover:text-foreground"
              title="Stop watching everything and stay in the room"
              @click="clearWatching()"
            >
              Clear
            </button>

            <!-- Everything worth looking at near you — screens and faces both. -->
            <CallStagePicker
              v-if="watchables.length > 1"
              compact
              :watchables="watchables"
              :watching="watching"
              :full="stageFull"
              @toggle="toggleWatch($event.owner, $event.kind)"
            />
          </div>
        </section>
      </Teleport>

      <!-- Sound with nothing to look at, so nobody is offered a screen that isn't coming. -->
      <p
        v-for="p in audioSharers"
        :key="`a-${p.id}`"
        class="flex shrink-0 items-center gap-1.5 rounded-md border bg-muted/40 px-2 py-1 text-[11px] text-muted-foreground"
      >
        <Volume2 class="h-3.5 w-3.5 shrink-0" />
        <span class="min-w-0 flex-1 truncate">{{ p.name }} is sharing sound</span>
      </p>

      <!-- Everyone in earshot. Your own tile first, so the grid never reflows around you. -->
      <!-- One column: the tiles carry full-width volume sliders, and two columns of those on a
           phone leaves each too short to aim a thumb at. -->
      <div class="grid shrink-0 grid-cols-1 gap-2">
        <VoiceTile
          :peer="selfPeer"
          self
          :speaking="selfSpeaking"
          :muted="!micOpen"
          :sharing="isSharing"
          :watching="isWatching(watchKey('self', 'screen'))"
          :pinned="isWatching(watchKey('self', 'camera'))"
          @watch="toggleWatch('self', 'screen')"
          @pin="toggleWatch('self', 'camera')"
        />
        <VoiceTile
          v-for="peer in nearby"
          :key="peer.id"
          :peer="peer"
          :speaking="peer.speaking"
          :muted="peer.muted"
          :sharing="peer.screenSharing"
          :watching="isWatching(watchKey(peer.id, 'screen'))"
          :pinned="isWatching(watchKey(peer.id, 'camera'))"
          can-mute-bubbles
          :bubbles-muted="bubblesMutedFor(peer.id)"
          :can-moderate="canModerate"
          @toggle-mute="togglePeerMute(peer.id)"
          @set-volume="setPeerVolume(peer.id, $event)"
          @set-screen-volume="setPeerScreenVolume(peer.id, $event)"
          @toggle-screen-mute="togglePeerScreenMute(peer.id)"
          @watch="toggleWatch(peer.id, 'screen')"
          @pin="toggleWatch(peer.id, 'camera')"
          @disconnect="disconnectUser(peer.id)"
          @force-mute="muteUser(peer.id, $event)"
          @toggle-bubbles="toggleBubbleMute(peer.id)"
        />
      </div>

      <p v-if="!nearby.length" class="px-1 py-2 text-center text-[11px] leading-snug text-muted-foreground">
        Nobody's near you yet. Walk over to somebody and their camera, screen and volume
        controls turn up here.
      </p>
    </div>
  </aside>
</template>
