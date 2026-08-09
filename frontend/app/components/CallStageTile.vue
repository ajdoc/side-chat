<script setup lang="ts">
import { Maximize, Minimize, ScreenShare, VolumeX, Volume2, X } from 'lucide-vue-next'
import type { Watchable } from '~/composables/useCallStage'
import type { Peer } from '~/types'

/**
 * One thing on the stage: a shared screen or a face, with everything you can do to it.
 *
 * ## Why it's a component now
 *
 * The stage held exactly one item, so a voice channel and a Side Space each inlined its picture
 * and its controls — twice over, and the two had already drifted (only one of them said whether
 * you could hand over the mouse). The stage now holds several at once, so the markup would have
 * had to become a `v-for` in both places, and a `v-for` over a hundred lines of duplicated
 * chrome is the point to stop duplicating it.
 *
 * Each tile owns its own fullscreen and its own reported dimensions, which is the other reason
 * this is a component: with a grid up, "fullscreen" means *this* screen, and remote control has
 * to map a click through the aspect of the picture it actually landed in.
 */
const props = defineProps<{
  watchable: Watchable
  /**
   * The peer whose screen this is, when it's somebody else's — see useCallStage.screenPeerFor.
   * Null for a face, and for your own screen: volume, mute and remote control are all meaningless
   * pointed at yourself.
   */
  screenPeer?: Peer | null
  /**
   * Small: a Side Space rail, or a thumbnail over the room. Drops the caption row and shrinks
   * the buttons — a slider is unusable at that size and the same control is a tile away.
   */
  compact?: boolean
  /** Hide the fullscreen button where the platform has no fullscreen (iOS' web view). */
  noFullscreen?: boolean
  /**
   * Extra classes for the picture itself — how tall the surface will let it get, which only the
   * surface knows: the same tile is a band across a chat pane, a rail in a Side Space, and a
   * quarter of a grid, and each has a different idea of how much room a screen may take before
   * it starts pushing the conversation off the page.
   */
  pictureClass?: string
}>()

const emit = defineEmits<{ close: [] }>()

const { setPeerScreenVolume, togglePeerScreenMute } = useVoice()

// Whether this machine could hand over the mouse at all — shown on your own share, so the answer
// arrives before someone asks rather than after. See useRemoteControl.
const { canGrantControl, grantBlockedReason } = useRemoteControl()

/**
 * Your own screen is a note, never played back live.
 *
 * A whole-screen or window capture that includes this very window is an endless hall of mirrors,
 * and in fullscreen it filled the display and flickered. You know what's on your screen — everyone
 * else sees the real thing on theirs.
 */
const selfScreen = computed(() => props.watchable.owner === 'self' && props.watchable.kind === 'screen')

/** The picture's true size. Remote control cannot map a click without it — see VoiceVideo. */
const dimensions = ref({ width: 0, height: 0 })
function onDimensions(width: number, height: number) {
  dimensions.value = { width, height }
}

const el = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)

async function toggleFullscreen() {
  if (!el.value || selfScreen.value) return

  try {
    if (document.fullscreenElement) await document.exitFullscreen()
    else await el.value.requestFullscreen()
  }
  catch {
    // Refused (a permissions policy, or a gesture the browser didn't count). Nothing to say — the
    // screen is still playing where it was, which is what you were watching it in.
  }
}

// Track it rather than assume: fullscreen can be left with Esc and with the browser's own
// controls, without our button ever being touched. Compared against this tile's element so one
// tile going fullscreen doesn't make every other tile think it did.
function onFullscreenChange() {
  isFullscreen.value = !!el.value && document.fullscreenElement === el.value
}
onMounted(() => document.addEventListener('fullscreenchange', onFullscreenChange))
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', onFullscreenChange))
</script>

<template>
  <div class="flex min-h-0 min-w-0 flex-col gap-1">
    <div
      ref="el"
      class="group relative min-h-0 overflow-hidden bg-black"
      :class="isFullscreen ? 'h-screen w-screen' : ['aspect-video rounded-lg border', pictureClass]"
    >
      <div v-if="selfScreen" class="grid h-full w-full place-items-center p-2 text-center text-white/70">
        <div class="flex flex-col items-center gap-1.5">
          <ScreenShare :class="compact ? 'h-6 w-6' : 'h-8 w-8'" />
          <p class="font-medium text-white" :class="compact ? 'text-xs' : 'text-sm'">You're sharing your screen</p>
          <p class="text-white/60" :class="compact ? 'text-[11px]' : 'text-xs'">Everyone else can see it.</p>
          <!-- Say now whether anyone could take the wheel, rather than at the moment someone asks
               and the Allow button turns out to be dead. -->
          <p
            v-if="!compact"
            class="max-w-xs text-xs"
            :class="canGrantControl ? 'text-white/60' : 'text-amber-300/80'"
          >
            {{ canGrantControl ? 'You can hand someone control if they ask.' : grantBlockedReason }}
          </p>
        </div>
      </div>
      <!-- Your own face is mirrored, like every self-view; nobody else's is. -->
      <VoiceVideo
        v-else
        :stream="watchable.stream"
        :class="watchable.owner === 'self' ? '-scale-x-100' : ''"
        @dimensions="onDimensions"
      />

      <!-- The remote-control input layer. Renders only while you actually hold control of *this*
           peer's screen, and sits above the hover controls so a click meant for their desktop
           can't land on our own fullscreen button. -->
      <RemoteControlSurface
        v-if="screenPeer"
        :peer-id="screenPeer.id"
        :video-width="dimensions.width"
        :video-height="dimensions.height"
      />

      <!-- Take this one off the stage, leaving the rest of the grid and the call alone. Re-watch
           from the owner's tile or the picker. -->
      <button
        type="button"
        class="absolute left-1.5 top-1.5 grid place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
        :class="compact ? 'h-7 w-7' : 'h-8 w-8'"
        :title="watchable.kind === 'camera' ? 'Take them off the stage' : 'Stop watching this screen'"
        @click="emit('close')"
      >
        <X :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
      </button>

      <div class="absolute right-1.5 top-1.5 flex items-center gap-1">
        <!-- Whatever the surface wants beside fullscreen: the Side Space's theatre and shrink
             buttons live here rather than in this component, being about where the stage is
             rather than about what's on it. -->
        <slot name="actions" :fullscreen="isFullscreen" />

        <button
          v-if="!selfScreen && !noFullscreen"
          type="button"
          class="grid place-items-center rounded-md bg-black/50 text-white opacity-0 reveal-touch transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
          :class="compact ? 'h-7 w-7' : 'h-8 w-8'"
          :title="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
          @click="toggleFullscreen"
        >
          <Minimize v-if="isFullscreen" :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
          <Maximize v-else :class="compact ? 'h-3.5 w-3.5' : 'h-4 w-4'" />
        </button>
      </div>

      <!-- Whose it is, on the picture itself. With several up, a caption under each tile would
           double the grid's height for a name; over the picture it costs nothing and stays
           readable in the corner of your eye while you're looking at another tile. -->
      <span class="pointer-events-none absolute bottom-1.5 left-1.5 max-w-[85%] truncate rounded bg-black/55 px-1.5 py-0.5 text-[11px] text-white/90">
        {{ watchable.name }}
      </span>
    </div>

    <!-- Controls that belong to this screen rather than to the person: whether you can drive it,
         and how loud it plays for you alone — kept apart from their voice so a loud clip can be
         turned down without quietening the person talking over it. -->
    <div v-if="screenPeer && !compact" class="flex min-w-0 items-center gap-1.5">
      <RemoteControlButton :peer-id="screenPeer.id" />

      <button
        type="button"
        class="shrink-0 rounded p-0.5 transition"
        :class="screenPeer.screenMuted ? 'text-destructive' : 'text-muted-foreground hover:text-foreground'"
        :title="screenPeer.screenMuted
          ? 'Hear this screen again'
          : 'Mute this screen\'s sound — you\'ll still hear everyone talking'"
        @click="togglePeerScreenMute(screenPeer.id)"
      >
        <VolumeX v-if="screenPeer.screenMuted" class="h-3.5 w-3.5" />
        <Volume2 v-else class="h-3.5 w-3.5" />
      </button>
      <input
        type="range"
        min="0"
        max="1"
        step="0.01"
        :value="screenPeer.screenVolume"
        :disabled="screenPeer.screenMuted"
        class="h-1 min-w-0 flex-1 cursor-pointer appearance-none rounded-full bg-muted accent-primary disabled:cursor-not-allowed disabled:opacity-40"
        :aria-label="`Shared screen volume for ${screenPeer.name}`"
        :title="screenPeer.screenMuted ? 'Screen sound: off' : `Screen sound: ${Math.round(screenPeer.screenVolume * 100)}%`"
        @input="setPeerScreenVolume(screenPeer.id, Number(($event.target as HTMLInputElement).value))"
      >
    </div>
  </div>
</template>
