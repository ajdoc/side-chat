<script setup lang="ts">
import {
  AudioLines,
  ChevronDown,
  ChevronUp,
  Headphones,
  HeadphoneOff,
  Loader2,
  Maximize,
  Minimize,
  Mic,
  MicOff,
  PhoneOff,
  ScreenShare,
  ScreenShareOff,
  UserX,
  Video,
  VideoOff,
  Volume2,
  VolumeX,
  X,
} from 'lucide-vue-next'
import type { Channel, Peer, VoiceParticipant } from '~/types'
import { Button } from '~/components/ui/button'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '~/components/ui/alert-dialog'

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
  notice,
  peers,
  selfMuted,
  selfDeafened,
  selfSpeaking,
  pushToTalk,
  pttHeld,
  micOpen,
  screenStream,
  cameraStream,
  isSharing,
  isCameraOn,
  isAudioSharing,
  connect,
  disconnect,
  toggleMute,
  toggleDeafen,
  togglePeerMute,
  setPeerVolume,
  setPeerScreenVolume,
  togglePeerScreenMute,
  setWatchedScreen,
  toggleScreenShare,
  toggleAudioShare,
  toggleCamera,
  disconnectUser,
  disconnectAll,
  muteUser,
} = useVoice()

/**
 * Whether you own the place this call is in — the one permission the call itself doesn't
 * grant everybody. Worked out here rather than passed in, because both pages that mount
 * this already hold the answer in shared state and neither should have to remember to
 * hand it over.
 *
 * A server asks its owner flag; a group chat asks who made it. A DM's `owner_id` is null,
 * so it falls out as false for both people, which is right — neither owns the other.
 */
const { server } = useServer()
const { conversation } = useConversation()

const canModerate = computed(() => {
  if (props.channel.server_id) {
    return server.value?.id === props.channel.server_id && !!server.value?.is_owner
  }

  return !!conversation.value
    && conversation.value.channel_id === props.channel.id
    && conversation.value.owner_id === user.value?.id
})

/** Are we in *this* channel's call? You can be in another one and just reading this one. */
const here = computed(() => channelId.value === props.channel.id && status.value !== 'idle')
const connecting = computed(() => here.value && status.value === 'connecting')

/** Before you join, who's already in there — straight from the sidebar's roster. */
const waiting = computed(() => participantsIn(props.channel.id))

/** A chat with no call going shows the conversation, not an empty stage. */
const hidden = computed(() => props.quietWhenEmpty && !here.value && !waiting.value.length)

const collapsed = ref(false)

/**
 * Who gets to decorate the room: the owner, and only in a server.
 *
 * Narrower than `canModerate` on purpose. Moderating a chat's call is something the person
 * who started the chat can do; an entrance effect is a property of a *venue*, and a DM isn't
 * one — the backend refuses it there in any case, so this just declines to offer a button
 * that would 403.
 */
const canSetEffects = computed(() => props.channel.server_id !== null && canModerate.value)

/**
 * Forcing someone out of the call is a moderator action you can't take back without them
 * rejoining, so it goes through a confirmation. `kickTarget` is who we're about to remove —
 * a single peer, or `'all'` for everyone but you — and `kickOpen` drives the dialog.
 *
 * These are kept separate on purpose: closing the dialog only flips `kickOpen`, so the
 * target is still readable when confirm fires, no matter which handler runs first.
 */
const kickTarget = ref<Peer | 'all' | null>(null)
const kickOpen = ref(false)

function askKick(target: Peer | 'all') {
  kickTarget.value = target
  kickOpen.value = true
}

function confirmKick() {
  const target = kickTarget.value
  if (target === 'all') disconnectAll()
  else if (target) disconnectUser(target.id)
  kickOpen.value = false
}

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
  // On push-to-talk your own tile shows the line, not the button — the same thing peers see.
  muted: !micOpen.value,
  deafened: selfDeafened.value,
  screenSharing: isSharing.value,
  cameraOn: isCameraOn.value,
  audioSharing: isAudioSharing.value,
  localMuted: false,
  volume: 1,
  screenVolume: 1,
  screenMuted: false,
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

// Keep the audio layer in step with the stage: only the screen you're actually watching
// gets to make a sound, so "Stop watching" (watching → null) silences it too.
watch(watching, key => setWatchedScreen(key), { immediate: true })

/** Your own screen is never put on the stage — see the template — so this is only ever a
 *  real peer, which is exactly who the shared-screen volume slider needs to address. */
const stagePeer = computed(() =>
  typeof watching.value === 'number' ? peers.value.find(p => p.id === watching.value) ?? null : null,
)

/**
 * The set of people sharing, as a stable string.
 *
 * `sharers` is rebuilt from `peers` on every roster tick — and `peers` is patched
 * constantly (the speaking rings alone repaint it many times a second). Watching `sharers`
 * directly therefore fires continuously, which is what made "Hide" spring straight back
 * open: the watcher kept re-running and kept forcing the pane visible. Reducing it to the
 * keys means the watcher only wakes when someone actually starts or stops sharing.
 */
const sharerKeys = computed(() => sharers.value.map(s => String(s.key)).join('|'))

watch(sharerKeys, (keys, prev) => {
  const current = keys ? keys.split('|') : []
  const previous = prev ? prev.split('|') : []

  // Auto-expand only for a *newly* started screen, so you can hide the pane afterwards and
  // it stays hidden. A screen going up is the one moment the call earns room over the chat.
  if (current.some(k => !previous.includes(k))) collapsed.value = false

  // Keep the stage on a screen that's still up; otherwise follow the first, or clear it.
  if (watching.value !== null && current.includes(String(watching.value))) return
  watching.value = sharers.value[0]?.key ?? null
}, { immediate: true })

// --- fullscreen ---

const stageEl = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)

function toggleFullscreen() {
  // Only a peer's screen is ever on the stage (your own shows a placeholder, never live
  // video — fullscreening your own capture is the hall-of-mirrors that flickered the app).
  if (watching.value === 'self') return
  if (document.fullscreenElement) void document.exitFullscreen().catch(() => {})
  else void stageEl.value?.requestFullscreen().catch(() => {})
}

// Track it rather than assume: the user can leave fullscreen with Esc, and the browser's
// own controls, without ever touching our button.
function onFullscreenChange() {
  isFullscreen.value = document.fullscreenElement === stageEl.value
}
onMounted(() => document.addEventListener('fullscreenchange', onFullscreenChange))
onUnmounted(() => document.removeEventListener('fullscreenchange', onFullscreenChange))

// Names follow whatever people are called in this server or chat — see useNicknames.
const { nameFor } = useNicknames()

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

// --- what the people already in there are up to, for the bar you see before joining ---

/** One waiting face's tooltip: their name, and anything worth knowing about them. */
function stateOf(p: VoiceParticipant) {
  const notes = [
    p.screen_sharing ? 'sharing a screen' : null,
    p.deafened ? 'deafened' : p.muted ? 'muted' : null,
  ].filter(Boolean)

  return notes.length ? `${nameFor(p.user)} — ${notes.join(', ')}` : nameFor(p.user)
}

/** "Ana is" / "Ana and Ben are" / "3 people are" — whoever has a screen up right now. */
const sharingNames = computed(() => {
  const names = waiting.value.filter(p => p.screen_sharing).map(p => nameFor(p.user))

  if (!names.length) return null
  if (names.length === 1) return `${names[0]} is`
  if (names.length === 2) return `${names[0]} and ${names[1]} are`

  return `${names.length} people are`
})

const mutedCount = computed(() => waiting.value.filter(p => p.muted && !p.deafened).length)
const deafenedCount = computed(() => waiting.value.filter(p => p.deafened).length)
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
            :title="stateOf(p)"
          >
            <img v-if="p.user.avatar" :src="p.user.avatar" :alt="nameFor(p.user)" class="h-full w-full rounded-full object-cover">
            <span v-else>{{ initials(nameFor(p.user)) }}</span>
          </div>
        </div>
        <span class="truncate text-xs text-muted-foreground">
          {{ waiting.length === 1 ? `${nameFor(waiting[0]!.user)} is` : `${waiting.length} people are` }} in the call
        </span>

        <!--
          Faces this small overlap, so a badge per avatar would be half-hidden under the next
          one — the per-person detail is in each avatar's tooltip instead, and what's worth
          seeing without hovering is summarised here. A screen already up in there is the one
          that changes whether you bother joining.
        -->
        <span class="flex shrink-0 items-center gap-1.5 text-muted-foreground">
          <ScreenShare v-if="sharingNames" class="h-3.5 w-3.5 text-primary" :title="`${sharingNames} sharing a screen`" />
          <MicOff v-if="mutedCount" class="h-3.5 w-3.5" :title="`${mutedCount} muted`" />
          <HeadphoneOff v-if="deafenedCount" class="h-3.5 w-3.5" :title="`${deafenedCount} deafened`" />
        </span>
      </template>
      <span v-else class="text-xs text-muted-foreground">Nobody's in voice yet.</span>

      <div v-if="canSetEffects" class="ml-auto">
        <VoiceEffectSettings :channel="channel" />
      </div>

      <Button size="sm" :class="canSetEffects ? 'gap-2' : 'ml-auto gap-2'" @click="connect(channel.id)">
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

        <div v-if="canSetEffects" class="ml-auto">
          <VoiceEffectSettings :channel="channel" />
        </div>

        <button
          type="button"
          class="flex items-center gap-1 rounded px-2 py-0.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
          :class="canSetEffects ? '' : 'ml-auto'"
          :title="collapsed ? 'Show the call' : 'Hide the call and make room for the chat'"
          @click="collapsed = !collapsed"
        >
          <ChevronDown v-if="collapsed" class="h-3.5 w-3.5" />
          <ChevronUp v-else class="h-3.5 w-3.5" />
          {{ collapsed ? 'Show' : 'Hide' }}
        </button>
      </div>

      <div v-if="!collapsed" class="flex flex-col gap-3 px-4 pb-3">
        <!-- Someone's screen, if anyone is sharing one — and you haven't chosen not to watch. -->
        <section v-if="stage" class="flex flex-col gap-1.5">
          <div
            ref="stageEl"
            class="group relative overflow-hidden bg-black"
            :class="isFullscreen ? 'h-screen w-screen' : 'aspect-video max-h-[45vh] rounded-lg border'"
          >
            <!--
              Your own screen is shown as a still note, never played back live: a whole-screen
              or window capture that includes this very window turns into an endless hall of
              mirrors, and fullscreen made it fill the display and flicker. You know what's on
              your screen — everyone else sees the real thing on their stage.
            -->
            <div v-if="stage.key === 'self'" class="grid h-full w-full place-items-center gap-2 text-center text-white/70">
              <div class="flex flex-col items-center gap-2">
                <ScreenShare class="h-8 w-8" />
                <p class="text-sm font-medium text-white">You're sharing your screen</p>
                <p class="text-xs text-white/60">Everyone else in the call can see it.</p>
              </div>
            </div>
            <VoiceVideo v-else :stream="stage.stream" />

            <!-- Stop watching: hide just the screen and keep the call. Re-watch from any
                 sharer's tile below. -->
            <button
              type="button"
              class="absolute left-2 top-2 grid h-8 w-8 place-items-center rounded-md bg-black/50 text-white opacity-0 transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
              title="Stop watching this screen"
              @click="watching = null"
            >
              <X class="h-4 w-4" />
            </button>

            <!-- Fullscreen toggle: peers' screens only (your own is a placeholder). Appears on
                 hover, and works while fullscreen too. -->
            <button
              v-if="stage.key !== 'self'"
              type="button"
              class="absolute right-2 top-2 grid h-8 w-8 place-items-center rounded-md bg-black/50 text-white opacity-0 transition hover:bg-black/70 focus:opacity-100 group-hover:opacity-100"
              :title="isFullscreen ? 'Exit fullscreen' : 'Fullscreen'"
              @click="toggleFullscreen"
            >
              <Minimize v-if="isFullscreen" class="h-4 w-4" />
              <Maximize v-else class="h-4 w-4" />
            </button>
          </div>
          <div class="flex items-center gap-2">
            <span class="shrink-0 text-xs text-muted-foreground">{{ stage.name }}</span>

            <!-- How loud their shared screen plays, for you alone — separate from their voice,
                 and with its own switch for turning the sound off while you keep watching. -->
            <div v-if="stagePeer" class="flex min-w-0 items-center gap-1.5">
              <button
                type="button"
                class="shrink-0 rounded p-0.5 transition"
                :class="stagePeer.screenMuted ? 'text-destructive' : 'text-muted-foreground hover:text-foreground'"
                :title="stagePeer.screenMuted
                  ? 'Hear this screen again'
                  : 'Mute this screen\'s sound — you\'ll still hear everyone talking'"
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
                class="h-1 w-24 cursor-pointer appearance-none rounded-full bg-muted accent-primary disabled:cursor-not-allowed disabled:opacity-40"
                :aria-label="`Shared screen volume for ${stagePeer.name}`"
                :title="stagePeer.screenMuted ? 'Screen sound: off' : `Screen sound: ${Math.round(stagePeer.screenVolume * 100)}%`"
                @input="setPeerScreenVolume(stagePeer.id, Number(($event.target as HTMLInputElement).value))"
              >
            </div>

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
            @disconnect="askKick(peer)"
            @force-mute="muteUser(peer.id, $event)"
          />
        </div>
      </div>

      <!-- Controls stay put whether or not the tiles are showing. -->
      <div class="flex items-center justify-center gap-2 border-t px-4 py-2">
        <Button
          :variant="micOpen ? 'secondary' : 'destructive'"
          size="icon"
          :title="selfMuted ? 'Unmute your microphone' : pushToTalk ? 'Push-to-talk — hold Space' : 'Mute your microphone'"
          @click="toggleMute"
        >
          <MicOff v-if="!micOpen" class="h-4 w-4" />
          <Mic v-else class="h-4 w-4" />
        </Button>

        <!-- On push-to-talk the mic button no longer tells the whole story, so say it plainly. -->
        <span
          v-if="pushToTalk && !selfMuted"
          class="rounded px-2 py-1 text-xs transition-colors"
          :class="pttHeld ? 'bg-green-600/15 font-medium text-green-600 dark:text-green-400' : 'text-muted-foreground'"
        >
          {{ pttHeld ? 'Talking…' : 'Hold Space' }}
        </span>

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

        <!-- Sound with nothing to look at: a track, or a video everyone is listening to.
             Its own button rather than a mode of the one above, because from the room's
             side it is a different thing to be offered — there is no screen to watch. -->
        <Button
          :variant="isAudioSharing ? 'default' : 'secondary'"
          size="sm"
          class="gap-2"
          :title="isAudioSharing
            ? 'Stop sharing audio'
            : 'Share a tab\'s sound with the call, without sharing the picture'"
          @click="toggleAudioShare"
        >
          <AudioLines class="h-4 w-4" />
          {{ isAudioSharing ? 'Stop audio' : 'Share audio' }}
        </Button>

        <!-- Only when there's actually anyone to clear out. Turns everyone but you out of
             the room; you keep your seat (use Leave for that). -->
        <Button
          v-if="peers.length"
          variant="secondary"
          size="sm"
          class="gap-2 text-destructive hover:bg-destructive hover:text-destructive-foreground"
          title="Disconnect everyone else from the call"
          @click="askKick('all')"
        >
          <UserX class="h-4 w-4" />
          Disconnect all
        </Button>

        <Button variant="destructive" size="icon" title="Leave the call" @click="disconnect">
          <PhoneOff class="h-4 w-4" />
        </Button>
      </div>
    </template>

    <p v-if="status === 'error' && error" class="border-t px-4 py-2 text-xs text-destructive">
      {{ error }}
    </p>

    <!-- Something that happened *to* you — being disconnected — which by nature shows up
         after you've already left the call, so it can't live inside the in-call view. -->
    <p v-if="notice" class="border-t px-4 py-2 text-xs text-muted-foreground">
      {{ notice }}
    </p>

    <AlertDialog v-model:open="kickOpen">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            {{ kickTarget === 'all' ? 'Disconnect everyone else?' : 'Disconnect this person?' }}
          </AlertDialogTitle>
          <AlertDialogDescription>
            <template v-if="kickTarget === 'all'">
              Everyone but you will be removed from the call. They can rejoin on their own.
            </template>
            <template v-else-if="kickTarget">
              {{ kickTarget.name }} will be removed from the call. They can rejoin on their own.
            </template>
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            class="bg-destructive text-white hover:bg-destructive/90"
            @click="confirmKick"
          >
            {{ kickTarget === 'all' ? 'Disconnect all' : 'Disconnect' }}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  </section>
</template>
