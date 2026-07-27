<script setup lang="ts">
import { Maximize, Minimize, ScreenShare, Users, VolumeX, Volume2, X } from 'lucide-vue-next'
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

const stageEl = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)
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
      <!-- Whatever's being shared near you. -->
      <section v-if="stage" class="flex shrink-0 flex-col gap-1.5">
        <div
          ref="stageEl"
          class="group relative overflow-hidden bg-black"
          :class="isFullscreen ? 'h-screen w-screen' : 'aspect-video rounded-lg border'"
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

          <button
            v-if="stage.key !== 'self' && canFullscreen"
            type="button"
            class="absolute right-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
            :title="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
            @click="toggleFullscreen"
          >
            <Minimize v-if="isFullscreen" class="h-3.5 w-3.5" />
            <Maximize v-else class="h-3.5 w-3.5" />
          </button>
        </div>

        <div class="flex items-center gap-1.5">
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
        <div v-if="sharers.length > 1" class="flex flex-wrap gap-1">
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
          :can-moderate="canModerate"
          @toggle-mute="togglePeerMute(peer.id)"
          @set-volume="setPeerVolume(peer.id, $event)"
          @set-screen-volume="setPeerScreenVolume(peer.id, $event)"
          @toggle-screen-mute="togglePeerScreenMute(peer.id)"
          @watch="watching = watching === peer.id ? null : peer.id"
          @disconnect="disconnectUser(peer.id)"
          @force-mute="muteUser(peer.id, $event)"
        />
      </div>

      <p v-if="!nearby.length" class="px-1 py-2 text-center text-[11px] leading-snug text-muted-foreground">
        Nobody's near you yet. Walk over to somebody and their camera, screen and volume
        controls turn up here.
      </p>
    </div>
  </aside>
</template>
