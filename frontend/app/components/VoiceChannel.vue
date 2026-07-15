<script setup lang="ts">
import {
  ChevronDown,
  ChevronUp,
  Headphones,
  HeadphoneOff,
  Loader2,
  Mic,
  MicOff,
  PhoneOff,
  ScreenShare,
  ScreenShareOff,
  Video,
  VideoOff,
  Volume2,
} from 'lucide-vue-next'
import type { Channel, Peer } from '~/types'
import { Button } from '~/components/ui/button'

/**
 * The call, as a stage above the channel's timeline.
 *
 * A voice channel here is a text channel with a call attached — the same thing Discord
 * means by text-in-voice — so this deliberately isn't a page. It sits on top of the very
 * same message list, composer, threads and read receipts every other channel gets, none of
 * which needed a line of special-casing to work here. It collapses, because once you're
 * talking the conversation usually matters more than a wall of your friends' initials.
 *
 * It serves a DM and a group chat unchanged, because a chat's call is the same call: the
 * mesh, the signalling and the roster are all addressed by channel id and neither knows
 * nor cares what the channel belongs to. Only the manners differ, which is what the props
 * below are for — a voice channel is a room that stands there saying "nobody's in voice
 * yet", whereas a chat with no call happening should be showing you the conversation, not
 * an empty stage. Hence `quietWhenEmpty`.
 */
const props = withDefaults(defineProps<{
  channel: Channel
  /** Render nothing at all when there's no call and you're not in one. */
  quietWhenEmpty?: boolean
  joinLabel?: string
}>(), {
  quietWhenEmpty: false,
  joinLabel: 'Join voice',
})

const { user } = useAuth()
const { participantsIn } = useVoiceRoster()
const {
  channelId,
  status,
  error,
  peers,
  selfMuted,
  selfDeafened,
  selfSpeaking,
  screenStream,
  cameraStream,
  isSharing,
  isCameraOn,
  connect,
  disconnect,
  toggleMute,
  toggleDeafen,
  togglePeerMute,
  setPeerVolume,
  toggleScreenShare,
  toggleCamera,
} = useVoice()

/** Are we in *this* channel's call? You can be in another one and just reading this one. */
const here = computed(() => channelId.value === props.channel.id && status.value !== 'idle')
const connecting = computed(() => here.value && status.value === 'connecting')

/** Before you join, who's already in there — straight from the sidebar's roster. */
const waiting = computed(() => participantsIn(props.channel.id))

/** A chat with no call going shows the conversation, not an empty stage. */
const hidden = computed(() => props.quietWhenEmpty && !here.value && !waiting.value.length)

const collapsed = ref(false)

/** A tile for yourself, so the grid is uniform and your own mic and camera are visible. */
const selfPeer = computed<Peer>(() => ({
  id: user.value?.id ?? 0,
  name: user.value?.name ?? 'You',
  avatar: user.value?.avatar ?? null,
  // Your own camera, played straight back from the capture — it never goes near a peer
  // connection to get here, so your self-view is the one picture in the call with no
  // latency at all.
  camera: cameraStream.value,
  screen: null,
  connection: 'connected',
  speaking: selfSpeaking.value,
  muted: selfMuted.value,
  deafened: selfDeafened.value,
  screenSharing: isSharing.value,
  cameraOn: isCameraOn.value,
  localMuted: false,
  volume: 1,
}))

// --- the screen-share stage ---

const watching = ref<number | 'self' | null>(null)

const sharers = computed(() => {
  const list: { key: number | 'self', name: string, stream: MediaStream | null }[] = []

  if (isSharing.value) list.push({ key: 'self', name: 'Your screen', stream: screenStream.value })
  for (const peer of peers.value) {
    if (peer.screenSharing && peer.screen) list.push({ key: peer.id, name: peer.name, stream: peer.screen })
  }

  return list
})

const stage = computed(() => sharers.value.find(s => s.key === watching.value) ?? null)

// Follow whoever starts sharing, and step off the stage when they stop. A screen going up
// is also the one moment the call is worth more room than the chat.
watch(sharers, (list) => {
  if (list.length) collapsed.value = false
  if (watching.value !== null && list.some(s => s.key === watching.value)) return
  watching.value = list[0]?.key ?? null
}, { immediate: true })

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <section v-if="!hidden" class="shrink-0 border-b bg-muted/20">
    <!-- Not in the call: a slim bar with whoever is, and a way in. -->
    <div v-if="!here" class="flex items-center gap-3 px-4 py-2">
      <Volume2 class="h-4 w-4 shrink-0 text-muted-foreground" />

      <template v-if="waiting.length">
        <div class="flex -space-x-2">
          <div
            v-for="p in waiting"
            :key="p.user.id"
            class="grid h-7 w-7 place-items-center rounded-full border-2 border-background bg-secondary text-[10px] font-semibold text-secondary-foreground"
            :title="p.user.name"
          >
            <img v-if="p.user.avatar" :src="p.user.avatar" :alt="p.user.name" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initials(p.user.name) }}</span>
          </div>
        </div>
        <span class="truncate text-xs text-muted-foreground">
          {{ waiting.length === 1 ? `${waiting[0]!.user.name} is` : `${waiting.length} people are` }} in the call
        </span>
      </template>
      <span v-else class="text-xs text-muted-foreground">Nobody's in voice yet.</span>

      <Button size="sm" class="ml-auto gap-2" @click="connect(channel.id)">
        <Volume2 class="h-4 w-4" /> {{ joinLabel }}
      </Button>
    </div>

    <template v-else>
      <div class="flex items-center gap-2 px-4 py-2">
        <span
          class="flex items-center gap-1.5 text-xs font-medium"
          :class="connecting ? 'text-amber-500' : 'text-green-600 dark:text-green-400'"
        >
          <Loader2 v-if="connecting" class="h-3.5 w-3.5 animate-spin" />
          <Volume2 v-else class="h-3.5 w-3.5" />
          {{ connecting ? 'Connecting…' : `Voice connected · ${peers.length + 1}` }}
        </span>

        <button
          type="button"
          class="ml-auto flex items-center gap-1 rounded px-2 py-0.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
          :title="collapsed ? 'Show the call' : 'Hide the call and make room for the chat'"
          @click="collapsed = !collapsed"
        >
          <ChevronDown v-if="collapsed" class="h-3.5 w-3.5" />
          <ChevronUp v-else class="h-3.5 w-3.5" />
          {{ collapsed ? 'Show' : 'Hide' }}
        </button>
      </div>

      <div v-if="!collapsed" class="flex flex-col gap-3 px-4 pb-3">
        <!-- Someone's screen, if anyone is sharing one. -->
        <section v-if="stage" class="flex flex-col gap-1.5">
          <div class="aspect-video max-h-[45vh] overflow-hidden rounded-lg border bg-black">
            <VoiceVideo :stream="stage.stream" />
          </div>
          <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground">{{ stage.name }}</span>
            <!-- More than one screen going: pick which one you're watching. -->
            <div v-if="sharers.length > 1" class="ml-auto flex gap-1">
              <button
                v-for="s in sharers"
                :key="String(s.key)"
                type="button"
                class="rounded px-2 py-0.5 text-xs transition"
                :class="s.key === watching ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/70'"
                @click="watching = s.key"
              >
                {{ s.name }}
              </button>
            </div>
          </div>
        </section>

        <div class="grid max-h-[38vh] grid-cols-[repeat(auto-fill,minmax(170px,1fr))] gap-2 overflow-y-auto">
          <VoiceTile
            :peer="selfPeer"
            self
            :speaking="selfSpeaking"
            :muted="selfMuted"
            :sharing="isSharing"
            :watching="watching === 'self'"
            @watch="watching = 'self'"
          />
          <VoiceTile
            v-for="peer in peers"
            :key="peer.id"
            :peer="peer"
            :speaking="peer.speaking"
            :muted="peer.muted"
            :sharing="peer.screenSharing"
            :watching="watching === peer.id"
            @toggle-mute="togglePeerMute(peer.id)"
            @set-volume="setPeerVolume(peer.id, $event)"
            @watch="watching = peer.id"
          />
        </div>
      </div>

      <!-- Controls stay put whether or not the tiles are showing. -->
      <div class="flex items-center justify-center gap-2 border-t px-4 py-2">
        <Button
          :variant="selfMuted ? 'destructive' : 'secondary'"
          size="icon"
          :title="selfMuted ? 'Unmute your microphone' : 'Mute your microphone'"
          @click="toggleMute"
        >
          <MicOff v-if="selfMuted" class="h-4 w-4" />
          <Mic v-else class="h-4 w-4" />
        </Button>

        <Button
          :variant="selfDeafened ? 'destructive' : 'secondary'"
          size="icon"
          :title="selfDeafened ? 'Undeafen — hear everyone again' : 'Deafen — silence everyone'"
          @click="toggleDeafen"
        >
          <HeadphoneOff v-if="selfDeafened" class="h-4 w-4" />
          <Headphones v-else class="h-4 w-4" />
        </Button>

        <Button
          :variant="isCameraOn ? 'default' : 'secondary'"
          size="icon"
          :title="isCameraOn ? 'Turn your camera off' : 'Turn your camera on'"
          @click="toggleCamera"
        >
          <VideoOff v-if="isCameraOn" class="h-4 w-4" />
          <Video v-else class="h-4 w-4" />
        </Button>

        <Button
          :variant="isSharing ? 'default' : 'secondary'"
          size="sm"
          class="gap-2"
          :title="isSharing ? 'Stop sharing your screen' : 'Share your screen'"
          @click="toggleScreenShare"
        >
          <ScreenShareOff v-if="isSharing" class="h-4 w-4" />
          <ScreenShare v-else class="h-4 w-4" />
          {{ isSharing ? 'Stop sharing' : 'Share screen' }}
        </Button>

        <Button variant="destructive" size="icon" title="Leave the call" @click="disconnect">
          <PhoneOff class="h-4 w-4" />
        </Button>
      </div>
    </template>

    <p v-if="status === 'error' && error" class="border-t px-4 py-2 text-xs text-destructive">
      {{ error }}
    </p>
  </section>
</template>
