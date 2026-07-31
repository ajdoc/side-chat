<script setup lang="ts">
import { useLocalStorage } from '@vueuse/core'
import { Maximize, Maximize2, Minimize, Minimize2, PictureInPicture2, ScreenShare, Tv, Users, VolumeX, Volume2, X } from 'lucide-vue-next'
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
  setWatchedScreen,
  disconnectUser,
  muteUser,
  outOfEarshot,
} = useVoice()

/**
 * Everyone in earshot — the only people this panel shows anything about.
 *
 * One computed, used everywhere below in place of `peers`, so there's a single place where "near
 * enough to be in the room with you" is decided and no surface can quietly forget to ask.
 */
const nearby = computed(() => peers.value.filter(p => !outOfEarshot(p)))

/**
 * Whose chat you've stopped seeing over their head, and the switch for it.
 *
 * Belongs on this panel rather than on the room because it's the same kind of decision as the
 * volume slider beside it — about one person, yours alone, and never sent anywhere. The
 * everybody-at-once version is in the stage's own menu. See useSpaceChatBubbles.
 */
const { isMuted: bubblesMutedFor, toggleMuted: toggleBubbleMute } = useSpaceChatBubbles()

const stageEl = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)

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
const watching = ref<number | 'self' | null>(null)

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

const sharers = computed(() => {
  const list: { key: number | 'self', name: string, stream: MediaStream | null }[] = []

  if (isSharing.value) list.push({ key: 'self', name: 'Your screen', stream: screenStream.value })
  for (const peer of nearby.value) {
    if (peer.screenSharing && peer.screen) list.push({ key: peer.id, name: peer.name, stream: peer.screen })
  }

  return list
})

const stage = computed(() => sharers.value.find(s => s.key === watching.value) ?? null)
const stagePeer = computed(() => nearby.value.find(p => p.id === watching.value) ?? null)

/** Anybody sharing sound with no picture — there's nothing to watch, so they get a mention. */
const audioSharers = computed(() => nearby.value.filter(p => p.audioSharing && !p.screenSharing))

/**
 * Start watching a screen as soon as one appears, and stop when the last one goes.
 *
 * Somebody in a room who starts sharing has walked over and said "look at this"; making you
 * hunt for a button first would be the wrong default. Once you've deliberately closed a
 * screen (`watching` → null) a *new* sharer can still claim the stage, which is what the
 * key-presence check below distinguishes from "you closed this one".
 */
watch(sharers, (now, before) => {
  const keys = now.map(s => s.key)
  const had = (before ?? []).map(s => s.key)

  if (watching.value !== null && !keys.includes(watching.value)) {
    watching.value = null
  }

  const arrived = keys.find(k => !had.includes(k))
  if (arrived !== undefined && watching.value === null) watching.value = arrived
})

// Keep the audio layer in step with the stage: only the screen you're actually watching gets
// to make a sound, so closing it silences it too.
watch(watching, key => setWatchedScreen(key), { immediate: true })

// A screen you shrank away and then closed shouldn't come back shrunk when the next one starts:
// the next share is a fresh "look at this".
watch(watching, key => { if (key === null) minimized.value = false })

/** The stage stream's true size — remote control maps clicks through it. See VoiceChannel. */
const stageDimensions = ref({ width: 0, height: 0 })
function onStageDimensions(width: number, height: number) {
  stageDimensions.value = { width, height }
}

/**
 * Fullscreen, where there is one.
 *
 * iOS' web view has no Element.requestFullscreen at all — `document.fullscreenEnabled` is how it
 * says so — and calling it there rejects into nothing. The button is hidden rather than left to
 * fail, and the call is guarded anyway because the check runs at mount and the DOM doesn't
 * promise the method will still be there.
 */
const canFullscreen = ref(false)

async function toggleFullscreen() {
  if (!stageEl.value || !canFullscreen.value) return

  try {
    if (document.fullscreenElement) await document.exitFullscreen()
    else await stageEl.value.requestFullscreen()
  }
  catch {
    // Refused (a permissions policy, or a gesture the browser didn't count). Nothing to say —
    // the screen is still playing in the panel, which is what you were watching it in.
  }
}

function onFullscreenChange() {
  isFullscreen.value = !!document.fullscreenElement
}

onMounted(() => {
  mounted.value = true
  canFullscreen.value = !!document.fullscreenEnabled && typeof Element.prototype.requestFullscreen === 'function'
  document.addEventListener('fullscreenchange', onFullscreenChange)
})
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', onFullscreenChange))
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
        <span class="min-w-0 flex-1 truncate">{{ stage.name }} — {{ minimized ? 'in the corner of the room' : 'showing over the room' }}</span>
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
          v-if="stage"
          class="flex flex-col gap-1.5"
          :class="theater
            ? (minimized
              ? 'pointer-events-auto absolute bottom-2 right-2 w-[min(60%,16rem)] rounded-lg border bg-background/95 p-1 shadow-xl backdrop-blur'
              : 'pointer-events-auto absolute inset-x-0 top-0 h-[62%] max-h-full border-b bg-background/95 p-2 shadow-xl backdrop-blur')
            : 'shrink-0'"
        >
          <div
            ref="stageEl"
            class="group relative overflow-hidden bg-black"
            :class="isFullscreen
              ? 'h-screen w-screen'
              : theater && !minimized ? 'min-h-0 flex-1 rounded-lg border' : 'aspect-video rounded-lg border'"
          >
            <!-- Your own screen is a note, never played back: capturing a window that contains
                 this one is an endless hall of mirrors. Everyone else sees the real thing. -->
            <div v-if="stage.key === 'self'" class="grid h-full w-full place-items-center p-2 text-center text-white/70">
              <div class="flex flex-col items-center gap-1.5">
                <ScreenShare class="h-6 w-6" />
                <p class="text-xs font-medium text-white">You're sharing your screen</p>
                <p class="text-[11px] text-white/60">Everyone near you can see it.</p>
              </div>
            </div>
            <VoiceVideo v-else :stream="stage.stream" @dimensions="onStageDimensions" />

            <!-- Remote control's input layer — inert unless you hold control of this screen. -->
            <RemoteControlSurface
              v-if="stagePeer"
              :peer-id="stagePeer.id"
              :video-width="stageDimensions.width"
              :video-height="stageDimensions.height"
            />

            <button
              type="button"
              class="absolute left-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
              title="Stop watching this screen"
              @click="watching = null"
            >
              <X class="h-3.5 w-3.5" />
            </button>

            <div class="absolute right-1.5 top-1.5 flex items-center gap-1">
              <!-- Out of the way without being closed. Only over the room: in the rail the screen
                   is already as small as it gets, and there'd be nowhere for it to go. -->
              <button
                v-if="theater && !isFullscreen"
                type="button"
                class="grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
                :title="minimized ? 'Show it full size again' : 'Shrink it into the corner'"
                @click="minimized = !minimized"
              >
                <Maximize2 v-if="minimized" class="h-3.5 w-3.5" />
                <Minimize2 v-else class="h-3.5 w-3.5" />
              </button>

              <!-- Between the rail and fullscreen. Hidden while minimized (three buttons don't fit
                   across a thumbnail), while the sheet is up — a phone watching in the sheet has
                   nowhere else to put it — and in fullscreen, where it would mean nothing. -->
              <button
                v-if="!sheet && !isFullscreen && !minimized"
                type="button"
                class="grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
                :title="theater ? 'Watch it in the side panel' : 'Watch it over the room'"
                @click="wantsTheater = !wantsTheater"
              >
                <PictureInPicture2 v-if="theater" class="h-3.5 w-3.5" />
                <Tv v-else class="h-3.5 w-3.5" />
              </button>

              <button
                v-if="stage.key !== 'self' && canFullscreen"
                type="button"
                class="grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
                :title="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
                @click="toggleFullscreen"
              >
                <Minimize v-if="isFullscreen" class="h-3.5 w-3.5" />
                <Maximize v-else class="h-3.5 w-3.5" />
              </button>
            </div>
          </div>

          <!-- Labels and sliders are illegible on a thumbnail; the volume is a person's tile away
               in the panel, and full size is one button away here. -->
          <div v-if="!minimized" class="flex shrink-0 items-center gap-1.5">
            <span class="min-w-0 flex-1 truncate text-[11px] text-muted-foreground">{{ stage.name }}</span>

            <!-- Ask to drive it. Only ever a peer's screen. -->
            <RemoteControlButton v-if="stagePeer" :peer-id="stagePeer.id" />

            <!-- How loud their share plays, for you alone — kept apart from their voice so a loud
                 clip can be turned down without quietening the person talking over it. -->
            <template v-if="stagePeer">
              <button
                type="button"
                class="shrink-0 rounded p-0.5 transition"
                :class="stagePeer.screenMuted ? 'text-destructive' : 'text-muted-foreground hover:text-foreground'"
                :title="stagePeer.screenMuted ? 'Hear this screen again' : 'Mute this screen\'s sound'"
                @click="togglePeerScreenMute(stagePeer.id)"
              >
                <VolumeX v-if="stagePeer.screenMuted" class="h-3.5 w-3.5" />
                <Volume2 v-else class="h-3.5 w-3.5" />
              </button>
              <input
                type="range"
                min="0"
                max="1"
                step="0.01"
                :value="stagePeer.screenVolume"
                :disabled="stagePeer.screenMuted"
                class="h-1 w-20 shrink-0 cursor-pointer appearance-none rounded-full bg-muted accent-primary disabled:cursor-not-allowed disabled:opacity-40"
                :aria-label="`Shared screen volume for ${stagePeer.name}`"
                @input="setPeerScreenVolume(stagePeer.id, Number(($event.target as HTMLInputElement).value))"
              >
            </template>
          </div>

          <!-- More than one screen going near you: pick. -->
          <div v-if="sharers.length > 1 && !minimized" class="flex shrink-0 flex-wrap gap-1">
            <button
              v-for="s in sharers"
              :key="String(s.key)"
              type="button"
              class="rounded px-1.5 py-0.5 text-[11px] transition"
              :class="s.key === watching ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/70'"
              @click="watching = s.key"
            >
              {{ s.name }}
            </button>
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
          :watching="watching === 'self'"
          @watch="watching = watching === 'self' ? null : 'self'"
        />
        <VoiceTile
          v-for="peer in nearby"
          :key="peer.id"
          :peer="peer"
          :speaking="peer.speaking"
          :muted="peer.muted"
          :sharing="peer.screenSharing"
          :watching="watching === peer.id"
          can-mute-bubbles
          :bubbles-muted="bubblesMutedFor(peer.id)"
          :can-moderate="canModerate"
          @toggle-mute="togglePeerMute(peer.id)"
          @set-volume="setPeerVolume(peer.id, $event)"
          @set-screen-volume="setPeerScreenVolume(peer.id, $event)"
          @toggle-screen-mute="togglePeerScreenMute(peer.id)"
          @watch="watching = watching === peer.id ? null : peer.id"
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
