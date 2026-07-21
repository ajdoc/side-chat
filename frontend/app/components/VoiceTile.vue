<script setup lang="ts">
import { Loader2, MicOff, PhoneOff, ScreenShare, Volume2, VolumeX, WifiOff } from 'lucide-vue-next'
import type { Peer } from '~/types'

/**
 * One person in the call.
 *
 * The controls at the bottom are the local half of the design and only appear on other
 * people's tiles: they change how *your* speakers treat them, are never sent anywhere,
 * and are remembered for next time. The icons at the top are the opposite — the state
 * they chose and broadcast to everybody.
 */
const props = defineProps<{
  peer: Peer
  /** Your own tile: no volume slider (you can't turn yourself down), no connection state. */
  self?: boolean
  speaking: boolean
  muted: boolean
  sharing?: boolean
  /** Set when this tile's screen is the one on the stage. */
  watching?: boolean
}>()

const emit = defineEmits<{
  toggleMute: []
  setVolume: [value: number]
  watch: []
  disconnect: []
}>()

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

// What this person is called in the server or chat the call is in — see useNicknames.
// A call is inside a place like everything else, so a nickname holds here too.
const { nameFor } = useNicknames()
const peerName = computed(() => nameFor(props.peer))

const volumeLabel = computed(() => `${Math.round(props.peer.volume * 100)}%`)
</script>

<template>
  <div
    class="flex flex-col items-center gap-3 rounded-xl border bg-card p-4 transition-colors"
    :class="watching ? 'border-primary' : 'border-border'"
  >
    <div class="relative">
      <!--
        On camera: their face, in the same footprint the avatar occupied, so a tile doesn't
        jump about the grid when somebody turns a camera on mid-sentence.

        Your own is mirrored. Everyone expects to see themselves the way a mirror shows
        them, and nobody else's view is flipped — this is a fact about self-view, not about
        the stream, so it stops at the CSS.
      -->
      <div
        v-if="peer.cameraOn && peer.camera"
        class="h-20 w-20 overflow-hidden rounded-full ring-2 transition-all"
        :class="speaking ? 'ring-green-500' : 'ring-transparent'"
      >
        <VoiceVideo :stream="peer.camera" fit="cover" :class="self ? '-scale-x-100' : ''" />
      </div>

      <div
        v-else
        class="grid h-20 w-20 place-items-center rounded-full bg-secondary text-lg font-semibold text-secondary-foreground ring-2 transition-all"
        :class="speaking ? 'ring-green-500' : 'ring-transparent'"
      >
        <img v-if="peer.avatar" :src="peer.avatar" :alt="peerName" class="h-full w-full rounded-full object-cover">
        <span v-else>{{ initials(peerName) }}</span>
      </div>

      <!-- Their own choices, as broadcast to everyone. -->
      <span
        v-if="muted"
        class="absolute -bottom-1 -right-1 grid h-7 w-7 place-items-center rounded-full bg-destructive text-destructive-foreground"
        :title="self ? 'Your microphone is off' : `${peerName} muted their microphone`"
      >
        <MicOff class="h-3.5 w-3.5" />
      </span>

      <span
        v-if="!self && peer.connection === 'connecting'"
        class="absolute -left-1 -top-1 grid h-6 w-6 place-items-center rounded-full bg-muted text-muted-foreground"
        title="Connecting…"
      >
        <Loader2 class="h-3.5 w-3.5 animate-spin" />
      </span>
      <span
        v-else-if="!self && peer.connection === 'failed'"
        class="absolute -left-1 -top-1 grid h-6 w-6 place-items-center rounded-full bg-destructive text-destructive-foreground"
        title="Connection lost — retrying"
      >
        <WifiOff class="h-3.5 w-3.5" />
      </span>
    </div>

    <div class="flex min-w-0 items-center gap-1.5">
      <span class="truncate text-sm font-medium">{{ peerName }}</span>
      <span v-if="self" class="text-xs text-muted-foreground">(you)</span>
    </div>

    <button
      v-if="sharing"
      type="button"
      class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium transition"
      :class="watching ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/70'"
      @click="emit('watch')"
    >
      <ScreenShare class="h-3.5 w-3.5" />
      {{ watching ? 'Watching' : 'Watch screen' }}
    </button>

    <!--
      Yours alone. Muting Bob here silences him on your speakers and nowhere else — he is
      never told, and everyone else still hears him perfectly well.
    -->
    <div v-if="!self" class="flex w-full items-center gap-2 pt-1">
      <button
        type="button"
        class="shrink-0 rounded p-1 transition"
        :class="peer.localMuted ? 'text-destructive' : 'text-muted-foreground hover:text-foreground'"
        :title="peer.localMuted ? `Unmute ${peerName} for yourself` : `Mute ${peerName} for yourself`"
        @click="emit('toggleMute')"
      >
        <VolumeX v-if="peer.localMuted" class="h-4 w-4" />
        <Volume2 v-else class="h-4 w-4" />
      </button>

      <input
        type="range"
        min="0"
        max="1"
        step="0.01"
        :value="peer.volume"
        :disabled="peer.localMuted"
        class="h-1 w-full cursor-pointer appearance-none rounded-full bg-muted accent-primary disabled:cursor-not-allowed disabled:opacity-40"
        :aria-label="`Volume for ${peerName}`"
        :title="`${peerName}: ${volumeLabel}`"
        @input="emit('setVolume', Number(($event.target as HTMLInputElement).value))"
      >

      <span class="w-9 shrink-0 text-right text-[10px] tabular-nums text-muted-foreground">
        {{ peer.localMuted ? 'off' : volumeLabel }}
      </span>
    </div>

    <!--
      Unlike the volume and local-mute above — which change only what *you* hear — this
      turns the person out of the call for everybody.
    -->
    <button
      v-if="!self"
      type="button"
      class="flex w-full items-center justify-center gap-1.5 rounded-md border border-destructive/30 px-2 py-1 text-xs font-medium text-destructive transition hover:bg-destructive hover:text-destructive-foreground"
      :title="`Disconnect ${peerName} from the call`"
      @click="emit('disconnect')"
    >
      <PhoneOff class="h-3.5 w-3.5" />
      Disconnect
    </button>
  </div>
</template>
