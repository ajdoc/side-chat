<script setup lang="ts">
import { Maximize, Minimize, ScreenShare, Users, VolumeX, Volume2, X } from 'lucide-vue-next'
import type { Peer } from '~/types'

/**
 * The people half of a Side Space: everyone in earshot, their cameras, whatever screen is
 * being shared, and the volume of each.
 *
 * The set it renders needs no filtering, and that's the neat part. In a Side Space `peers` is
 * *already* "people near you" — the room only opens a connection to somebody within
 * CONNECT_TILES and drops them when they walk off (see useVoice's range gating). So the tiles
 * appear and disappear as people come and go from earshot without this component knowing that
 * proximity is a thing. It also means the media follows: you can only receive a camera or a
 * screen from somebody you're connected to, which is to say from somebody standing near you.
 *
 * Every control here is {@link VoiceTile}'s or {@link useVoice}'s — per-peer mute, per-peer
 * volume, the separate volume for what they're sharing, the watch/stop-watching stage. None of
 * it is new; a Side Space simply had no surface for it until now.
 */
defineProps<{ canModerate?: boolean }>()

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
} = useVoice()

const collapsed = ref(false)
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
  for (const peer of peers.value) {
    if (peer.screenSharing && peer.screen) list.push({ key: peer.id, name: peer.name, stream: peer.screen })
  }

  return list
})

const stage = computed(() => sharers.value.find(s => s.key === watching.value) ?? null)
const stagePeer = computed(() => peers.value.find(p => p.id === watching.value) ?? null)

/** Anybody sharing sound with no picture — there's nothing to watch, so they get a mention. */
const audioSharers = computed(() => peers.value.filter(p => p.audioSharing && !p.screenSharing))

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

async function toggleFullscreen() {
  if (!stageEl.value) return

  if (document.fullscreenElement) await document.exitFullscreen()
  else await stageEl.value.requestFullscreen()
}

function onFullscreenChange() {
  isFullscreen.value = !!document.fullscreenElement
}

onMounted(() => document.addEventListener('fullscreenchange', onFullscreenChange))
onBeforeUnmount(() => document.removeEventListener('fullscreenchange', onFullscreenChange))
</script>

<template>
  <aside class="flex min-h-0 flex-col border-l bg-card/40">
    <header class="flex h-9 shrink-0 items-center justify-between gap-2 border-b px-2.5">
      <span class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
        <Users class="h-3.5 w-3.5" />
        In earshot
        <span class="tabular-nums">{{ peers.length }}</span>
      </span>
      <button
        type="button"
        class="rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition hover:bg-muted hover:text-foreground"
        @click="collapsed = !collapsed"
      >
        {{ collapsed ? 'Show' : 'Hide' }}
      </button>
    </header>

    <div v-if="!collapsed" class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto p-2">
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
          <VoiceVideo v-else :stream="stage.stream" />

          <button
            type="button"
            class="absolute left-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
            title="Stop watching this screen"
            @click="watching = null"
          >
            <X class="h-3.5 w-3.5" />
          </button>

          <button
            v-if="stage.key !== 'self'"
            type="button"
            class="absolute right-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-md bg-black/50 text-white opacity-0 transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
            :title="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
            @click="toggleFullscreen"
          >
            <Minimize v-if="isFullscreen" class="h-3.5 w-3.5" />
            <Maximize v-else class="h-3.5 w-3.5" />
          </button>
        </div>

        <div class="flex items-center gap-1.5">
          <span class="min-w-0 flex-1 truncate text-[11px] text-muted-foreground">{{ stage.name }}</span>

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
          v-for="peer in peers"
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

      <p v-if="!peers.length" class="px-1 py-2 text-center text-[11px] leading-snug text-muted-foreground">
        Nobody's near you yet. Walk over to somebody and their camera, screen and volume
        controls turn up here.
      </p>
    </div>
  </aside>
</template>
