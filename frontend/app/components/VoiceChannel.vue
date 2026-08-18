<script setup lang="ts">
import {
  AudioLines,
  CalendarClock,
  CalendarPlus,
  Check,
  ChevronDown,
  Circle,
  Link as LinkIcon,
  ChevronUp,
  Headphones,
  HeadphoneOff,
  Loader2,
  Mic,
  MicOff,
  PhoneOff,
  ScreenShare,
  ScreenShareOff,
  SwitchCamera,
  UserX,
  Video,
  VideoOff,
  Volume2,
} from 'lucide-vue-next'
import type { Channel, Peer, VoiceParticipant } from '~/types'
import { watchKey } from '~/composables/useCallStage'
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
  toggleScreenShare,
  toggleAudioShare,
  canShareScreen,
  screenShareUnavailableReason,
  probeDisplayCapture,
  toggleCamera,
  switchCamera,
  canSwitchCamera,
  cameraFacing,
  disconnectUser,
  disconnectAll,
  muteUser,
} = useVoice()

// Whether this device can capture a screen at all — an OS-and-build question, not a platform
// one, so it's asked rather than assumed. See useDisplayCapture.
onMounted(probeDisplayCapture)

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

/* ------------------------------------------------------------------- recording the call */

/**
 * Recording is offered to everybody and refused by the API for anyone who may not — staff in a
 * server's room, the owner of a group, either person in a DM (see RecordCallRequest).
 *
 * Offered-then-refused rather than hidden, because the client has no reliable idea of the
 * viewer's role here (the roster carries badges, not powers) and a button that quietly isn't
 * there is indistinguishable from a feature that doesn't exist. The refusal says why.
 */
const recorder = useCallRecorder()

/**
 * A guest is in the meeting, not in charge of it: no scheduling, and no handing the link on.
 * Both are refused server-side; this is what keeps the room from offering them.
 */
const { isGuest } = useGuest()

async function toggleRecording() {
  if (recorder.recording.value) await recorder.stop()
  else await recorder.start(props.channel.id)
}

/**
 * Is anybody in this room recording?
 *
 * Read off the *roster*, not off our own recorder — the badge has to be true for everyone in the
 * room, including the people who didn't press the button. That's the whole reason the flag lives
 * on the participant row.
 */
const recordedBy = computed(() => waiting.value.filter((p: VoiceParticipant) => p.recording))

/**
 * What's scheduled in this room — see useRoomMeetings.
 *
 * Only for a room that can hold one: a DM's call is a call between two people, and "the next
 * meeting in here" is not a thing anybody schedules.
 */
/*
 * Any room that can hold a meeting — a voice channel, a Side Space, or the channel of a group
 * chat (which is what a group meeting's room *is*). Originally this was voice-only, which meant
 * the rooms created by "New meeting" were the ones that couldn't show their own meeting.
 *
 * A DM is excluded: two people don't schedule meetings with each other, and a strip saying
 * "nothing scheduled in here" over every DM would be furniture nobody asked for.
 */
const meetingChannelId = computed(() => {
  // Nothing in this strip is for a guest: they can't schedule, can't pass the link on, and
  // "nothing scheduled in here" is furniture to somebody who is here for one call.
  if (isGuest.value) return null

  const c = props.channel
  // A group chat's channel is a meeting room; a DM's is two people talking, where a strip
  // saying "nothing scheduled in here" would be furniture nobody asked for.
  const group = c.conversation_id != null
    && conversation.value?.channel_id === c.id
    && conversation.value?.type === 'group'

  const room = c.type === 'voice' || c.type === 'space' || group
  return room ? c.id : null
})
const { current: meetingNow, next: meetingNext, until } = useRoomMeetings(meetingChannelId)

/**
 * The way *in* to scheduling one.
 *
 * A meeting has always been "a calendar entry with a room", and until this the only route to it
 * was: open the desk, find Calendar, make an entry, then find this room in a dropdown. Four steps
 * none of which mention meetings. This opens the same editor with the room already chosen — the
 * intent rides in the URL, which is how every other panel here is addressed (see useSurfaceRoute).
 */
const surface = useSurfaceRoute()

function scheduleMeeting() {
  surface.patch({ desk: 'calendar', meet: '1', event: null })
}

/** Open an existing entry in the same editor — what the banner's title does. */
function openMeeting(id: number) {
  surface.patch({ desk: 'calendar', event: String(id), meet: null })
}

/* ------------------------------------------------------------------ the meeting link */

/**
 * "Get the link to this room."
 *
 * The gap this fills: a link was only ever visible in the dialog that created it, so closing that
 * dialog lost it. Any member may copy one — a link is exactly the thing they're entitled to pass
 * on — and a room that has never had one gets one made for it here, because from the asker's side
 * "get the link" is one question whether or not somebody has asked it before.
 */
const { ensureFor, linkFor } = useMeetings()

const linking = ref(false)
const linkCopied = ref(false)

async function copyMeetingLink() {
  if (!meetingChannelId.value || linking.value) return
  linking.value = true

  try {
    const meeting = await ensureFor(meetingChannelId.value, props.channel.name || 'Meeting')
    await navigator.clipboard.writeText(linkFor(meeting.token))
    linkCopied.value = true
    setTimeout(() => { linkCopied.value = false }, 1800)
  }
  catch {
    // Clipboard refused, or the link couldn't be made. Nothing is claimed — the button simply
    // doesn't turn into "Copied".
  }
  finally {
    linking.value = false
  }
}

/* --------------------------------------------------------------- arriving from a link */

/**
 * `?call=1` — put me in the call on arrival.
 *
 * Set by a meeting link (see useMeetings.roomPath). Following one and then having to press
 * "Join call" is two doors for one intention, and the press that followed the link is the user
 * gesture a browser wants before it will ask for a microphone — spending it on the navigation
 * and then asking for a second click wastes exactly the thing that makes this possible.
 *
 * Three guards, each of which is a real case rather than defensive habit:
 *
 *  - **Once.** The flag is cleared as it's acted on, so a reload of the room doesn't drag
 *    somebody back into a call they deliberately left.
 *  - **Not if you're already talking.** Somebody in another room's call who opens a meeting link
 *    is choosing to look, not to be moved; `connect` would tear down the call they're in.
 *  - **Not if you're already here.** Re-connecting an established seat would drop and remake it.
 */
async function autoJoinFromLink() {
  if (surface.query.value.call !== '1') return

  // Consumed first: a failure to get a microphone must not leave the flag armed for the next
  // reload, or a refusal turns into a prompt that reappears forever.
  surface.patch({ call: null })

  if (here.value || (channelId.value !== null && channelId.value !== props.channel.id)) return

  await connect(props.channel.id)
}

onMounted(() => {
  void autoJoinFromLink()
})

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
  // Nothing is ever at a distance from itself, and a voice channel has no distances anyway.
  proximity: 1,
}))

// --- the screen-share stage ---

/**
 * The stage: which screen, or whose face, is on the big rectangle.
 *
 * Shared with the Side Space dock, which had grown its own near-identical copy of this — see
 * useCallStage, which is also where cameras being stageable at all is explained.
 *
 * **One behaviour changed in the move.** This used to fall through to the first screen still up
 * whenever the watched one became invalid, `watching = null` included — so closing a screen in a
 * voice channel sprang it straight back, and there was no way to dismiss the last one at all.
 * Now closing means closed, and a *newly* started share is what claims the stage again.
 *
 * **And it holds several at once** — up to `MAX_WATCHING`, laid out as a grid. Two people sharing
 * used to mean picking one; now the second joins the first, which is the case that produces two
 * shares in the first place.
 */
const { watchables, watching, stages, stageFull, isWatching, screenPeerFor, toggleWatch, clearWatching } = useCallStage({
  peers: () => peers.value,
  self: () => ({
    sharing: isSharing.value,
    screen: screenStream.value,
    cameraOn: isCameraOn.value,
    camera: cameraStream.value,
  }),
  // A screen going up is the one moment the call earns room over the chat. Cameras don't get
  // this: people leave them on for the whole call, and it would mean the pane could never
  // usefully be hidden.
  onScreenStarted: () => { collapsed.value = false },
})

/*
 * How the grid is laid out.
 *
 * One thing on the stage keeps the full width, which is the overwhelmingly common case and the
 * one that most wants the pixels. Beyond that it's two columns — a shared terminal in a third of
 * a chat pane is not something anyone can read, so the third and fourth tiles go onto a second
 * row rather than making every tile narrower.
 */
const stageColumns = computed(() => stages.value.length > 1 ? 'grid-cols-2' : 'grid-cols-1')

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
  <!--
    The meeting strip, deliberately *outside* the call section below.

    That section hides itself in a chat with no call going (`quietWhenEmpty`) — which is right for
    an empty call stage and was wrong for this: it meant a group chat created as a meeting room
    couldn't show its own link until somebody was already in the call, i.e. exactly when nobody
    needs the link any more. The strip is about the room, not about whether it's occupied.
    Shown whether or not you've joined, too: "is something happening in here, and when" is the
    question you have *before* you decide to go in.
  -->
  <div
    v-if="meetingChannelId"
    class="flex items-center gap-1.5 border-b px-4 py-1.5 text-xs"
    :class="meetingNow ? 'bg-primary/10 text-primary' : 'text-muted-foreground'"
  >
    <CalendarClock class="h-3.5 w-3.5 shrink-0" />

    <!-- The title opens the entry, because "what is this meeting" is the next question after
         "there is one" — and the entry is where its notes and its room live. -->
    <button
      v-if="meetingNow || meetingNext"
      type="button"
      class="min-w-0 truncate text-left hover:underline"
      :class="meetingNow && 'font-medium'"
      :title="'Open this entry in the calendar'"
      @click="openMeeting((meetingNow ?? meetingNext)!.id)"
    >
      <template v-if="meetingNow">{{ meetingNow.title }} — happening now</template>
      <template v-else>{{ meetingNext!.title }} — {{ until(meetingNext!) }}</template>
    </button>
    <span v-else class="min-w-0 truncate">Nothing scheduled in here.</span>

    <!-- The link to this room, made on demand if it hasn't got one. Beside Schedule because
         the two are the same errand: getting somebody else in here. -->
    <button
      type="button"
      class="ml-auto flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
      :disabled="linking"
      title="Copy a link that brings people straight into this room"
      @click="copyMeetingLink"
    >
      <Loader2 v-if="linking" class="h-3.5 w-3.5 animate-spin" />
      <component :is="linkCopied ? Check : LinkIcon" v-else class="h-3.5 w-3.5" />
      {{ linkCopied ? 'Copied' : 'Copy link' }}
    </button>

    <button
      type="button"
      class="flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 hover:bg-muted hover:text-foreground"
      title="Schedule a meeting in this room"
      @click="scheduleMeeting"
    >
      <CalendarPlus class="h-3.5 w-3.5" /> Schedule
    </button>
  </div>

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
        <!-- Whatever you're watching — screens and faces, up to four of them side by side. -->
        <section v-if="stages.length" class="flex flex-col gap-1.5">
          <div class="grid gap-1.5" :class="stageColumns">
            <CallStageTile
              v-for="w in stages"
              :key="w.key"
              :watchable="w"
              :screen-peer="screenPeerFor(w)"
              :picture-class="stages.length > 1 ? 'max-h-[30vh]' : 'max-h-[45vh]'"
              @close="toggleWatch(w.owner, w.kind)"
            />
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <!-- Clear the lot without leaving the call. Only worth a button once there's more
                 than one to clear; a single tile has its own X. -->
            <button
              v-if="stages.length > 1"
              type="button"
              class="shrink-0 rounded px-2 py-0.5 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground"
              title="Stop watching everything and keep the call"
              @click="clearWatching()"
            >
              Clear stage
            </button>

            <!-- Everything you could be watching, and everything you are. -->
            <CallStagePicker
              v-if="watchables.length > 1"
              class="ml-auto"
              :watchables="watchables"
              :watching="watching"
              :full="stageFull"
              @toggle="toggleWatch($event.owner, $event.kind)"
            />
          </div>
        </section>

        <!-- Tiles are wider than they were, because a tile with a camera in it is now 16:9
             rather than an 80px circle. `min(100%,220px)` is what keeps that honest on a
             phone: the track floor can never exceed the column, so a 390px screen gets one
             full-width tile instead of a 220px one with its controls squashed beside it. -->
        <div class="grid max-h-[55vh] grid-cols-[repeat(auto-fill,minmax(min(100%,220px),1fr))] gap-2 overflow-y-auto">
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
            v-for="peer in peers"
            :key="peer.id"
            :peer="peer"
            :speaking="peer.speaking"
            :muted="peer.muted"
            :sharing="peer.screenSharing"
            :watching="isWatching(watchKey(peer.id, 'screen'))"
            :pinned="isWatching(watchKey(peer.id, 'camera'))"
            :can-moderate="canModerate"
            @toggle-mute="togglePeerMute(peer.id)"
            @set-volume="setPeerVolume(peer.id, $event)"
            @set-screen-volume="setPeerScreenVolume(peer.id, $event)"
            @toggle-screen-mute="togglePeerScreenMute(peer.id)"
            @watch="toggleWatch(peer.id, 'screen')"
            @pin="toggleWatch(peer.id, 'camera')"
            @disconnect="askKick(peer)"
            @force-mute="muteUser(peer.id, $event)"
          />
        </div>
      </div>

      <!-- Controls stay put whether or not the tiles are showing. They wrap rather than
           overflow, because a phone can't fit the row on one line and a call control you
           can't reach is worse than one on a second row. -->
      <div class="flex flex-wrap items-center justify-center gap-2 border-t px-4 py-2">
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

        <!-- Front camera or back one. Only while a camera is actually on (there's nothing to
             flip otherwise) and only on a device with more than one — see canSwitchCamera. -->
        <Button
          v-if="isCameraOn && canSwitchCamera"
          variant="secondary"
          size="icon"
          :title="cameraFacing === 'user' ? 'Switch to the back camera' : 'Switch to the front camera'"
          @click="switchCamera"
        >
          <SwitchCamera class="h-4 w-4" />
        </Button>

        <!--
          Offered wherever the device can actually capture a screen, which since the native
          capture plugin includes the phone. Not a platform check: no mobile WebView implements
          getDisplayMedia, so on Android it's MediaProjection and on iOS the broadcast
          extension, and either can be missing on an older OS. `canShareScreen` is the answer
          to "can *this* device", asked once when the call bar mounts. Watching someone else's
          share has always worked everywhere and is unaffected either way.
        -->
        <Button
          v-if="canShareScreen"
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
        <!-- Same capability gate: an audio share is a screen capture with the picture thrown
             away, so a device that can't capture can't do this either. It used to be offered
             unconditionally and simply did nothing when pressed on a phone. -->
        <Button
          v-if="canShareScreen"
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

        <!-- Recording. Destructive-coloured while it runs, because the state it puts the room
             in is the thing worth noticing, and it counts up so nobody forgets it's on. -->
        <Button
          :variant="recorder.recording.value ? 'destructive' : 'secondary'"
          size="sm"
          class="gap-2"
          :disabled="recorder.uploading.value"
          :title="recorder.recording.value
            ? 'Stop recording — the file is posted here when it finishes uploading'
            : 'Record this call\'s audio. Everyone in the room is told.'"
          @click="toggleRecording"
        >
          <Loader2 v-if="recorder.uploading.value" class="h-4 w-4 animate-spin" />
          <Circle v-else class="h-4 w-4" :class="recorder.recording.value && 'fill-current'" />
          {{ recorder.uploading.value
            ? 'Uploading…'
            : recorder.recording.value ? `Stop ${recorder.label.value}` : 'Record' }}
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

    <!--
      Visible to the whole room, sourced from the roster rather than from this tab's recorder.
      A call being recorded is something everybody in it is entitled to know at a glance — the
      timeline announcement is the durable record, and this is the live one.
    -->
    <p
      v-if="recordedBy.length"
      class="flex items-center gap-1.5 border-t bg-destructive/10 px-4 py-1.5 text-xs font-medium text-destructive"
    >
      <Circle class="h-3 w-3 fill-current" />
      {{ recordedBy.length === 1
        ? `${nameFor(recordedBy[0]!.user)} is recording this call.`
        : `${recordedBy.length} people are recording this call.` }}
    </p>

    <p v-if="recorder.error.value" class="border-t px-4 py-1.5 text-xs text-destructive">{{ recorder.error.value }}</p>

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
