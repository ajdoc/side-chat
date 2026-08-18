import type { MicChain, NoiseSuppression } from '~/lib/micProcessing'
import type { Facing } from '~/lib/spaceMapEngine'
import type { CleanedCapture } from '~/lib/shareEcho'
import type { SpatialPlacement, SpatialVoice } from '~/lib/spatialAudio'
import type {
  SfuCredentials,
  TrackKind,
  TransportContext,
  TransportEvents,
  TransportKind,
  VoiceTransport,
} from '~/lib/voice/VoiceTransport'
import type { MeshSignaling, SignalPayload } from '~/lib/voice/MeshTransport'
import type { CloudflareAnnouncement } from '~/lib/voice/CloudflareTransport'
import { createCloudflareTransport } from '~/lib/voice/CloudflareTransport'
import { createMeshTransport } from '~/lib/voice/MeshTransport'
import { createLiveKitTransport } from '~/lib/voice/LiveKitTransport'
import {
  base64ToGunzip,
  gzipToBase64,
  mungeOpus,
  preferEfficientVideo,
  preferOpus,
} from '~/lib/voice/sdp'
import type { IceServer, Peer, PeerConnectionState, VoiceEffectPair, VoiceEffects, VoiceParticipant } from '~/types'
import {
  buildMicChain,
  DEFAULT_NOISE_SUPPRESSION,
  DEFAULT_NORMALIZE_VOLUME,
  DEFAULT_SUPPRESSION_STRENGTH,
  clampStrength,
  micConstraints,
  NOISE_SUPPRESSION_LEVELS,
  RNNOISE_SAMPLE_RATE,
  NOISE_SUPPRESSION_OPTIONS,
  resetMicProcessing,
  SUPPRESSION_STRENGTH_RANGE,
} from '~/lib/micProcessing'
import { cancelCallEcho } from '~/lib/shareEcho'
import {
  arrangeInArc,
  centreListener,
  createSpatialVoice,
  DEFAULT_PLACEMENT,
  DEFAULT_WIDTH,
  distanceGain,
  placementFromOffset,
  rotatePlacement,
  spatialSupported,
} from '~/lib/spatialAudio'

/**
 * A voice call: a full mesh of WebRTC peer connections, signalled over Reverb.
 *
 * ## Why a mesh
 *
 * Everybody connects directly to everybody else, so a call of N people means N-1 peer
 * connections each and N-1 copies of your microphone going up your (asymmetric, thin)
 * upload pipe. Audio is cheap enough that this is genuinely fine for a handful of people
 * — a screen share at 2.5Mbps × 7 peers is not, which is why the backend caps the room
 * (config/webrtc.php) and refuses the person who would tip it over rather than letting
 * the call quietly turn to mud for everyone already in it.
 *
 * The alternative is an SFU: every browser sends *one* stream to a media server, which
 * fans it out. That's how you get to 50 people, and it's a whole server to run, deploy
 * and pay for. It is the right thing to reach for the day a room needs to be big; it is
 * a strange thing to reach for on the day you want four people to talk to each other.
 *
 * ## Two conversations, deliberately
 *
 * Media negotiation (offers, answers, ICE candidates) rides on the *presence* channel as
 * client events — it never reaches Laravel. Presence is what makes this work: it hands us
 * `here` (who to dial on arrival), `joining` (who is about to dial us) and `leaving`
 * (whose connection to tear down) — including when a laptop lid closes and no one gets to
 * say goodbye.
 *
 * The HTTP endpoints do something different and slower: they record who's in the room so
 * that members *outside* the call can see it in the sidebar. Nothing about the audio path
 * waits on them.
 *
 * ## What is yours alone
 *
 * `muted` on a peer means they muted their own microphone, for everybody. `localMuted`
 * and `volume` mean you turned *them* down, on your speakers, and that decision is not
 * broadcast, not persisted server-side, and not visible to them — it lives in this tab
 * and in localStorage.
 */

/** How loud counts as talking, and how long we keep the ring lit after they stop. */
const SPEAKING_THRESHOLD = 0.02
const SPEAKING_HOLD_MS = 250

/** Comfortably inside the backend's staleness window, so a live tab is never reaped. */
const HEARTBEAT_MS = 25_000

/*
 * Encoding budgets — how many bits a screen, a camera, a microphone and a shared soundtrack
 * may each spend — now live with the transport that has to honour them, in
 * ~/lib/voice/MeshTransport. They belong there because they are not one set of numbers: a mesh
 * sends the same picture once per person and has to divide a budget between them, while an SFU
 * takes one copy and fans it out. The arithmetic is a property of how the media travels.
 *
 * What stays here is the half that isn't about bytes at all: what the *content* is, and which
 * axis to give up first when it can't all fit — see the detail/motion sampler below.
 */

/**
 * How the microphone is captured and cleaned up lives in ~/lib/micProcessing: the capture
 * constraints (mono, and the browser's own echo/noise/gain cleanup) and the extra chain the
 * "Aggressive" level adds on top of them. `channelCount: 1` there is the mono capture this
 * file's encoding budget is built around — see the mic cap in MeshTransport.
 */

/**
 * Encoding is the other half of the cost, and it's the half that lags the *sharer*.
 *
 * A screen isn't sent once — in a mesh it's encoded separately for every peer, so the CPU
 * bill scales with pixels × framerate × people. On a machine that's already busy driving a
 * game or a video, that's exactly the load that stutters both the share and the game. So we
 * cap the capture itself, and default it *below* 1080p: 720p reads a shared window fine and
 * costs the encoder about half as many pixels. The chooser (see setScreenResolution) lets
 * someone with headroom trade it back up. Past ~4K it's the encoder, not the network, that
 * falls over, which is the whole reason the cap is on the capture and not just the bitrate.
 */
/**
 * Where the call's own fader starts, before anyone has touched it — see `outputVolume`.
 *
 * Not 1. A remote voice at full scale sits noticeably above the level people have their
 * machine set to for everything else, so a call opened at 1 is the one thing on the system
 * that makes them reach for the knob. Starting a little under leaves room to go *up* for a
 * quiet talker, which a ceiling of 1 does not.
 */
const DEFAULT_OUTPUT_VOLUME = 0.75

const SCREEN_RESOLUTIONS = [480, 720, 1080] as const
type ScreenResolution = (typeof SCREEN_RESOLUTIONS)[number]
const DEFAULT_SCREEN_RESOLUTION: ScreenResolution = 720
const SCREEN_MAX_FRAMERATE = 30

/**
 * Detail content — slides, docs, code — is mostly still, so there is no reason to pay 30 fps of
 * bitrate and encode for it. Encoded at a low framerate instead, the saved budget goes into
 * keeping the *text* crisp (contentHint 'detail' + maintain-resolution). Motion content keeps
 * the full rate. The 'auto' sampler flips between the two, so a deck that starts playing a video
 * speeds back up on its own — the axis it's easy to be wrong about is the one it re-checks.
 */
const SCREEN_DETAIL_FRAMERATE = 10

/**
 * Consecutive still samples (one a second) before 'auto' drops back to the detail encoding.
 * Only guards that direction — see sampleScreen for why the two aren't symmetric.
 */
const SCREEN_DETAIL_DWELL_SAMPLES = 4

/**
 * How a share is encoded, as a trade the sender picks:
 *
 * - `detail` — sharp text over smooth motion (code, docs): contentHint 'detail', and under
 *   load keep the resolution and shed framerate so the text stays legible.
 * - `motion` — smooth motion over sharpness (a game, a video): contentHint 'motion', and
 *   under load keep the framerate and drop resolution so it doesn't judder.
 * - `auto` — watch the picture and pick between the two as the content changes; see the
 *   sampler in startScreenShare. The one that costs a trickle of CPU to guess right.
 */
type ScreenMode = 'auto' | 'detail' | 'motion'
const DEFAULT_SCREEN_MODE: ScreenMode = 'auto'

/** The concrete contentHint + degradation for a resolved (never 'auto') mode. */
function screenModeSettings(mode: 'detail' | 'motion'): {
  hint: 'detail' | 'motion'
  degradation: RTCDegradationPreference
  maxFramerate: number
} {
  return mode === 'motion'
    ? { hint: 'motion', degradation: 'maintain-framerate', maxFramerate: SCREEN_MAX_FRAMERATE }
    : { hint: 'detail', degradation: 'maintain-resolution', maxFramerate: SCREEN_DETAIL_FRAMERATE }
}

/**
 * What the *call* keeps about a peer, now that the transport keeps the rest.
 *
 * The split is deliberate and it's the whole point of the transport interface: peer
 * connections, transceivers, senders and the negotiation state machine live in
 * ~/lib/voice/MeshTransport (or don't exist at all, behind an SFU). What's left here is
 * everything that would be identical either way — the elements a voice comes out of, the
 * analyser that decides who's speaking, where they're stood in the room, and how loud you
 * like them. None of that changes when the transport does.
 *
 * Streams are assembled here by hand rather than taken from `event.streams[0]`, because the
 * video slots are negotiated empty and a track pushed into one later can arrive orphaned.
 * Owning the streams sidesteps that, and the transport hands us bare tracks precisely so it
 * doesn't have to care.
 */
interface PeerHandle {
  /**
   * Their audio, playing outside Vue's control.
   *
   * Kept as a bare element appended to the document rather than something a component
   * renders, because a call outlives the page you started it on: you can wander off to a
   * text channel and keep talking, and nothing about that should interrupt the audio. The
   * element is also what per-peer volume and per-peer mute actually *are* — see setVolume.
   */
  audio: HTMLAudioElement
  /** Their microphone. Kept alone, because it's the only thing the <audio> should sink. */
  audioStream: MediaStream
  /**
   * The audio *of* what they're sharing — a video playing in the tab, say — kept on its own
   * element, deliberately apart from the microphone. Mixing the two would let a screen mute
   * silence a voice, run the shared audio through the mic's echo cancellation, and set the
   * speaking ring flickering to a YouTube clip. One <audio> each keeps per-peer volume and
   * mute honest for both.
   */
  screenAudio: HTMLAudioElement
  screenAudioStream: MediaStream
  /**
   * Their face, and the thing they're presenting — separate streams, because someone on
   * camera who starts presenting has to appear in two places at once: their face on their
   * tile, their screen on the stage. Which arriving track is which is the transport's
   * problem; it tells us with the `kind` on trackReceived.
   */
  cameraStream: MediaStream
  screenStream: MediaStream
  analyser: AnalyserNode | null
  /**
   * Their voice, positioned in the room — or null when spatial audio is off, unsupported, or
   * their audio track hasn't arrived yet. While this exists it, and not `audio.volume`, is
   * what you hear: the element stays in the document muted, as the pump Chromium needs to
   * keep a remote stream flowing into WebAudio. See createSpatialVoice.
   */
  spatial: SpatialVoice | null
  /**
   * This peer's contribution to the echo canceller's reference mix — their voice and their
   * shared sound, at the levels you're actually hearing them.
   *
   * Null unless a desktop share is carrying the machine's own audio, which is the only time
   * anything needs to know what the call sounds like from outside. See openReferenceMix.
   */
  refTaps: { voice: GainNode, screen: GainNode } | null
  /**
   * This peer's two audio streams, tapped into the recording mix.
   *
   * Separate from `refTaps` on purpose: the reference mix follows what *you* hear (a peer you
   * muted locally contributes silence to it), and a recording of the meeting must not. Somebody
   * turning one person down in their own ears is not an edit to the record of the call.
   */
  recTaps: { voice: MediaStreamAudioSourceNode, screen: MediaStreamAudioSourceNode } | null
  speakingUntil: number
}

/** One input event from a controller. Terse keys: this goes out ~60×/second while dragging. */
export interface ControlInput {
  t: 'move' | 'down' | 'up' | 'wheel' | 'key-down' | 'key-up'
  /** Pointer position as a fraction (0..1) of the shared surface — never pixels. See below. */
  x?: number
  y?: number
  /** Mouse button, as `MouseEvent.button`. */
  b?: number
  dx?: number
  dy?: number
  /** `KeyboardEvent.code` — the physical key, so the sharer's own layout decides the character. */
  code?: string
}

/** The control handshake, over whispers. `to` is omitted on a broadcast (an ended session). */
export interface ControlSignal {
  from: number
  to: number
  kind: 'request' | 'approve' | 'deny' | 'end'
}

interface StatePayload {
  id: number
  muted: boolean
  deafened: boolean
  screen_sharing: boolean
  camera_on: boolean
  audio_sharing: boolean
  /**
   * How this person's screen is travelling — and the only way anyone else could know.
   *
   * A share moved onto the SFU is published into a room the viewers are not in, so without
   * this they would watch it vanish from the mesh and never think to look anywhere else.
   * Optional so a client that hasn't been updated is read as sharing directly, which is what
   * it is doing.
   */
  screen_transport?: TransportKind
  /**
   * Where this person's media sits on a relaying SFU, when they're using one.
   *
   * Cloudflare Realtime has no track discovery of its own: to pull somebody's screen you need
   * their session id and the name they published it under, and nothing on Cloudflare's side
   * will ever tell you. So it rides here, alongside the other "what is this person doing"
   * facts, which is the one message every peer already receives.
   */
  sfu?: CloudflareAnnouncement | null
}

interface JoinResponse {
  data: VoiceParticipant[]
  ice_servers: IceServer[]
  max_participants: number
  effects: VoiceEffects
  /**
   * How the server thinks this call should be carried — see the backend's
   * VoiceTransportResolver, which weighs the admin's policy, the size of the call, and which
   * SFU providers are actually configured.
   *
   * Optional because a client can outlive a backend that doesn't send it yet: absent means
   * 'mesh', which is what every call was before any of this existed.
   */
  transport?: TransportKind
  /** Present only when `transport` is 'sfu'. Where to connect, and proof we may. */
  sfu?: SfuCredentials | null
}

/** Nothing attached to anybody — the shape of a room that has never been decorated. */
const NO_EFFECTS: VoiceEffects = { default: { join: null, leave: null }, people: [] }

/** How loud you like each person, remembered between calls. */
interface LocalPrefs {
  volume: number
  muted: boolean
  /** How loud you like *what they're sharing* — independent of their voice. */
  screenVolume?: number
  /**
   * Whether you've silenced what they're sharing while still listening to *them*.
   *
   * Its own switch rather than a volume of zero, because the two are different intentions
   * and a slider can't hold both: turning a share down to nothing loses where you had it,
   * and coming back needs you to guess. This remembers.
   */
  screenMuted?: boolean
  /**
   * Where you've put them in the room, in radians clockwise from straight ahead, and how far
   * off. Remembered with the rest of your decisions about a person, because that's what it is
   * — someone you always want on your left stays on your left, call after call.
   *
   * Absent for anyone you've never placed; they're arranged automatically instead (see
   * {@link arrangeInArc}), and the arrangement is only written down once you move somebody.
   */
  angle?: number
  distance?: number
}

// Module scope, not component scope: one call, however many components are looking at it.
// None of this belongs in reactive state — Vue would proxy the RTCPeerConnections and
// MediaStreams, and a proxied MediaStream is not a MediaStream as far as the DOM is
// concerned (assigning one to `srcObject` throws).
const handles = new Map<number, PeerHandle>()
/**
 * What peers are sent. On the 'off' and 'standard' suppression levels this *is* the capture;
 * on 'high' it's the output of the processing chain, and {@link rawStream} is the capture
 * behind it. Everything that sends, mutes or meters your microphone goes through this one,
 * so the rest of the file never has to know which of the two it's holding.
 */
let localStream: MediaStream | null = null
/**
 * The microphone itself, kept separately because the chain's output track is not the thing
 * that holds the device: stopping it is what releases the mic and turns the browser's
 * recording indicator off, and disabling it is what makes muting real rather than cosmetic.
 */
let rawStream: MediaStream | null = null
let micChain: MicChain | null = null
/** Guards reviveMic: un-muting can fire twice in a frame, and getUserMedia is slow. */
let reviving = false
/**
 * Where remote control's two streams get delivered, once useRemoteControl registers for them.
 *
 * Single handlers rather than listener sets: there is exactly one remote-control state machine
 * in the app (it's a `useState` singleton), and letting several register would mean a hot reload
 * quietly stacking up duplicate injectors. Registering again replaces.
 */
let controlInputHandler: ((from: number, input: ControlInput) => void) | null = null
let controlSignalHandler: ((signal: ControlSignal) => void) | null = null

let screenTrack: MediaStreamTrack | null = null
let screenAudioTrack: MediaStreamTrack | null = null
let cameraTrack: MediaStreamTrack | null = null
let iceServers: IceServer[] = []
/**
 * The SFU the server offered for this call, if it offered one.
 *
 * Module scope alongside `iceServers` for the same reason: it's a property of the call, and
 * `useVoice()` is instantiated per component. Null whenever the call is a mesh — including
 * when the server proposed an SFU and connecting to it failed.
 */
let sfuCredentials: SfuCredentials | null = null

/**
 * The thing actually carrying this call — a mesh, or an SFU.
 *
 * Module scope like `presence` and for the same reason: it belongs to the call, and
 * `useVoice()` is instantiated per component. Null between calls.
 */
let activeTransport: VoiceTransport | null = null

/**
 * The SFU, when a screen share is using one.
 *
 * A *second* transport rather than a replacement, which is the shape of the whole feature:
 * voices and cameras never leave the mesh, and this carries the one stream big enough to be
 * worth a server. Null until a share asks for it, and null again the moment one stops needing
 * it — see openScreenSfu.
 */
let sfuTransport: VoiceTransport | null = null

/**
 * The in-flight open, so concurrent callers share one.
 *
 * Without this, `openScreenSfu` races itself: the assignment to `sfuTransport` happens after
 * the connect, and the state-whisper handler calls it on *every* whisper from a sharing peer —
 * which arrive in bursts, not least because announcing our own tracks sends one. Several land
 * inside the connect window and each builds its own transport, its own Cloudflare session, and
 * its own pull of the same tracks. Heard as the same audio two or three times over, and billed
 * that way too.
 */
let sfuOpening: Promise<VoiceTransport | null> | null = null

/**
 * Signalling for the mesh, bridged onto the presence channel.
 *
 * The transport is handed this rather than reaching for Echo itself, so that the thing which
 * knows about offers and answers doesn't also have to know how this app moves messages. The
 * handler is held here because the transport subscribes the moment it connects, which is
 * before `presence` exists — whispers simply start arriving once it does.
 */
let signalHandler: ((payload: SignalPayload) => void) | null = null

/**
 * Our Cloudflare coordinates, and the handler waiting for everyone else's.
 *
 * Only meaningful while a Cloudflare share is up. Held at module scope beside the signalling
 * bridge because it is the same kind of thing: a transport that needs a message bus, handed one
 * rather than reaching for it.
 */
let sfuAnnouncement: CloudflareAnnouncement | null = null
let sfuAnnounceHandler: ((peerId: number, announcement: CloudflareAnnouncement | null) => void) | null = null

const meshSignaling: MeshSignaling = {
  send: (body) => { presence?.whisper('signal', body) },
  subscribe: (handler) => {
    signalHandler = handler

    return () => { signalHandler = null }
  },
}
let presence: any = null
let audioCtx: AudioContext | null = null
let localAnalyser: AnalyserNode | null = null
/**
 * A silent copy of everything the call is playing you — every voice, and every screen's sound —
 * kept as one node so it can be handed to the echo canceller as the signal to subtract.
 *
 * Silent because it is never connected to the destination: the call is already audible through
 * the peers' own <audio> elements, and connecting this as well would play everyone twice. It's
 * a *tap*, and it only exists while a desktop share is carrying the machine's own sound — see
 * openReferenceMix, and shareEcho.ts for what it's for.
 */
let referenceMix: GainNode | null = null
/** The canceller wrapping the current share's audio, if one is in place. */
let shareCleanup: CleanedCapture | null = null
/**
 * The capture the canceller is reading from, when one is in place.
 *
 * Held separately because the track the peers get is no longer the track the OS gave us: it's
 * the graph's output, and stopping *that* leaves the real capture running — a screen still
 * marked as being recorded, with nothing left listening to it.
 */
let rawShareAudioTrack: MediaStreamTrack | null = null
// The WebAudio node feeding the speaking indicator from your mic. Kept in module scope so a
// mid-call microphone swap can unhook the old capture and hook the new one. See setMicDevice.
let localSource: MediaStreamAudioSourceNode | null = null
let heartbeatTimer: ReturnType<typeof setInterval> | undefined
let speakingFrame: number | undefined
let leaveOnUnload: (() => void) | undefined
// Kept so it can be removed on leave — an anonymous listener would leak one per call.
let deviceChangeHandler: (() => void) | undefined

/*
 * --- proximity (Side Space only) ---
 *
 * In a voice channel or a DM everybody in the room is dialled, full stop. In a Side Space
 * they're not: you connect to the people near you and drop them as they walk off, which is the
 * whole reason a room can hold fifty people on a mesh built for eight.
 *
 * That needs two things the ordinary call doesn't have. `roomMembers` is everyone on the
 * presence channel *whether or not* we have a connection to them — the roster `here`/`joining`
 * would normally have turned straight into peers — so that somebody walking back into range
 * can be dialled without waiting for them to rejoin the channel. And `proximityGains` is the
 * last gain computed for each of them, held outside `peers` so that it survives a peer being
 * destroyed and re-created and is ready the instant createPeer runs.
 *
 * `proximityMode` is off by default and set by the stage on connect. Everything below is inert
 * while it's off, which is what keeps the existing call paths untouched.
 */
let proximityMode = false
const roomMembers = new Map<number, { id: number, name: string, avatar: string | null }>()
/**
 * The same roster, as something you can watch.
 *
 * `roomMembers` is a plain Map because it's read on every animation frame and rewritten on
 * every arrival; making it reactive would re-render the room for each. But *somebody having
 * left* is exactly the kind of thing other parts of the room need to hear about — the map draws
 * its own avatars from whispers, and a person who walks out stops whispering rather than
 * announcing it, which left them standing there until the 15-second staleness sweep noticed.
 *
 * So the ids alone are mirrored into a shallowRef, written only when the set actually changes.
 * {@link useSpacePresence} watches it and drops anyone who is no longer on the channel.
 */
const memberIds = shallowRef<number[]>([])

function syncMemberIds() {
  const ids = [...roomMembers.keys()]
  const before = memberIds.value

  if (ids.length !== before.length || ids.some(id => !before.includes(id))) memberIds.value = ids
}
const proximityGains = new Map<number, number>()
/**
 * The last *direction* each person was in, alongside the gain above and for the same reason:
 * it outlives a peer being dropped and re-dialled as they pace in and out of range, so someone
 * walking back into earshot arrives from the side they actually walked in from.
 */
const proximityPlacements = new Map<number, SpatialPlacement>()
/**
 * The raw offsets those placements were computed from, in tiles, and which way you were facing
 * at the time.
 *
 * Kept because a placement is a *derived* thing — offset, rotated by your facing if you asked
 * for that — and the two settings that feed it can change while nobody is moving. Without the
 * inputs, turning "the room turns with me" on would leave everyone where they were until their
 * next footstep, which reads as the setting not working.
 */
const proximityOffsets = new Map<number, { x: number, y: number }>()
let myFacing: Facing = 'up'
/** Pending teardowns, so a peer who steps briefly out of range isn't dropped on the spot. */
const dropTimers = new Map<number, ReturnType<typeof setTimeout>>()
/**
 * The last mic/camera/screen state whispered by each person, peer or not.
 *
 * Whispers arrive on the presence channel and so reach us for everybody in the room, including
 * people we have no connection to. Keeping the newest one means a peer created later — when
 * they walk into range — starts with their icons already right, instead of showing an open mic
 * until they next happen to change something.
 */
const lastStates = new Map<number, StatePayload>()
/**
 * How long somebody has to stay out of range before their connection actually goes.
 *
 * Long enough that walking past the edge, or a whisper arriving late, doesn't cost a
 * renegotiation; short enough that a room of fifty doesn't accumulate connections to everyone
 * who ever wandered near you.
 */
const DROP_GRACE_MS = 3000

/**
 * Is this person outside earshot — near enough to be dialled, too far to be part of the room?
 *
 * That gap is real and deliberate: the connection opens at `CONNECT_TILES` and hearing stops at
 * `FAR_TILES`, so a peer at full connection strength and zero volume is the *normal* state of
 * somebody across the room, and being in a sealed zone you're not in scores zero at any distance.
 *
 * It used to be a purely audio distinction, and that was half a feature. Their voice faded out and
 * everything else about them didn't: their tile sat in the dock, their camera played, their screen
 * claimed the stage. Distance has to gate the whole person, or "proximity" only means volume.
 *
 * Always false outside a Side Space, where every peer's proximity is a flat 1.
 */
function outOfEarshot(peer: { proximity: number }): boolean {
  return proximityMode && peer.proximity <= 0
}

// --- adaptive screen-share sampling (mode 'auto'; see startScreenShare) ---
// A hidden <video> playing the shared track and a tiny canvas we down-sample it into, so the
// motion check compares a few hundred pixels a second rather than a whole screen. `prev` is
// the last down-sampled frame; `resolved` is the detail/motion we last actually applied, so
// we only touch the encoder when the guess flips.
let sampleTimer: ReturnType<typeof setInterval> | undefined
let sampleVideo: HTMLVideoElement | null = null
let sampleCanvas: HTMLCanvasElement | null = null
let samplePrev: Uint8ClampedArray | null = null
let resolvedScreenMode: 'detail' | 'motion' = 'detail'
/** How many samples in a row have disagreed with what's applied. See sampleScreen. */
let sampleDissent = 0


/**
 * Where the call's audio elements live: a container hung off <body>, outside the Vue tree.
 *
 * They have to be in the document — a detached element is not reliably played, and Chrome
 * additionally won't pump a remote MediaStream into WebAudio (our speaking indicator)
 * unless something is sinking it. And they have to be outside the component tree, because
 * a call outlives the page that started it: rendering them from VoiceChannel.vue would cut
 * the audio the moment you clicked into a text channel.
 */
function audioRoot(): HTMLElement {
  let root = document.getElementById('voice-audio')

  if (!root) {
    root = document.createElement('div')
    root.id = 'voice-audio'
    root.setAttribute('aria-hidden', 'true')
    document.body.appendChild(root)
  }

  return root
}

const sleep = (ms: number) => new Promise<void>(resolve => setTimeout(resolve, ms))

/**
 * How hard to chase a remembered mic before conceding to the default. A Bluetooth headset is
 * often enumerated a beat before it's actually ready to capture right after a page load, so
 * the first `exact` request can miss it — a couple of short retries catch that without adding
 * a delay anyone notices when the device is present (the common case succeeds first try).
 */
const MIC_RETRY_ATTEMPTS = 3
const MIC_RETRY_DELAY_MS = 300

/**
 * Open the microphone, honouring a remembered device *exactly*.
 *
 * `exact`, not `ideal`: an `ideal` deviceId is only advisory, and browsers were quietly
 * falling back to the system default on a fresh join even when the chosen device was right
 * there — so a reloaded call kept coming up on the built-in mic while the picker, reading the
 * still-stored id, went on naming the one you'd picked. `exact` makes the choice actually bite.
 *
 * The retry keeps (and sharpens) the promise `ideal` was there for. A device that's genuinely
 * gone falls back to whatever the browser will give us rather than failing the join — but a
 * device that's merely slow to wake, the Bluetooth case, is given a moment to appear first,
 * so a headset that reconnects on load still ends up being the mic you chose. A denied
 * permission is never a device problem, so it surfaces immediately without burning the retries.
 */
async function getMicStream(deviceId: string | null, level: NoiseSuppression): Promise<MediaStream> {
  const audio = micConstraints(level)

  if (deviceId) {
    for (let attempt = 0; attempt < MIC_RETRY_ATTEMPTS; attempt++) {
      try {
        return await navigator.mediaDevices.getUserMedia({
          audio: { deviceId: { exact: deviceId }, ...audio },
          video: false,
        })
      } catch (err: any) {
        // Only a missing / not-yet-ready device is worth waiting on; anything else (above all
        // a denied permission) is final and must surface as the failure it is.
        if (err?.name !== 'OverconstrainedError' && err?.name !== 'NotFoundError') throw err
        // Wait between tries, but not after the last one — that just delays the fallback.
        if (attempt < MIC_RETRY_ATTEMPTS - 1) await sleep(MIC_RETRY_DELAY_MS)
      }
    }
    // The remembered device never showed — fall through to the browser's default rather than
    // stranding the join on a mic that isn't there.
  }

  return navigator.mediaDevices.getUserMedia({ audio, video: false })
}

/**
 * The call's AudioContext, asked for at 48kHz.
 *
 * Left to itself an AudioContext runs at whatever the *output* device reports, which is 44.1kHz
 * on a great many machines. That is fine for everything else in the chain and fatal for the
 * neural denoiser, whose frequency bands are defined against 48kHz and which produces confident
 * nonsense at any other rate rather than failing — see {@link RNNOISE_SAMPLE_RATE}. 48kHz is
 * also what Opus encodes at, so asking for it costs a resample here and saves one later.
 *
 * The fallback is not decoration. A browser is entitled to refuse a rate the hardware can't
 * take, and some have historically thrown `NotSupportedError` outright — in which case a call
 * at the device's own rate with one level unavailable is obviously right, and no call at all
 * because we insisted on a sample rate is obviously wrong.
 */
function createCallContext(): AudioContext {
  try {
    return new AudioContext({ sampleRate: RNNOISE_SAMPLE_RATE })
  } catch {
    return new AudioContext()
  }
}

export function useVoice() {
  const api = useApi()
  const config = useRuntimeConfig()
  const echo: any = useNuxtApp().$echo
  const { user } = useAuth()
  const token = useAuthToken()
  const backgroundVoice = useBackgroundVoice()

  // Shared: the layout's "you're in a call" bar and the channel page both read this.
  const channelId = useState<number | null>('voice:channelId', () => null)
  const status = useState<'idle' | 'connecting' | 'connected' | 'error'>('voice:status', () => 'idle')
  const error = useState<string | null>('voice:error', () => null)
  // A short-lived line for something that happened *to* you — being disconnected by a
  // moderator, say — that outlives the call it's about and so can't ride on `error`.
  const notice = useState<string | null>('voice:notice', () => null)
  const peers = useState<Peer[]>('voice:peers', () => [])
  const selfMuted = useState<boolean>('voice:selfMuted', () => false)
  /**
   * Push-to-talk: the mic stays shut and only opens while the talk key is held. A remembered
   * preference (it belongs to your room, not to a call), with `pttHeld` the live "key is down
   * right now" — the two together are what {@link micOpen} decides on. The key listeners
   * themselves live on the call bar, which is mounted wherever you've wandered off to.
   */
  const pushToTalk = useState<boolean>('voice:pushToTalk', () => loadSettings().pushToTalk)
  const pttHeld = useState<boolean>('voice:pttHeld', () => false)
  const selfDeafened = useState<boolean>('voice:selfDeafened', () => false)
  const selfSpeaking = useState<boolean>('voice:selfSpeaking', () => false)
  // Where a screen comes from. `getDisplayMedia` in a browser or in Electron; a native capture
  // piped back as a MediaStream on a phone, which is what put screen sharing on the phone build
  // at all. Nothing below this line knows or cares which. See useDisplayCapture.
  const {
    supported: canShareScreen,
    unsupportedReason: screenShareUnavailableReason,
    probe: probeDisplayCapture,
    capture: captureDisplay,
    release: releaseDisplayCapture,
  } = useDisplayCapture()

  const screenStream = useState<MediaStream | null>('voice:screenStream', () => null)
  const cameraStream = useState<MediaStream | null>('voice:cameraStream', () => null)
  /**
   * Which way the camera is pointing, on a device that has more than one.
   *
   * Front by default everywhere, because a call is a face: `user` is what a laptop's only
   * camera is anyway, so this costs desktop nothing and gives a phone the right default.
   * Held here rather than inside startCamera so that switching sides survives turning the
   * camera off and on again — you flipped to the back camera to show someone something, and
   * it should still be the back camera a moment later.
   */
  const cameraFacing = useState<'user' | 'environment'>('voice:cameraFacing', () => 'user')
  /**
   * Whether flipping is worth offering — asked of the device, not guessed from the platform.
   *
   * `enumerateDevices` only labels cameras once permission has been granted, but it *counts*
   * them regardless, and a count is the whole question here. A laptop with one webcam gets no
   * button; a phone, and a desktop with two cameras plugged in, get one.
   */
  const cameraCount = useState('voice:cameraCount', () => 0)
  const canSwitchCamera = computed(() => cameraCount.value > 1)
  /**
   * The sound you're sharing with nothing to look at — a track playing in a tab, a video
   * everyone is listening to rather than watching.
   *
   * It rides the *same* pre-negotiated slot a screen share's audio does, which is what makes
   * it nearly free to add: the transceiver, the second <audio> element per peer and its own
   * volume control were all already there. The one thing that had to be new is saying so, so
   * that nobody is offered a screen to watch that is never coming.
   */
  const audioShareStream = useState<MediaStream | null>('voice:audioShareStream', () => null)
  /**
   * What this call plays for each person, and what it does for everyone else. Handed over on
   * join, and kept current by VoiceEffectsUpdated (see applyChannelEffects).
   */
  const voiceEffects = useState<VoiceEffects>('voice:channelEffects', () => ({ ...NO_EFFECTS }))
  /**
   * Which shared screens are on the stage right now — peer ids and/or `'self'`, empty when
   * you're watching nobody. Only these screens' audio is allowed to play (see applyAudio); the
   * stage UI keeps it in step via setWatchedScreens.
   *
   * A list rather than a single owner since the stage became a grid: several screens can be in
   * front of you at once, and each of them is something you asked to see, so each of them is
   * allowed to be heard.
   */
  const watchedScreens = useState<(number | 'self')[]>('voice:watchedScreens', () => [])

  // --- device & quality settings (yours, remembered across calls) ---

  /** The audio devices the browser will show you — refreshed on demand and on hot-plug. */
  const inputDevices = useState<MediaDeviceInfo[]>('voice:inputDevices', () => [])
  const outputDevices = useState<MediaDeviceInfo[]>('voice:outputDevices', () => [])
  /** Chosen device ids — null means "let the browser pick its default". */
  const micId = useState<string | null>('voice:micId', () => loadSettings().micId)
  const speakerId = useState<string | null>('voice:speakerId', () => loadSettings().speakerId)
  /**
   * Whether voices are placed around you rather than stacked in the centre — see
   * ~/lib/spatialAudio. Off by default: it changes how every call sounds, and a change like
   * that should be one you went and asked for.
   */
  const spatialAudio = useState<boolean>('voice:spatialAudio', () => loadSettings().spatialAudio)
  /** Whether this browser can do it at all. The setting is hidden rather than lying when not. */
  const canSpatialise = computed(() => spatialSupported())
  /**
   * How wide the sound field is, 0–1 — see DEFAULT_WIDTH. The dial for people who want the
   * separation without voices flying out to the edges of their head, and the honest answer for
   * anyone the full effect simply doesn't sit well with.
   */
  const spatialWidth = useState<number>('voice:spatialWidth', () => loadSettings().spatialWidth)
  /**
   * In a Side Space: does the room turn when your character does?
   *
   * Off by default, which is the third-person answer — up the screen is always "ahead", so what
   * you hear matches what you're looking at. On, it's first-person: turn to face someone and
   * they move to the front. Better for immersion, worse for the map, and enough of a
   * coin-flip between people that it's a switch rather than a decision made for you.
   */
  const spatialTurnsWithYou = useState<boolean>(
    'voice:spatialTurnsWithYou',
    () => loadSettings().spatialTurnsWithYou,
  )
  /** True in a Side Space, where positions come from where everyone is stood. */
  const roomPlacesPeople = useState<boolean>('voice:roomPlacesPeople', () => false)

  /**
   * How a *screen share* travels — 'mesh' (a copy to each viewer) or 'sfu' (one copy, fanned
   * out by a server). Voices and cameras are always peer-to-peer and aren't described by this.
   *
   * A live setting rather than a fixed decision: the sharer can move a running share either way
   * from the call bar, which is the point — you find out a share is struggling by watching it,
   * not by counting people beforehand.
   *
   * Deliberately *not* remembered between calls. Every join gets a fresh suggestion from the
   * server, which knows how busy this call is and what the admin allows; a stored preference
   * would only sit there contradicting it. Always names what is actually running, so a
   * fallback corrects it rather than leaving it lying.
   */
  const screenTransport = useState<TransportKind>('voice:screenTransport', () => 'mesh')

  /** How hard your microphone is cleaned up on the way out — see ~/lib/micProcessing. */
  const noiseSuppression = useState<NoiseSuppression>(
    'voice:noiseSuppression',
    () => loadSettings().noiseSuppression,
  )
  /**
   * How hard the "Aggressive" chain works, 0…1. Only meaningful on that level; kept whatever
   * the level is, so switching away and back doesn't lose where you'd set it.
   */
  const suppressionStrength = useState<number>(
    'voice:suppressionStrength',
    () => loadSettings().suppressionStrength,
  )
  /** Whether the chain rides your level toward everyone else's — see ~/lib/micProcessing. */
  const normalizeVolume = useState<boolean>(
    'voice:normalizeVolume',
    () => loadSettings().normalizeVolume,
  )
  /**
   * One fader over everybody, 0–1 — the call's own volume, on top of the machine's.
   *
   * It exists because the per-peer faders can't be the answer to "the call is too loud": they
   * are a decision about *one person relative to the others*, and turning all of them down one
   * at a time both undoes that comparison and has to be redone for every new person who joins.
   * The system volume can't be the answer either, since it takes the music and the video down
   * with it. So: multiplied into every peer's level in {@link applyAudio}, shared audio
   * included, and left out of `savePref` entirely — it belongs to you, not to a person.
   *
   * The default is deliberately under 1. A voice arriving at a browser's full scale is louder
   * than most of what people play through the same speakers, which is what "everyone is
   * shouting" turned out to mean; this leaves headroom to turn a quiet talker *up*.
   */
  const outputVolume = useState<number>('voice:outputVolume', () => loadSettings().outputVolume)
  const screenResolution = useState<ScreenResolution>('voice:screenResolution', () => loadSettings().resolution)
  const screenMode = useState<ScreenMode>('voice:screenMode', () => loadSettings().mode)
  /** Whether this browser can even honour an output-device choice (Chromium can; Firefox not). */
  const canPickSpeaker = computed(() =>
    typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype,
  )

  /**
   * Is audio actually leaving this machine? Muting is a hard no; past that, push-to-talk means
   * only while the key is held. Everything that gates the mic — the tracks, the speaking ring,
   * what peers are told — reads this rather than `selfMuted`, so the two ways of being quiet
   * can't drift apart.
   */
  const micOpen = computed(() => !selfMuted.value && (!pushToTalk.value || pttHeld.value))

  const inCall = computed(() => status.value === 'connected' || status.value === 'connecting')
  const isSharing = computed(() => screenStream.value !== null)
  const isCameraOn = computed(() => cameraStream.value !== null)
  const isAudioSharing = computed(() => audioShareStream.value !== null)
  /** Whoever is sharing right now — at most one screen is on the stage at a time. */
  const sharingPeer = computed(() => peers.value.find(p => p.screenSharing && p.screen) ?? null)

  // --- entrance and exit effects ---

  const effects = useVoiceEffects()
  // A call happens inside a server or a chat like everything else, so an effect announcing
  // somebody should call them whatever this place calls them.
  const { nameOf } = useNicknames()

  /**
   * What this call does about *this person* coming or going: whatever the owner attached to
   * them, and failing that whatever the room does for anybody.
   */
  function effectFor(userId: number, phase: 'join' | 'leave'): VoiceEffectPair['join'] {
    const mine = voiceEffects.value.people.find(p => p.user_id === userId)

    return (mine ? mine[phase] : null) ?? voiceEffects.value.default[phase]
  }

  /**
   * Somebody arrived or left: play whatever this room does about them.
   *
   * Everyone in the call runs this off their own presence event, so the effect goes off for
   * all of them within a frame or two of each other without a single message being sent about
   * it. Deafening yourself takes the sound and leaves the picture — you silenced the room's
   * speakers, not its lights.
   */
  function fireEffect(phase: 'join' | 'leave', userId: number, name: string) {
    const effect = effectFor(userId, phase)
    if (!effect) return

    effects.fire(effect, phase, name, { silent: selfDeafened.value })
  }

  /**
   * Adopt an effect change made while the call is already running — the owner may not even be
   * in it, which is why this arrives on the container's broadcast rather than a whisper
   * between the people talking. Ignored unless it's about the call we're actually in.
   */
  function applyChannelEffects(id: number, next: VoiceEffects) {
    if (channelId.value !== id) return

    voiceEffects.value = {
      default: { join: next.default?.join ?? null, leave: next.default?.leave ?? null },
      people: next.people ?? [],
    }
  }

  /** Everything this channel plays, for the owner's settings dialog. Any member may read it. */
  function loadChannelEffects(id: number) {
    return api<{ data: VoiceEffects }>(`/api/channels/${id}/voice/effects`).then(res => res.data)
  }

  /**
   * Attach an effect to one person — or, with `userId` null, set what the room does for
   * everybody nobody has singled out. Owner only: the server refuses anyone else, and the
   * settings UI is only offered to them.
   *
   * Nothing is applied locally here. The server broadcasts the new payload to every member,
   * ourselves included, so the one that lands is the one everybody has.
   */
  async function setChannelEffects(id: number, target: { userId: number | null } & VoiceEffectPair) {
    const { data } = await api<{ data: VoiceEffects }>(`/api/channels/${id}/voice/effects`, {
      method: 'PATCH',
      body: {
        user_id: target.userId,
        join_effect: target.join,
        leave_effect: target.leave,
      },
    })

    applyChannelEffects(id, data)

    return data
  }

  // --- local preferences (yours, about other people) ---

  function loadPrefs(): Record<number, LocalPrefs> {
    if (typeof localStorage === 'undefined') return {}
    try {
      return JSON.parse(localStorage.getItem('voice:prefs') ?? '{}')
    } catch {
      return {}
    }
  }

  /**
   * Remember one thing you've decided about somebody, leaving the rest alone.
   *
   * Merged rather than replaced: these are four independent decisions (how loud they are,
   * whether you've muted them, and the same pair for what they're sharing), and a caller
   * that has to restate all four to change one is a caller that will eventually drop one.
   */
  function savePref(userId: number, pref: Partial<LocalPrefs>) {
    const prefs = loadPrefs()
    prefs[userId] = { volume: 1, muted: false, ...prefs[userId], ...pref }
    localStorage.setItem('voice:prefs', JSON.stringify(prefs))
  }

  // --- device & quality settings storage ---

  /**
   * Read the remembered device & quality choices, healing anything unexpected.
   *
   * These are read at composable setup to seed the reactive state, so they run on the server
   * too — hence the localStorage guard — and they must never throw on a hand-edited or
   * half-written blob: an unknown resolution or mode falls back to its default rather than
   * poisoning every future call.
   */
  function loadSettings(): {
    micId: string | null
    speakerId: string | null
    noiseSuppression: NoiseSuppression
    suppressionStrength: number
    normalizeVolume: boolean
    outputVolume: number
    spatialAudio: boolean
    spatialWidth: number
    spatialTurnsWithYou: boolean
    resolution: ScreenResolution
    mode: ScreenMode
    pushToTalk: boolean
  } {
    const fallback = {
      micId: null,
      speakerId: null,
      noiseSuppression: DEFAULT_NOISE_SUPPRESSION,
      suppressionStrength: DEFAULT_SUPPRESSION_STRENGTH,
      normalizeVolume: DEFAULT_NORMALIZE_VOLUME,
      outputVolume: DEFAULT_OUTPUT_VOLUME,
      spatialAudio: false,
      spatialWidth: DEFAULT_WIDTH,
      spatialTurnsWithYou: false,
      resolution: DEFAULT_SCREEN_RESOLUTION,
      mode: DEFAULT_SCREEN_MODE,
      pushToTalk: false,
    }
    if (typeof localStorage === 'undefined') return fallback
    try {
      const saved = JSON.parse(localStorage.getItem('voice:settings') ?? '{}')
      return {
        micId: typeof saved.micId === 'string' ? saved.micId : null,
        speakerId: typeof saved.speakerId === 'string' ? saved.speakerId : null,
        noiseSuppression: NOISE_SUPPRESSION_LEVELS.includes(saved.noiseSuppression)
          ? saved.noiseSuppression
          : DEFAULT_NOISE_SUPPRESSION,
        suppressionStrength: typeof saved.suppressionStrength === 'number'
          ? clampStrength(saved.suppressionStrength)
          : DEFAULT_SUPPRESSION_STRENGTH,
        normalizeVolume: typeof saved.normalizeVolume === 'boolean'
          ? saved.normalizeVolume
          : DEFAULT_NORMALIZE_VOLUME,
        outputVolume: typeof saved.outputVolume === 'number' && saved.outputVolume >= 0 && saved.outputVolume <= 1
          ? saved.outputVolume
          : DEFAULT_OUTPUT_VOLUME,
        spatialAudio: saved.spatialAudio === true,
        spatialWidth: typeof saved.spatialWidth === 'number' && saved.spatialWidth >= 0 && saved.spatialWidth <= 1
          ? saved.spatialWidth
          : DEFAULT_WIDTH,
        spatialTurnsWithYou: saved.spatialTurnsWithYou === true,
        resolution: SCREEN_RESOLUTIONS.includes(saved.resolution) ? saved.resolution : DEFAULT_SCREEN_RESOLUTION,
        mode: (['auto', 'detail', 'motion'] as const).includes(saved.mode) ? saved.mode : DEFAULT_SCREEN_MODE,
        pushToTalk: saved.pushToTalk === true,
      }
    } catch {
      return fallback
    }
  }

  function saveSettings() {
    if (typeof localStorage === 'undefined') return
    localStorage.setItem('voice:settings', JSON.stringify({
      micId: micId.value,
      speakerId: speakerId.value,
      noiseSuppression: noiseSuppression.value,
      suppressionStrength: suppressionStrength.value,
      normalizeVolume: normalizeVolume.value,
      outputVolume: outputVolume.value,
      spatialAudio: spatialAudio.value,
      spatialWidth: spatialWidth.value,
      spatialTurnsWithYou: spatialTurnsWithYou.value,
      resolution: screenResolution.value,
      mode: screenMode.value,
      pushToTalk: pushToTalk.value,
    }))
  }

  // --- peer state helpers ---

  function patchPeer(id: number, changes: Partial<Peer>) {
    const idx = peers.value.findIndex(p => p.id === id)
    if (idx === -1) return
    peers.value.splice(idx, 1, { ...peers.value[idx]!, ...changes })
  }

  /**
   * Start keeping a copy of what the call sounds like, for the echo canceller to subtract.
   *
   * Built only while it's needed, because it isn't free: two WebAudio source nodes per person,
   * and they exist purely so a share can be cleaned up. Everyone already dialled is tapped here
   * and anyone who joins later is tapped by {@link tapPeer}, which addPeer calls.
   *
   * The gains mirror what {@link applyAudio} put on the elements, and are kept mirrored by it,
   * because the reference has to be the sound *as played*: someone you've turned down or muted
   * contributes that much less echo, and telling the canceller otherwise would have it
   * subtracting a voice that was never in the room.
   */
  function openReferenceMix(): GainNode | null {
    if (!audioCtx) return null
    if (referenceMix) return referenceMix

    referenceMix = audioCtx.createGain()
    handles.forEach((_handle, id) => tapPeer(id))

    return referenceMix
  }

  /** Put the tap away. The canceller is torn down first — see stopScreenShare. */
  function closeReferenceMix() {
    handles.forEach((handle) => {
      handle.refTaps?.voice.disconnect()
      handle.refTaps?.screen.disconnect()
      handle.refTaps = null
    })
    referenceMix?.disconnect()
    referenceMix = null
  }

  /** Add one person to the reference mix. A no-op when nothing is listening for it. */
  function tapPeer(id: number) {
    const handle = handles.get(id)
    if (!handle || handle.refTaps || !referenceMix || !audioCtx) return

    const voice = audioCtx.createGain()
    const screen = audioCtx.createGain()
    voice.gain.value = 0
    screen.gain.value = 0

    // Sourced from the streams rather than the elements deliberately. A MediaElementAudioSource
    // *redirects* an element's output into the graph — it would take the call out of the
    // speakers and hand us responsibility for putting it back, sink selection and all — whereas
    // a MediaStreamAudioSource is a second reader of the same stream and changes nothing.
    audioCtx.createMediaStreamSource(handle.audioStream).connect(voice)
    audioCtx.createMediaStreamSource(handle.screenAudioStream).connect(screen)
    voice.connect(referenceMix)
    screen.connect(referenceMix)

    handle.refTaps = { voice, screen }
    applyAudio(id) // push the levels they're currently being heard at
  }

  /* ----------------------------------------------------------------- recording the call */

  /**
   * The mixed audio of the call, for a recording.
   *
   * Lives here because this composable owns the audio graph: the peers' streams, the microphone
   * after its effect chain, and the AudioContext they all run in. A recorder outside it could
   * only reach the `<audio>` elements, and `createMediaElementSource` *redirects* an element's
   * output into the graph — it would take the call out of the speakers and hand the recorder
   * responsibility for putting it back, sink selection and all. Stream sources are second
   * readers of the same streams and change nothing anybody hears.
   *
   * It stays live for the whole recording: {@link tapRecording} is called for peers who arrive
   * *after* it starts, so somebody joining mid-meeting is on the tape. A snapshot taken at start
   * would silently omit exactly the people who turn up when the meeting actually begins.
   *
   * Deliberately **not** routed through per-peer volume or local mute — see `recTaps`.
   */
  let recordingMix: MediaStreamAudioDestinationNode | null = null
  let recordingLocal: MediaStreamAudioSourceNode | null = null

  function tapRecording(id: number) {
    const handle = handles.get(id)
    if (!handle || handle.recTaps || !recordingMix || !audioCtx) return

    const voice = audioCtx.createMediaStreamSource(handle.audioStream)
    const screen = audioCtx.createMediaStreamSource(handle.screenAudioStream)
    voice.connect(recordingMix)
    screen.connect(recordingMix)

    handle.recTaps = { voice, screen }
  }

  /**
   * Start mixing, and hand back the stream to encode.
   *
   * Null when there is no audio graph yet — you are not in a call, and there is nothing to
   * record. The caller treats that as "can't", not as an error.
   */
  function startRecordingMix(): MediaStream | null {
    if (!audioCtx) return null
    if (recordingMix) return recordingMix.stream

    recordingMix = audioCtx.createMediaStreamDestination()

    // Your own microphone, taken *after* the effect chain, so the recording contains what the
    // room actually heard rather than what your mic picked up.
    if (localStream) {
      recordingLocal = audioCtx.createMediaStreamSource(localStream)
      recordingLocal.connect(recordingMix)
    }

    for (const id of handles.keys()) tapRecording(id)

    return recordingMix.stream
  }

  function stopRecordingMix() {
    recordingLocal?.disconnect()
    recordingLocal = null

    handles.forEach((handle) => {
      handle.recTaps?.voice.disconnect()
      handle.recTaps?.screen.disconnect()
      handle.recTaps = null
    })

    recordingMix?.disconnect()
    recordingMix = null
  }

  /**
   * Apply a peer's audio settings to their audio element.
   *
   * This *is* per-peer mute and per-peer volume: one <audio> per person, so turning one
   * of them down is a property assignment and cannot possibly affect anybody else.
   * Deafening yourself is the same operation applied to all of them at once.
   *
   * And it's why a Side Space's proximity audio costs almost nothing: walking away from
   * somebody is the same property assignment, made sixty times a second. `volume` is your
   * standing decision about this person, `proximity` is where you're both stood; multiplying
   * them keeps both intact, so turning someone down stays turned down as you walk about, and
   * walking off doesn't overwrite the fact you'd turned them down.
   */
  function applyAudio(id: number) {
    const handle = handles.get(id)
    const peer = peers.value.find(p => p.id === id)
    if (!handle || !peer) return

    const muted = peer.localMuted || selfDeafened.value
    // Three independent things, multiplied so that none of them overwrites another: your
    // standing decision about this person, where you're both stood, and how loud the call is
    // as a whole. See `outputVolume` for why the last one isn't just the system volume.
    const level = peer.volume * peer.proximity * outputVolume.value

    if (handle.spatial) {
      // The element is kept muted rather than removed, and everything audible moves to the
      // WebAudio graph — including mute, which becomes a gain of zero. Two places that can
      // silence someone is one too many, and the element is the one that can't also pan.
      handle.audio.muted = true
      handle.audio.volume = 1
      handle.spatial.setPlacement(peer.placement, spatialWidth.value)
      // Distance costs loudness only for placements *you* made. In a Side Space `proximity`
      // is already a distance curve — one that knows about walls, which this doesn't — and
      // charging for the same distance twice would make far-off people vanish early.
      handle.spatial.setGain(muted ? 0 : level * (proximityMode ? 1 : distanceGain(peer.placement.distance)))
    } else {
      handle.audio.volume = level
      handle.audio.muted = muted
    }
    // The shared audio still answers to *mute* and *deafen* — silencing someone silences
    // their screen too — but rides its own volume so a loud shared clip can be turned down
    // without quietening the person talking over it. See setPeerScreenVolume.
    //
    // It also plays *only while you're watching that screen*: clicking "Stop watching" (or
    // switching the stage to someone else) hides the picture, and this is what stops the
    // sound coming with it — otherwise a screen you'd closed kept playing audio out of a
    // stream you couldn't see. See setWatchedScreens.
    //
    // An audio-only share is exempt, and has to be: there is no picture, so there is nothing
    // to be watching, and gating it on the stage would mean nobody ever heard it. Someone
    // sharing sound alone is heard the moment they start, like a person talking.
    //
    // `screenMuted` is the listener's own veto on top of all that — "keep talking, but I've
    // heard enough of your music". Yours alone, never sent, and remembered for next time.
    //
    // Distance is not a fader here, it's a gate. A shared screen's sound is deliberately *not*
    // scaled by `proximity` — something you chose to watch shouldn't fade to a silent film because
    // the person sharing it paced across the room — but somebody you cannot hear at all is
    // somebody you are not standing with, and a room where a stranger's music reaches you from two
    // rooms away isn't a room. So: full volume while they're audible, nothing when they're not.
    //
    // Only in a Side Space, where "out of earshot" is a thing that exists. In a voice channel or a
    // DM every peer's proximity is a flat 1 and this is the same expression it always was.
    handle.screenAudio.volume = peer.screenVolume * outputVolume.value
    handle.screenAudio.muted = muted
      || peer.screenMuted
      || outOfEarshot(peer)
      || (peer.screenSharing && !watchedScreens.value.includes(id))

    // And the same two decisions again, onto the echo canceller's copy of them, so that what it
    // subtracts is what your speakers are being asked to play. Only ever present mid-share; see
    // openReferenceMix.
    if (handle.refTaps) {
      handle.refTaps.voice.gain.value = muted ? 0 : level
      handle.refTaps.screen.gain.value = handle.screenAudio.muted ? 0 : handle.screenAudio.volume
    }
  }

  // --- spatial audio: where each voice comes from (see ~/lib/spatialAudio) ---

  /**
   * Give this peer a positioned voice, if they should have one and don't yet.
   *
   * Called from three places, and it has to be all three: when their audio track arrives (the
   * usual one — a MediaStreamAudioSourceNode can't be built from a stream with no track in
   * it), when the setting is switched on mid-call, and when a peer is created while it's
   * already on. Idempotent, so none of them has to know about the others.
   */
  function ensureSpatial(id: number) {
    const handle = handles.get(id)
    const peer = peers.value.find(p => p.id === id)
    if (!handle || !peer || handle.spatial || !audioCtx) return
    if (!spatialAudio.value || !canSpatialise.value) return

    handle.spatial = createSpatialVoice(audioCtx, handle.audioStream, peer.placement, spatialWidth.value)
    // Null means the browser wouldn't build it (or the track isn't there yet). The <audio>
    // element is still playing them the ordinary way, so this is a missing feature and not a
    // missing person — applyAudio takes the non-spatial branch and everything works.
    if (handle.spatial) applyAudio(id)
  }

  /** Take the positioned path back down and hand playback to the element again. */
  function dropSpatial(id: number) {
    const handle = handles.get(id)
    if (!handle?.spatial) return

    handle.spatial.destroy()
    handle.spatial = null
    applyAudio(id)
  }

  /**
   * Where somebody sits when they're created — theirs if you've ever placed them, the
   * automatic arrangement's otherwise.
   *
   * In a Side Space neither applies: the room decides, and until their first position whisper
   * lands they sit dead ahead rather than somewhere arbitrary.
   */
  function initialPlacement(id: number): { placement: SpatialPlacement, placed: boolean } {
    // A placement you made yourself outranks both automatic sources — the arc in a channel and
    // the room in a Side Space. It's the same stored preference either way: "where I like to
    // hear this person from" is one decision, not one per kind of call.
    const pref = loadPrefs()[id]
    if (typeof pref?.angle === 'number') {
      return {
        placement: { angle: pref.angle, distance: pref.distance ?? DEFAULT_PLACEMENT.distance },
        placed: true,
      }
    }

    if (proximityMode) {
      return { placement: proximityPlacements.get(id) ?? { ...DEFAULT_PLACEMENT, distance: 0 }, placed: false }
    }

    return { placement: { ...DEFAULT_PLACEMENT }, placed: false }
  }

  /**
   * Re-spread everyone you *haven't* placed yourself across the arc in front of you.
   *
   * Run whenever the roster changes, because an arrangement is a division of the space and
   * the space has to be re-divided when the number of people sharing it changes — two voices
   * hard left and hard right shouldn't stay that way when a third arrives.
   *
   * Anyone you dragged is left exactly where you put them, and is not counted in the spread:
   * your decision is the fixed point the automatic layout works around, not something it
   * averages over.
   */
  function restackUnplaced() {
    if (proximityMode) return

    const loose = peers.value.filter(p => !p.placed)
    if (!loose.length) return

    const arranged = arrangeInArc(loose.map(p => p.id))
    for (const [id, placement] of arranged) {
      patchPeer(id, { placement })
      applyAudio(id)
    }
  }

  /**
   * Move somebody. Yours alone, never sent, and remembered — this is the same kind of
   * decision as turning a person down, and it's stored next to it.
   *
   * In a Side Space this *pins* them: their voice stops following them round the room and
   * stays where you put it, until you send them back to auto with {@link unplacePeer}. Which
   * sounds like it defies the room, and does — deliberately. Someone you're actually trying to
   * talk to while the room mills about is exactly the case where "realistic" is the wrong
   * goal, and it's the same reason per-person volume is allowed to override distance.
   */
  function setPeerPlacement(id: number, placement: SpatialPlacement) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped: SpatialPlacement = {
      angle: placement.angle,
      distance: Math.min(1, Math.max(0, placement.distance)),
    }

    patchPeer(id, { placement: clamped, placed: true })
    applyAudio(id)
    savePref(id, { angle: clamped.angle, distance: clamped.distance })
  }

  /**
   * Hand one person back to whichever automatic source this call has — the arc in a channel,
   * the room in a Side Space. The per-person counterpart to {@link resetPlacements}.
   */
  function unplacePeer(id: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    patchPeer(id, { placed: false })
    savePref(id, { angle: undefined, distance: undefined })

    if (proximityMode) {
      const placement = spacePlacementFor(id) ?? peer.placement
      patchPeer(id, { placement })
      applyAudio(id)
      return
    }

    restackUnplaced()
  }

  /**
   * Throw the room away and lay it out again from scratch — including the people you placed.
   *
   * The escape hatch for an arrangement that has drifted somewhere unhelpful over a few
   * calls. It forgets the stored placements too; anything less would leave the automatic
   * layout fighting a preference you can no longer see.
   */
  function resetPlacements() {
    for (const peer of peers.value) {
      patchPeer(peer.id, { placed: false })
      savePref(peer.id, { angle: undefined, distance: undefined })
    }

    if (proximityMode) restackRoom()
    else restackUnplaced()
  }

  /**
   * Where a Side Space occupant sits, from the last offset we had for them.
   *
   * The whole derivation in one place, so the four things that can change it — they moved, you
   * moved, you turned, you flipped the setting — all end up at the same answer.
   */
  function spacePlacementFor(id: number): SpatialPlacement | null {
    const offset = proximityOffsets.get(id)
    if (!offset) return null

    const placement = placementFromOffset(offset.x, offset.y)

    return spatialTurnsWithYou.value ? rotatePlacement(placement, myFacing) : placement
  }

  /** Re-seat everybody in a Side Space, after something that moves all of them at once. */
  function restackRoom() {
    if (!proximityMode) return

    for (const peer of peers.value) {
      const placement = spacePlacementFor(peer.id)
      if (!placement) continue

      // Kept up to date even for someone you've pinned: it's where they'd go back to, and
      // un-pinning them shouldn't drop them at wherever the room last happened to say.
      proximityPlacements.set(peer.id, placement)
      if (peer.placed) continue

      patchPeer(peer.id, { placement })
      applyAudio(peer.id)
    }
  }

  /**
   * How wide the sound field is. Takes effect immediately on every voice — it's a single
   * multiplier inside the panner, so this costs one ramp per person, not a rebuild.
   */
  function setSpatialWidth(width: number) {
    spatialWidth.value = Math.min(1, Math.max(0, width))
    saveSettings()

    for (const id of handles.keys()) applyAudio(id)
  }

  /** First-person or third-person listening in a Side Space. See spatialTurnsWithYou. */
  function setSpatialTurnsWithYou(on: boolean) {
    spatialTurnsWithYou.value = on
    saveSettings()
    restackRoom()
  }

  /** Turn spatial audio on or off. Remembered, and applied to a live call on the spot. */
  function setSpatialAudio(on: boolean) {
    spatialAudio.value = on
    saveSettings()

    if (on) {
      restackUnplaced()
      for (const id of handles.keys()) ensureSpatial(id)
    } else {
      for (const id of handles.keys()) dropSpatial(id)
    }
  }

  // --- remote control transport (the protocol itself lives in useRemoteControl) ---

  /**
   * Push one input event to the person whose screen we're driving.
   *
   * Drops the event rather than buffering when the channel isn't open yet or its send buffer is
   * backing up. That's deliberate: these are pointer positions, and a stale one is worse than
   * none — replaying a queue after a stall would drag the sharer's cursor through where the
   * controller used to be. The next `move` is along in ~16ms anyway.
   */
  function sendControlInput(to: number, input: ControlInput) {
    activeTransport?.sendControl(to, input)
  }

  /** Ask for / grant / refuse / end control. Whispered — see the listener in connect(). */
  function sendControlSignal(to: number, kind: ControlSignal['kind']) {
    if (!presence || !user.value) return
    presence.whisper('control', { from: user.value.id, to, kind } satisfies ControlSignal)
  }

  /** Register the remote-control state machine's two inboxes. Registering again replaces. */
  function onControl(handlers: {
    input?: (from: number, input: ControlInput) => void
    signal?: (signal: ControlSignal) => void
  }) {
    controlInputHandler = handlers.input ?? null
    controlSignalHandler = handlers.signal ?? null
  }

  /** Whether a peer's input pipe is actually up — "Request control" shouldn't offer otherwise. */
  function controlChannelReady(id: number) {
    return activeTransport?.controlReady(id) ?? false
  }

  /** Tell the people in the call what my mic, camera and screen are doing, right now. */
  function whisperState() {
    if (!presence || !user.value) return
    presence.whisper('state', {
      id: user.value.id,
      // What peers see is whether the line is actually open: on push-to-talk that's the key,
      // not the mic button.
      muted: !micOpen.value,
      deafened: selfDeafened.value,
      screen_sharing: isSharing.value,
      screen_transport: screenTransport.value,
      sfu: sfuAnnouncement,
      camera_on: isCameraOn.value,
      audio_sharing: isAudioSharing.value,
    } satisfies StatePayload)
  }

  /** …and tell the server, so the sidebar shows it to people who aren't in the call. */
  async function publishState() {
    if (!channelId.value) return
    whisperState()
    try {
      await api(`/api/channels/${channelId.value}/voice/state`, {
        method: 'PATCH',
        body: {
          muted: !micOpen.value,
          deafened: selfDeafened.value,
          screen_sharing: isSharing.value,
          camera_on: isCameraOn.value,
          audio_sharing: isAudioSharing.value,
        },
      })
    } catch {
      // The call is unaffected — this only drives someone else's sidebar icon.
    }
  }

  // --- peers ---

  /**
   * Start hearing somebody, and build the local machinery that plays them.
   *
   * Two halves that used to be one function. The transport is told to open a connection (in a
   * mesh that dials them; behind an SFU it subscribes), and everything here is the *other*
   * half — the elements, streams, analyser and roster entry that a track will need somewhere
   * to land when it arrives. Nothing below knows or cares which transport is running.
   */
  function createPeer(id: number, name: string, avatar: string | null) {
    if (handles.has(id) || !user.value) return

    const audioStream = new MediaStream()
    const screenAudioStream = new MediaStream()
    const cameraStream = new MediaStream()
    const screenStream = new MediaStream()

    const audio = new Audio()
    audio.autoplay = true
    audio.srcObject = audioStream
    audioRoot().appendChild(audio)

    // A second element for the shared tab/system audio, so it plays independently of the
    // microphone and answers to the same per-peer volume and mute (see applyAudio).
    const screenAudio = new Audio()
    screenAudio.autoplay = true
    screenAudio.srcObject = screenAudioStream
    audioRoot().appendChild(screenAudio)

    const handle: PeerHandle = {
      audio,
      audioStream,
      screenAudio,
      screenAudioStream,
      cameraStream,
      screenStream,
      analyser: null,
      spatial: null,
      refTaps: null,
      recTaps: null,
      speakingUntil: 0,
    }

    // If a speaker was chosen, route this peer's two audio elements to it as they're born —
    // otherwise a device picked before someone joined wouldn't reach the people who arrive
    // after. Best-effort: setSinkId is Chromium-only and rejects if the id has gone stale.
    if (speakerId.value) {
      void applySinkId(audio, speakerId.value)
      void applySinkId(screenAudio, speakerId.value)
    }

    const pref = loadPrefs()[id]

    handles.set(id, handle)
    peers.value = [...peers.value, {
      id,
      name,
      avatar,
      camera: null,
      screen: null,
      connection: 'connecting',
      speaking: false,
      muted: false,
      deafened: false,
      screenSharing: false,
      cameraOn: false,
      audioSharing: false,
      localMuted: pref?.muted ?? false,
      volume: pref?.volume ?? 1,
      screenVolume: pref?.screenVolume ?? 1,
      screenMuted: pref?.screenMuted ?? false,
      // Full volume until the room says otherwise. In a voice channel or a DM nothing ever
      // does, which is how those two keep behaving exactly as they always have.
      proximity: proximityGains.get(id) ?? 1,
      ...initialPlacement(id),
    }]

    // Whatever they last said they were doing, if we heard it before we had a peer to hang it
    // on — which in a Side Space is the normal case. See lastStates.
    const state = lastStates.get(id)
    if (state) {
      patchPeer(id, {
        muted: state.muted,
        deafened: state.deafened,
        screenSharing: state.screen_sharing,
        cameraOn: state.camera_on,
        audioSharing: state.audio_sharing,
      })

      // They were already sharing through the media server before we had a peer for them —
      // the Side Space case, where people are dialled as you walk up to them.
      if (state.screen_sharing && state.screen_transport === 'sfu') void openScreenSfu()
    }

    // Push the starting volume onto the element. Easy to think unnecessary — the peer object
    // already carries the right `proximity` — but nothing has yet *applied* it, and
    // setPeerProximity won't: it early-returns when the gain hasn't changed, which is exactly
    // the case for somebody dialled at the gain we already computed for them. Without this a
    // peer created mid-room plays at full volume until they next move.
    applyAudio(id)
    // If a share is currently having the call subtracted out of it, this person has to be part
    // of what's subtracted — they're about to be coming out of the speakers like everyone else.
    tapPeer(id)
    // And if the call is being recorded, somebody arriving mid-meeting belongs on the tape.
    tapRecording(id)

    // A new arrival changes how the room divides up, and may already have audio (a peer
    // re-created in a Side Space adopts a stream that's still running). Both are cheap no-ops
    // when there's nothing to do.
    restackUnplaced()
    ensureSpatial(id)

    // And open the pipes. Last, so nothing can arrive before there's anywhere to put it.
    //
    // *Both* transports, because they carry different halves of the same person: the mesh has
    // their voice, and the SFU — if a share is running through one — has their screen. Telling
    // only the mesh is invisible with LiveKit, which subscribes to everything on its own, and
    // fatal with Cloudflare, which pulls nothing it wasn't explicitly asked for.
    subscribeEverywhere(id, true)
  }

  /** Stop hearing somebody: close the pipe, then dismantle what was playing them. */
  function destroyPeer(id: number) {
    const handle = handles.get(id)
    if (!handle) return

    // Claimed before anything else, because this re-enters: dropping the peer makes the
    // transport emit `peerLeft`, which lands right back here. Deleting first means the second
    // pass returns at the guard above instead of tearing the same person down twice.
    handles.delete(id)

    subscribeEverywhere(id, false)

    handle.audio.srcObject = null
    handle.audio.remove()
    handle.screenAudio.srcObject = null
    handle.screenAudio.remove()
    handle.analyser?.disconnect()
    handle.spatial?.destroy()

    peers.value = peers.value.filter(p => p.id !== id)

    // One fewer voice to share the arc between. Skipped during teardown, where every peer is
    // going and re-spreading the survivors is work nobody will hear.
    if (status.value === 'connected') restackUnplaced()
  }

  /**
   * Sort a track the transport handed us into the stream it belongs in.
   *
   * The `kind` is the transport's answer to a question a MediaStreamTrack cannot answer for
   * itself — a track carries nothing that says "webcam" rather than "screen". A mesh works it
   * out from the transceiver it arrived on, an SFU from the publication's source; either way
   * this end is told rather than guessing.
   */
  function receiveTrack(id: number, kind: TrackKind, track: MediaStreamTrack) {
    const handle = handles.get(id)
    if (!handle) return

    if (kind === 'screenAudio') {
      replaceTrackIn(handle.screenAudioStream, track, handle.screenAudio)
      handle.screenAudio.play().catch(() => {})
      applyAudio(id)
      return
    }

    if (kind === 'mic') {
      replaceTrackIn(handle.audioStream, track, handle.audio)
      handle.audio.play().catch(() => {
        // Autoplay policy. Joining a call is a user gesture, so this shouldn't fire — and if
        // it somehow does, the next click anywhere in the page will unblock it.
      })
      listenForSpeech(handle)
      // Their audio exists now, which is the earliest moment a positioned voice can be built
      // from it — see ensureSpatial.
      ensureSpatial(id)
      applyAudio(id)
      return
    }

    const target = kind === 'camera' ? handle.cameraStream : handle.screenStream
    replaceTrackIn(target, track)

    // Vue must not proxy a MediaStream: the DOM rejects the proxy on `srcObject`.
    patchPeer(id, kind === 'camera'
      ? { camera: markRaw(target) }
      : { screen: markRaw(target) })
  }

  /** The context every transport is opened with — the same facts, whichever one it is. */
  function transportContext(): TransportContext {
    return {
      channelId: channelId.value ?? 0,
      selfId: user.value?.id ?? 0,
      iceServers,
      sfu: sfuCredentials,
      proximity: proximityMode,
    }
  }

  /**
   * Open the call.
   *
   * The mesh is not optional and never was: it carries every voice and every camera for the
   * whole life of the call. What the server proposed only decides where the *screen* starts,
   * and that is a switch the sharer can throw at any point (see {@link setScreenTransport}).
   */
  async function openTransport(proposed: TransportKind) {
    activeTransport = createMeshTransport({
      signaling: meshSignaling,
      // In a Side Space, being offered to is evidence we're in range: the far end computed the
      // same distance we would have. Elsewhere nobody should be dialling us unannounced.
      accepts: peerId => proximityMode && roomMembers.has(peerId),
    })

    await activeTransport.connect(transportContext(), transportEvents())

    // Only a suggestion, and only about the screen. Honoured when the server offered
    // credentials to honour it with; the SFU itself isn't dialled until a share needs it.
    screenTransport.value = proposed === 'sfu' && sfuCredentials ? 'sfu' : 'mesh'

    await republish()
  }

  /**
   * Bring up the SFU, for the screen and nothing else.
   *
   * Connected lazily — on the first share that wants it rather than on joining — because most
   * calls never share a screen at all, and a room nobody publishes into is a connection, a
   * participant and a minute of somebody's quota spent on nothing.
   *
   * Its event set is deliberately not the mesh's. It reports *only* arriving screen media: the
   * mesh owns who is in this call, and letting a second transport create or destroy peers would
   * mean somebody's voice being torn down because they stopped sharing.
   */
  async function openScreenSfu(): Promise<VoiceTransport | null> {
    if (sfuTransport) return sfuTransport
    if (sfuOpening) return sfuOpening
    if (!sfuCredentials) return null

    sfuOpening = buildScreenSfu()

    try {
      return await sfuOpening
    } finally {
      sfuOpening = null
    }
  }

  /** The actual work, called once per open — see the guard in openScreenSfu. */
  async function buildScreenSfu(): Promise<VoiceTransport | null> {
    if (!sfuCredentials) return null

    const sfu = sfuCredentials.driver === 'cloudflare'
      ? createCloudflareTransport({
        // Called through a plainly-typed reference on purpose: $fetch's route-and-method
        // generics recurse to an "excessive stack depth" error on a union method, and nothing
        // here benefits from inferring a response type the relay passes straight through.
        api: (path, body, method = 'POST') => (api as unknown as (
          url: string,
          options: { method: string, body: unknown },
        ) => Promise<any>)(
          `/api/channels/${channelId.value}/voice/sfu/${path}`,
          { method, body },
        ),
        // Our coordinates go out with the next state whisper, and one is sent immediately so
        // peers don't wait on an unrelated mute or camera toggle to learn where to pull from.
        announce: (announcement) => {
          sfuAnnouncement = announcement
          whisperState()
        },
        onAnnounce: (handler) => {
          sfuAnnounceHandler = handler

          // Anything already heard, replayed — a share may well have been announced before we
          // had a transport to tell about it.
          for (const [id, state] of lastStates) {
            if (state.screen_sharing && state.screen_transport === 'sfu') handler(id, state.sfu ?? null)
          }

          return () => { sfuAnnounceHandler = null }
        },
      })
      : createLiveKitTransport()

    try {
      await sfu.connect({
        ...transportContext(),
        // Never proximity-gated, even in a Side Space. Distance decides who you can *hear*;
        // a screen on the stage is for the room. Leaving this on would mean subscribing to
        // nobody, since the walking logic only ever drives the mesh.
        proximity: false,
      }, {
        // Not our business: the mesh already knows the roster, and it is authoritative.
        peerJoined: () => {},
        peerLeft: () => {},
        // A voice would never arrive here — we publish only the screen — but routing one into
        // the call by accident would double somebody up, so only the screen is let through.
        trackReceived: (peerId, kind, track) => {
          if (kind === 'screen' || kind === 'screenAudio') receiveTrack(peerId, kind, track)
        },
        trackEnded: () => {},
        // The mesh is the authority on whether you can reach someone; it is carrying their
        // voice, which is the part you would actually notice losing.
        peerStateChanged: () => {},
        controlReceived: () => {},
        failed: () => { void fallBackToMesh() },
      })
    } catch {
      // Couldn't get in. The share simply goes out over the mesh, as it always did.
      return null
    }

    sfuTransport = sfu

    // Everybody already in the call. The SFU is opened partway through — on the first share
    // that wants it — so unlike the mesh it never saw anyone arrive, and would otherwise sit
    // there subscribed to nobody.
    for (const id of handles.keys()) sfu.setSubscribed(id, true)

    return sfu
  }

  /**
   * Let go of the SFU once nothing needs it.
   *
   * A room connection is billed by the minute whether or not anything is flowing through it,
   * and a call that shared a screen once would otherwise hold one open until everybody hung
   * up. Kept while *anyone* is still sharing through it — including us.
   */
  async function maybeCloseScreenSfu() {
    if (!sfuTransport) return
    if (screenTrack && screenTransport.value === 'sfu') return

    for (const state of lastStates.values()) {
      if (state.screen_sharing && state.screen_transport === 'sfu') return
    }

    const going = sfuTransport
    sfuTransport = null
    await going.close().catch(() => {})
  }

  /**
   * Want, or stop wanting, everything one person is sending — on every transport carrying any
   * of it. See the note in createPeer for why this isn't just the mesh.
   */
  function subscribeEverywhere(id: number, subscribed: boolean) {
    activeTransport?.setSubscribed(id, subscribed)
    sfuTransport?.setSubscribed(id, subscribed)
  }

  /** Whichever transport the screen should be travelling on right now. */
  function screenCarrier(): VoiceTransport | null {
    return screenTransport.value === 'sfu' ? sfuTransport : activeTransport
  }

  /**
   * Move a screen share between direct and server-relayed, live.
   *
   * The capture is never touched, which is the whole point: the picture the sharer is looking
   * at doesn't flicker, no permission is re-asked, and what changes is only the route. The old
   * route is retracted *after* the new one is publishing, so there is no moment where the
   * screen is going nowhere.
   */
  async function setScreenTransport(kind: TransportKind) {
    if (kind === screenTransport.value) return

    screenTransport.value = kind

    if (!screenTrack) return // nothing live to move; the choice applies to the next share

    const previous = kind === 'sfu' ? activeTransport : sfuTransport

    if (kind === 'sfu' && !await openScreenSfu()) {
      // No SFU to move to — say so rather than silently leaving it where it was.
      screenTransport.value = 'mesh'
      notice.value = 'Couldn\u2019t reach the media server, so the share stayed direct.'
      return
    }

    const carrier = screenCarrier()
    if (!carrier) return

    /*
     * Video overlaps; audio does not. The two want opposite things from this moment.
     *
     * A picture that briefly arrives twice is invisible — the viewer renders one of them, and
     * the overlap is what stops the screen going black mid-switch. The *same sound* arriving
     * twice is not invisible at all: it phases against itself and reads as an echo, or as the
     * audio playing two or three times over. And the gap between the two routes here is a
     * round-trip to a media server plus a renegotiation, which is far longer than the few
     * milliseconds of overlap the video benefits from.
     *
     * So the old route's audio is dropped *before* the new one picks it up, and the video the
     * other way round.
     */
    await previous?.publish('screenAudio', null)

    await carrier.publish('screen', screenTrack)
    if (screenAudioTrack) await carrier.publish('screenAudio', screenAudioTrack)

    await previous?.publish('screen', null)

    // The encoder settings are per-transport, so the new carrier has to be told what the
    // sampler last decided — otherwise a film moved onto the SFU goes back to slideshow rates.
    const { degradation, maxFramerate } = screenModeSettings(resolvedScreenMode)
    await carrier.setScreenEncoding({ degradationPreference: degradation, maxFramerate })

    await publishState()
  }

  /**
   * Hand the transports everything we're already sending.
   *
   * Voice and camera always go to the mesh. The screen goes wherever it's currently routed —
   * which on a fresh join is nowhere yet, because nothing is being shared.
   */
  async function republish() {
    const mic = localStream?.getAudioTracks()[0] ?? null
    if (mic) await activeTransport?.publish('mic', mic)
    if (cameraTrack) await activeTransport?.publish('camera', cameraTrack)

    if (screenTrack) {
      const carrier = screenTransport.value === 'sfu' ? await openScreenSfu() : activeTransport
      await carrier?.publish('screen', screenTrack)
      if (screenAudioTrack) await carrier?.publish('screenAudio', screenAudioTrack)
    }
  }

  /**
   * Give up on the SFU and put the screen back on the mesh.
   *
   * Only the screen was ever on it, so this is a much smaller event than it used to be: the
   * voices never moved, nobody is re-dialled, and the call itself doesn't notice.
   */
  async function fallBackToMesh() {
    const failed = sfuTransport
    sfuTransport = null

    await failed?.close().catch(() => {})

    if (screenTransport.value !== 'sfu') return

    screenTransport.value = 'mesh'

    if (screenTrack) {
      notice.value = 'The media server dropped out — your share is going direct instead.'
      await activeTransport?.publish('screen', screenTrack)
      if (screenAudioTrack) await activeTransport?.publish('screenAudio', screenAudioTrack)
    }
  }

  /**
   * Put a track into a stream, displacing whatever was in that slot.
   *
   * A slot holds one track, and a second arrival means the first is finished — a share moved
   * between transports is the case that made this necessary, since the mesh copy ends and the
   * SFU copy begins and for a moment the stream would hold both. Left to accumulate, the
   * element goes on rendering the dead one and the switch looks like a freeze.
   *
   * An <audio> element is re-pointed at the stream afterwards, because mutating a stream a
   * media element is already sinking doesn't reliably make it pick the new track up.
   */
  function replaceTrackIn(stream: MediaStream, track: MediaStreamTrack, element?: HTMLMediaElement) {
    for (const existing of stream.getTracks()) {
      if (existing !== track) stream.removeTrack(existing)
    }

    stream.addTrack(track)

    if (element) element.srcObject = stream
  }

  /** The transport's inboxes, wired once per call. */
  function transportEvents(): TransportEvents {
    return {
      // Fired for somebody who dialled *us* — the Side Space case, where being offered to is
      // itself evidence we're in range. Everyone we dialled already has a handle by now.
      peerJoined: (id) => {
        if (handles.has(id)) return

        const member = roomMembers.get(id) ?? knownMembers().find(m => m.id === id)
        if (member) createPeer(member.id, member.name, member.avatar)
      },
      peerLeft: id => destroyPeer(id),
      trackReceived: receiveTrack,
      trackEnded: () => {
        // Nothing to undo: the stream keeps the ended track, and the element goes quiet on its
        // own. What the UI reacts to is the peer's whispered state, not the track's lifetime.
      },
      // The UI knows three states, not four: a transport that is reconnecting is, as far as
      // anyone looking at the tile is concerned, connecting.
      peerStateChanged: (id, state) => patchPeer(id, {
        connection: state === 'reconnecting' ? 'connecting' : state,
      }),
      controlReceived: (id, payload) => controlInputHandler?.(id, payload as ControlInput),
      failed: () => {
        // The mesh has no single thing to lose — a peer failing is that peer's problem, and
        // there is nowhere to fall back *to* from the transport that is itself the fallback.
      },
    }
  }

  // --- speaking detection ---

  function listenForSpeech(handle: PeerHandle) {
    if (handle.analyser || !audioCtx || !handle.audioStream.getAudioTracks().length) return

    const source = audioCtx.createMediaStreamSource(handle.audioStream)
    handle.analyser = audioCtx.createAnalyser()
    handle.analyser.fftSize = 512
    source.connect(handle.analyser)
  }

  /**
   * Point the "am I talking" meter at your own audio, building the analyser if this is the
   * first time. Deliberately fed from the *end* of the processing chain rather than the
   * capture: on the aggressive level the gate decides what leaves this machine, so a ring lit
   * from the raw microphone would glow at room noise the far end never hears. What you see
   * should be what they get.
   */
  function hookLocalMeter() {
    if (!audioCtx) return

    localSource?.disconnect()
    localSource = null

    localAnalyser ??= audioCtx.createAnalyser()
    localAnalyser.fftSize = 512

    if (micChain?.output) {
      micChain.output.connect(localAnalyser)
      return
    }

    if (!localStream) return
    localSource = audioCtx.createMediaStreamSource(localStream)
    localSource.connect(localAnalyser)
  }

  /** Root-mean-square of the waveform: loudness, near enough, and cheap. */
  function loudness(analyser: AnalyserNode, buffer: Float32Array): number {
    analyser.getFloatTimeDomainData(buffer as Float32Array<ArrayBuffer>)

    let sum = 0
    for (const sample of buffer) sum += sample * sample

    return Math.sqrt(sum / buffer.length)
  }

  /**
   * One loop for the whole call, driving the "who is talking" rings.
   *
   * Held for a moment after someone drops below the threshold, because speech is full of
   * gaps at this timescale and a ring that strobes on every consonant is worse than none.
   */
  function watchSpeaking() {
    const buffer = new Float32Array(256)

    const tick = () => {
      const now = Date.now()

      if (localAnalyser) {
        const talking = micOpen.value && loudness(localAnalyser, buffer) > SPEAKING_THRESHOLD
        if (talking) selfSpeaking.value = true
        else if (selfSpeaking.value) selfSpeaking.value = false
      }

      for (const [id, handle] of handles) {
        if (!handle.analyser) continue

        if (loudness(handle.analyser, buffer) > SPEAKING_THRESHOLD) {
          handle.speakingUntil = now + SPEAKING_HOLD_MS
        }

        const speaking = now < handle.speakingUntil
        const peer = peers.value.find(p => p.id === id)
        // A muted peer can't be "speaking", however loud the last packet was.
        if (peer && peer.speaking !== (speaking && !peer.muted)) {
          patchPeer(id, { speaking: speaking && !peer.muted })
        }
      }

      speakingFrame = requestAnimationFrame(tick)
    }

    speakingFrame = requestAnimationFrame(tick)
  }

  // --- joining and leaving ---

  async function connect(id: number) {
    if (inCall.value) await disconnect()

    status.value = 'connecting'
    error.value = null
    channelId.value = id

    try {
      // Ask for the microphone *first*: no point taking a seat in the room, telling
      // everybody, and then discovering the browser won't give us a microphone. getMicStream
      // honours the remembered device exactly (so a reloaded call comes up on the mic you
      // chose, not the system default) and falls back on its own if that device has gone.
      rawStream = await getMicStream(micId.value, noiseSuppression.value)

      // The processing chain has to exist before anything is sent, because what it produces
      // *is* what gets sent — building it after the peers were wired would mean handing
      // everybody the raw capture and swapping it out under them a moment later.
      resetMicProcessing() // the worklet module belongs to the context, and this one is new
      audioCtx = createCallContext()
      await audioCtx.resume()
      centreListener(audioCtx)
      // The context has its own output device, and a spatialised call plays out of it rather
      // than out of the <audio> elements — so the remembered speaker has to be set here too.
      if (speakerId.value) void applyContextSink(speakerId.value)
      micChain = await buildMicChain(
        audioCtx,
        rawStream,
        noiseSuppression.value,
        normalizeVolume.value,
        suppressionStrength.value,
      )
      localStream = micChain.stream
    } catch {
      // Anything half-built (a capture we got before the context failed, say) goes back —
      // a failed join must not leave the microphone open.
      teardownMedia()
      status.value = 'error'
      error.value = 'We couldn\'t reach your microphone. Check the site\'s permissions and try again.'
      channelId.value = null
      return null
    }

    // A fresh capture arrives live. On push-to-talk it must not: joining a call is not the
    // same as asking to be heard in it.
    applyMic()

    let joined: JoinResponse
    try {
      joined = await api<JoinResponse>(`/api/channels/${id}/voice/join`, { method: 'POST' })
    } catch (err: any) {
      teardownMedia()
      status.value = 'error'
      error.value = err?.data?.errors?.channel?.[0] ?? 'Couldn\'t join this voice channel.'
      channelId.value = null
      return null
    }

    iceServers = joined.ice_servers

    // What the server decided (see the backend's VoiceTransportResolver), and what it handed
    // over to act on it. A proposal, not an instruction: if the SFU won't come up we carry the
    // call on the mesh instead, which is why ice_servers arrives whichever it named.
    sfuCredentials = joined.sfu ?? null
    await openTransport(joined.transport ?? 'mesh')
    voiceEffects.value = {
      default: joined.effects?.default ?? { join: null, leave: null },
      people: joined.effects?.people ?? [],
    }

    hookLocalMeter()

    // Now that the site holds mic permission, the device labels are readable — populate the
    // picker, and keep it current as headsets are plugged and pulled for the call's lifetime.
    void refreshDevices()
    deviceChangeHandler = () => { void refreshDevices() }
    navigator.mediaDevices?.addEventListener?.('devicechange', deviceChangeHandler)

    // Everyone already in the room, and what they're doing — so a tile can be drawn with
    // the right icons on it before a single packet of audio has arrived.
    const known = new Map(joined.data.map(p => [p.user.id, p]))

    presence = echo.join(`voice.${id}`)
      .here((members: { id: number, name: string, avatar: string | null }[]) => {
        for (const member of members) {
          if (member.id === user.value?.id) continue

          // Everyone in the room, dialled or not. In an ordinary call these are the same set;
          // in a Side Space this is the roster the stage draws and setPeerInRange dials from.
          roomMembers.set(member.id, member)

          // In a Side Space nobody is connected on arrival — you dial the people you walk up
          // to. Which is the entire reason a fifty-person room is affordable on a mesh: the
          // one you're in is the size of your neighbourhood, not of the building.
          if (!proximityMode) createPeer(member.id, member.name, member.avatar)

          const state = known.get(member.id)
          if (state && !proximityMode) {
            patchPeer(member.id, {
              muted: state.muted,
              deafened: state.deafened,
              screenSharing: state.screen_sharing,
              cameraOn: state.camera_on,
              audioSharing: state.audio_sharing,
            })
          }
        }
        syncMemberIds()
        status.value = 'connected'

        // Your own arrival, for you. `here` is the one place we know we've actually landed —
        // and deliberately *only* your own: everyone in this list arrived before you did, and
        // firing an effect for each of them would greet you with six fireworks at once.
        if (user.value) fireEffect('join', user.value.id, 'You')
      })
      .joining((member: { id: number, name: string, avatar: string | null }) => {
        roomMembers.set(member.id, member)
        syncMemberIds()

        if (!proximityMode) createPeer(member.id, member.name, member.avatar)
        // They joined after our last state change, so their roster snapshot may predate
        // it. Say where we stand; it's one whisper.
        whisperState()

        // In a room, an arrival on the far side of the map is not an event you should be shown
        // — a busy Side Space would otherwise greet you with a firework per stranger. Effects
        // there are fired by the stage instead, when somebody comes into earshot.
        if (!proximityMode) fireEffect('join', member.id, nameOf(member.id, member.name))
      })
      .leaving((member: { id: number, name: string }) => {
        // Read before the teardown: destroyPeer drops them from `peers`, and the effect
        // wants to say whose exit it is.
        const name = peers.value.find(p => p.id === member.id)?.name ?? member.name

        roomMembers.delete(member.id)
        syncMemberIds()
        proximityGains.delete(member.id)
        const pending = dropTimers.get(member.id)
        if (pending) {
          clearTimeout(pending)
          dropTimers.delete(member.id)
        }

        destroyPeer(member.id)
        if (!proximityMode) fireEffect('leave', member.id, nameOf(member.id, name))
      })
      .listenForWhisper('signal', (payload: SignalPayload) => signalHandler?.(payload))
      .listenForWhisper('state', (state: StatePayload) => {
        // Remembered whether or not we have a peer to apply it to. In a Side Space we often
        // don't yet: both ends whisper the moment they come into range, and whichever whisper
        // wins the race would otherwise land before its peer existed and be dropped — leaving
        // somebody's mic shown as open when it isn't. createPeer replays this.
        lastStates.set(state.id, state)

        patchPeer(state.id, {
          muted: state.muted,
          deafened: state.deafened,
          screenSharing: state.screen_sharing,
          cameraOn: state.camera_on,
          audioSharing: state.audio_sharing,
        })

        // Somebody is sharing through the media server, so we have to be in the room to see
        // it. This is the whole receiving half of the feature: a viewer has no other reason
        // to open an SFU connection, and without it a share that moved there simply
        // disappears from the call.
        if (state.screen_sharing && state.screen_transport === 'sfu') {
          void openScreenSfu().then(() => {
            // Cloudflare needs to be told where their tracks are; LiveKit works it out itself,
            // and its adapter never registers a handler for this.
            sfuAnnounceHandler?.(state.id, state.sfu ?? null)
          })
        } else {
          sfuAnnounceHandler?.(state.id, null)
          void maybeCloseScreenSfu()
        }
      })
      // The remote-control handshake. Whispered rather than sent down the data channel because
      // it has to work *before* control exists, and because a request that quietly failed to
      // arrive would look identical to one that was ignored. See ControlSignal.
      .listenForWhisper('control', (signal: ControlSignal) => {
        if (!user.value || signal.to !== user.value.id) return
        controlSignalHandler?.(signal)
      })

    watchSpeaking()
    heartbeatTimer = setInterval(() => {
      api(`/api/channels/${id}/voice/heartbeat`, { method: 'POST' }).catch(() => {})
    }, HEARTBEAT_MS)

    /**
     * A closed tab never gets to run an await. `fetch(keepalive)` is the one request the
     * browser promises to finish anyway — and unlike `sendBeacon`, which is the usual
     * answer here, it can carry the bearer token. Without it the seat lingers until the
     * backend's staleness sweep notices, and the sidebar shows a ghost in the meantime.
     */
    leaveOnUnload = () => {
      fetch(`${config.public.apiBase}/api/channels/${id}/voice/leave`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token.value}`, Accept: 'application/json' },
        keepalive: true,
      }).catch(() => {})
    }
    window.addEventListener('pagehide', leaveOnUnload)

    // On Android the call is cut the moment the app leaves the screen unless a foreground
    // service is holding the microphone; this asks the shell for one, and is a no-op in every
    // other shell. Not awaited — a call must not wait on a notification permission dialog.
    backgroundVoice.start({
      text: 'Tap to return to your call.',
      // The notification's own Leave button. Hanging up here goes through the ordinary path,
      // so the seat is released and the room hears you go, exactly as if you'd used the app.
      onLeaveRequested: () => { void disconnect() },
    })

    // Handed back so a caller that needs the roster can have it without a second request. A
    // Side Space does: it wants everybody's last standing position to draw the room with
    // before a single whisper has arrived. Null on any of the bail-outs above.
    return joined
  }

  function teardownMedia() {
    stopScreenSampler()

    if (deviceChangeHandler) {
      navigator.mediaDevices?.removeEventListener?.('devicechange', deviceChangeHandler)
      deviceChangeHandler = undefined
    }

    for (const id of [...handles.keys()]) destroyPeer(id)

    // Closes the connections, the congestion poll and the signalling subscription in one go.
    // Left running, a mesh's stats timer would outlive the call it was measuring.
    void activeTransport?.close()
    void sfuTransport?.close()
    activeTransport = null
    sfuTransport = null
    sfuCredentials = null

    micChain?.destroy()
    micChain = null
    resetMicProcessing()

    localStream?.getTracks().forEach(track => track.stop())
    localStream = null
    // The capture is the thing actually holding the microphone — stopping the chain's output
    // track releases nothing on its own, and a mic left open after a call is over is exactly
    // the bug people are right never to forgive.
    rawStream?.getTracks().forEach(track => track.stop())
    rawStream = null

    // Every capture is stopped, not merely un-sent. This is what turns the camera light
    // and the "sharing your screen" bar off — and leaving either running after a call has
    // ended is the one bug in here that people would be right never to forgive.
    screenTrack?.stop()
    screenTrack = null
    screenAudioTrack?.stop()
    screenAudioTrack = null
    releaseShareAudio()
    screenStream.value = null
    audioShareStream.value = null

    cameraTrack?.stop()
    cameraTrack = null
    cameraStream.value = null

    localSource?.disconnect()
    localSource = null
    localAnalyser?.disconnect()
    localAnalyser = null
    void audioCtx?.close()
    audioCtx = null
  }

  async function disconnect() {
    const id = channelId.value

    if (speakingFrame) cancelAnimationFrame(speakingFrame)
    clearInterval(heartbeatTimer)
    if (leaveOnUnload) window.removeEventListener('pagehide', leaveOnUnload)
    speakingFrame = undefined
    heartbeatTimer = undefined
    leaveOnUnload = undefined

    teardownMedia()
    void backgroundVoice.stop()

    if (presence) {
      echo.leave(`voice.${id}`)
      presence = null
    }

    status.value = 'idle'
    error.value = null
    channelId.value = null
    voiceEffects.value = { ...NO_EFFECTS }
    // An effect must not outlive the room it belongs to: hanging up mid-firework should
    // take the firework with it.
    effects.clear()
    selfMuted.value = false
    selfDeafened.value = false
    selfSpeaking.value = false
    pttHeld.value = false // a key held as you hang up mustn't leave the next call open
    peers.value = []
    // Leaving a Side Space has to leave its rules behind with it, or the next call you join
    // starts with nobody dialled and everybody silent.
    setProximityMode(false)
    lastStates.clear()
    proximityPlacements.clear()
    proximityOffsets.clear()

    if (id) {
      try {
        await api(`/api/channels/${id}/voice/leave`, { method: 'POST' })
      } catch {
        // The staleness sweep will collect the seat if this didn't land.
      }
    }
  }

  // --- the controls ---

  /**
   * Open or close the capture to match {@link micOpen}. The single place a track's `enabled`
   * is decided, so muting, deafening and push-to-talk can't each hold a different opinion of
   * whether you're on air.
   */
  function applyMic() {
    applyMicTracks()
    // Coming back on air is the moment to check the capture is still alive: a track left
    // disabled for a long stretch can be ended out from under us (the OS or the browser
    // reclaims the device, a headset sleeps), and `enabled = true` on a dead track is silence
    // the UI can't see. Re-open it instead. See reviveMic.
    if (micOpen.value) void reviveMic()
  }

  /** The `enabled` half of {@link applyMic}, on its own so reviveMic can re-assert it. */
  function applyMicTracks() {
    const open = micOpen.value
    // Both ends of the chain, and the capture above all: disabling only what we send would
    // leave the microphone genuinely live — the browser's recording indicator lit, the OS
    // still hearing the room — while the UI says you're muted. Mute has to mean the mic.
    for (const stream of [rawStream, localStream]) {
      stream?.getAudioTracks().forEach(track => {
        track.enabled = open
      })
    }
    if (!open) selfSpeaking.value = false
  }

  /** Mute *your* microphone, for everyone. Stops sending audio, rather than sending silence. */
  function toggleMute() {
    selfMuted.value = !selfMuted.value
    applyMic()

    void publishState()
  }

  /** Silence everyone, for you. Muting your own mic too is the polite half of it. */
  function toggleDeafen() {
    selfDeafened.value = !selfDeafened.value

    if (selfDeafened.value && !selfMuted.value) {
      selfMuted.value = true
      applyMic()
    }

    for (const id of handles.keys()) applyAudio(id)

    void publishState()
  }

  /**
   * Turn push-to-talk on or off. Switching it on shuts the mic immediately — the point of the
   * mode is that nothing goes out unasked — and switching it off leaves you exactly as muted
   * or unmuted as the mic button says, rather than surprising the room with an open line.
   */
  function setPushToTalk(on: boolean) {
    if (pushToTalk.value === on) return
    pushToTalk.value = on
    pttHeld.value = false
    saveSettings()
    applyMic()
    void publishState()
  }

  /**
   * The talk key going down and coming back up. Cheap enough to call on every key event: both
   * return early unless push-to-talk is actually on and the state is really changing, so a
   * held key repeating doesn't republish anything.
   */
  function holdTalk() {
    if (!pushToTalk.value || pttHeld.value) return
    pttHeld.value = true
    applyMic()
    void publishState()
  }

  function releaseTalk() {
    if (!pttHeld.value) return
    pttHeld.value = false
    applyMic()
    void publishState()
  }

  /** Mute one person, for you alone. They are never told. */
  function togglePeerMute(id: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const localMuted = !peer.localMuted
    patchPeer(id, { localMuted })
    applyAudio(id)
    savePref(id, { muted: localMuted })
  }

  /**
   * Turn the whole call up or down, for you alone. `volume` is 0–1.
   *
   * Live: every peer is re-levelled as you drag, so it can be set by ear mid-conversation
   * rather than guessed at from a settings page. Remembered for next time, and applied to
   * people who join after it — see the push in `addPeer`, which reads the same expression.
   */
  function setOutputVolume(volume: number) {
    const clamped = Math.min(1, Math.max(0, volume))
    if (clamped === outputVolume.value) return

    outputVolume.value = clamped
    peers.value.forEach(peer => applyAudio(peer.id))
    saveSettings()
  }

  /** Turn one person up or down, for you alone. `volume` is 0–1. */
  function setPeerVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { volume: clamped })
    applyAudio(id)
    savePref(id, { volume: clamped })
  }

  // --- proximity: the Side Space's distance rules, driven from the stage ---

  /**
   * Turn distance-based audio on for this call.
   *
   * Set by the stage the moment it connects, and cleared on disconnect. While it's on, arriving
   * on the presence channel no longer means being dialled — {@link setPeerInRange} decides that
   * — and every peer's microphone is scaled by {@link setPeerProximity}.
   */
  function setProximityMode(on: boolean) {
    proximityMode = on
    // Mirrored into reactive state purely so the UI can tell the two spatial worlds apart:
    // in a Side Space you don't arrange the room, you walk around it, and offering a
    // drag-people-about panel there would be offering a control that does nothing.
    roomPlacesPeople.value = on

    if (!on) {
      roomMembers.clear()
      syncMemberIds()
      proximityGains.clear()
      proximityPlacements.clear()
      proximityOffsets.clear()
      for (const timer of dropTimers.values()) clearTimeout(timer)
      dropTimers.clear()
    }
  }

  /** Everyone on the presence channel, dialled or not — the stage draws all of them. */
  function knownMembers() {
    return [...roomMembers.values()]
  }

  /**
   * How loudly to hear one person, 0–1, and — when spatial audio is on — which direction from.
   *
   * Called for every occupant on every evaluation, so it does as little as it can get away
   * with: the gain is compared before anything reactive is written, because otherwise this
   * would rewrite state many times a second per person and re-render the room for no reason.
   *
   * `offset` is where they are relative to you in tiles (screen space, so +y is down the
   * map). It's optional so a caller that only cares about volume needn't supply it, and it's
   * *not* part of the early-return check: someone circling you at a constant distance has an
   * unchanged gain and a changing direction, which is precisely the case worth hearing.
   * Writing it costs nothing when spatial audio is off, so it isn't conditional on that
   * either — the placement is kept up to date so switching the setting on mid-walk is right
   * immediately rather than at their next step.
   */
  function setPeerProximity(id: number, gain: number, offset?: { x: number, y: number, facing?: Facing }) {
    const clamped = Math.min(1, Math.max(0, gain))

    if (offset) {
      proximityOffsets.set(id, { x: offset.x, y: offset.y })
      // Your own facing arrives with every occupant's offset (it's the same `self`), so the
      // last one in wins and they all agree. Cheap enough not to bother de-duplicating.
      if (offset.facing) myFacing = offset.facing
    }

    const placement = offset ? spacePlacementFor(id) : null
    const previous = proximityPlacements.get(id)
    // A hundredth of a radian is well under what anyone can localise, and this is the guard
    // that keeps a walking room from patching reactive state on every frame.
    const turned = placement !== null && (
      !previous
      || Math.abs(previous.angle - placement.angle) > 0.01
      || Math.abs(previous.distance - placement.distance) > 0.01
    )

    if (proximityGains.get(id) === clamped && !turned) return

    proximityGains.set(id, clamped)
    if (placement && turned) proximityPlacements.set(id, placement)

    // Held even with no peer to apply it to: they may be out of connect range right now, and
    // these are the values createPeer will start them at when they walk back.
    const peer = peers.value.find(p => p.id === id)
    if (peer) {
      patchPeer(id, {
        proximity: clamped,
        // Distance still applies to a pinned person — they can walk out of earshot like
        // anybody else. It's only the *direction* their voice comes from that you've fixed.
        ...(placement && turned && !peer.placed ? { placement } : {}),
      })
      applyAudio(id)
    }
  }

  /**
   * Open or close the connection to one person as they come and go.
   *
   * The asymmetry is deliberate. Coming into range dials immediately — a delay here is somebody
   * standing next to you saying something you never hear. Going out of range waits
   * {@link DROP_GRACE_MS}, because the connection is expensive to rebuild and the common case
   * for leaving range is pacing about near its edge, not actually walking away.
   *
   * Both ends run this off the same distance between the same two positions, so they cross the
   * threshold together and both start offering — a collision the existing perfect-negotiation
   * tie-break in {@link onSignal} already exists to untangle.
   */
  function setPeerInRange(id: number, inRange: boolean) {
    if (!proximityMode || id === user.value?.id) return

    const member = roomMembers.get(id)
    if (!member) return

    if (inRange) {
      const pending = dropTimers.get(id)
      if (pending) {
        clearTimeout(pending)
        dropTimers.delete(id)
      }

      if (!handles.has(id)) {
        createPeer(id, member.name, member.avatar)
        // They have no idea we've just arrived in earshot, and their last state whisper went
        // out before we had a connection to hear it on. Say where we stand.
        whisperState()
      }

      return
    }

    if (!handles.has(id) || dropTimers.has(id)) return

    dropTimers.set(id, setTimeout(() => {
      dropTimers.delete(id)
      destroyPeer(id)
    }, DROP_GRACE_MS))
  }

  /**
   * Stop (or start) hearing what one person is sharing, without touching their voice.
   *
   * The counterpart to togglePeerMute, and separate from it on purpose: someone playing music
   * over a conversation you still want to follow is exactly the case neither the per-person
   * mute nor "stop watching" covers. Yours alone — they are never told, and everyone else
   * still hears it.
   */
  function togglePeerScreenMute(id: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const screenMuted = !peer.screenMuted
    patchPeer(id, { screenMuted })
    applyAudio(id)
    savePref(id, { screenMuted })
  }

  /** Turn one person's *shared screen* up or down, for you alone. `volume` is 0–1. */
  function setPeerScreenVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { screenVolume: clamped })
    applyAudio(id)
    savePref(id, { screenVolume: clamped })
  }

  /**
   * Follow the stage: remember which screen you're watching so only its audio plays.
   *
   * The stage UI owns the choice of *which* screen is up; this is how that choice reaches the
   * audio, which lives one layer down per peer. Re-applying every peer settles both sides of
   * a switch in one pass — the screen you left goes quiet, the one you moved to speaks up.
   */
  function setWatchedScreens(keys: (number | 'self')[]) {
    watchedScreens.value = [...keys]
    for (const id of handles.keys()) applyAudio(id)
  }

  // --- devices: which microphone in, which speaker out ---

  /**
   * Ask the browser what audio devices exist and remember them for the picker.
   *
   * Labels ("Jabra Elite", "MacBook Pro Speakers") only come through once the site has been
   * granted mic access, which is why the list is worth refreshing right after connect and
   * again whenever a device is plugged or unplugged — before that they're blank strings the
   * UI has to paper over with a generic name.
   */
  async function refreshDevices() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) return
    try {
      const devices = await navigator.mediaDevices.enumerateDevices()
      inputDevices.value = devices.filter(d => d.kind === 'audioinput')
      outputDevices.value = devices.filter(d => d.kind === 'audiooutput')
    } catch {
      // A picker that can't populate just shows the current device; not worth surfacing.
    }
  }

  /** Point one audio element at a chosen speaker, tolerating browsers/ids that can't. */
  async function applySinkId(el: HTMLMediaElement, deviceId: string) {
    const sinkable = el as HTMLMediaElement & { setSinkId?: (id: string) => Promise<void> }
    if (!sinkable.setSinkId) return
    try {
      await sinkable.setSinkId(deviceId)
    } catch {
      // The device was unplugged, or the id is stale — the element keeps its old sink.
    }
  }

  /**
   * Re-open the microphone mid-call and put what comes out of it on the wire.
   *
   * Both things that can change the capture — picking a different device, and changing how
   * hard it's cleaned up — are this same operation, because both need a *new* getUserMedia:
   * echo cancellation and the rest are capture constraints, not something that can be turned
   * on afterwards. So it's written once.
   *
   * The swap itself is `replaceTrack` into every peer's mic sender — same-kind, so no
   * renegotiation and no gap the far end can hear. The care is all in what else pointed at
   * the old track: the processing chain is rebuilt on the new capture, the speaking meter is
   * re-hooked to it, and the new track inherits your current mute state so this can't quietly
   * un-mute you. The old capture is stopped last, once nothing depends on it.
   */
  async function swapMicCapture(deviceId: string | null, level: NoiseSuppression) {
    let capture: MediaStream
    try {
      capture = await getMicStream(deviceId, level)
    } catch {
      return // device vanished or was denied; the current mic keeps working
    }

    const chain = audioCtx
      ? await buildMicChain(audioCtx, capture, level, normalizeVolume.value, suppressionStrength.value)
      : null
    const stream = chain?.stream ?? capture
    const track = stream.getAudioTracks()[0]
    if (!track) {
      capture.getTracks().forEach(t => t.stop())
      chain?.destroy()
      return
    }

    // Carry mute across the swap — a fresh capture starts enabled, which would un-mute you
    // (and on push-to-talk, open the line without the key).
    for (const s of [capture, stream]) s.getAudioTracks().forEach(t => { t.enabled = micOpen.value })

    await activeTransport?.publish('mic', track)

    const oldCapture = rawStream
    const oldStream = localStream
    const oldChain = micChain

    rawStream = capture
    localStream = stream
    micChain = chain

    hookLocalMeter()

    oldChain?.destroy()
    oldStream?.getTracks().forEach(t => t.stop())
    oldCapture?.getTracks().forEach(t => t.stop())
  }

  /**
   * Bring the microphone back if it died while it was quiet.
   *
   * A muted or deafened call leaves the capture track disabled, sometimes for a long time, and
   * a disabled track is not a protected one: the browser can end it when the device is taken
   * elsewhere, the machine sleeps, or a bluetooth headset drops. Nothing about that is visible
   * from `enabled`, so un-muting would go through cleanly and send nothing but silence — the
   * "I unmuted and no-one can hear me" bug.
   *
   * So on the way back on air we check the capture rather than trust it, and a dead one is
   * re-opened through the same swap a device change uses. Also resumes the audio context,
   * which browsers suspend under the same long-idle conditions and which the mic chain (and
   * therefore everything we send) hangs off.
   */
  async function reviveMic() {
    if (!inCall.value || reviving) return

    if (audioCtx?.state === 'suspended') await audioCtx.resume().catch(() => {})

    const tracks = rawStream?.getAudioTracks() ?? []
    const dead = tracks.length === 0 || tracks.every(t => t.readyState === 'ended')
    if (!dead) return

    reviving = true
    try {
      await swapMicCapture(micId.value, noiseSuppression.value)
      if (!inCall.value) {
        // The call ended while getUserMedia was in flight — teardown has already run, so the
        // capture it just installed is ours to release. A mic left open after a call is over.
        micChain?.destroy()
        micChain = null
        localStream?.getTracks().forEach(t => t.stop())
        localStream = null
        rawStream?.getTracks().forEach(t => t.stop())
        rawStream = null
        return
      }
      // The swap carries the mute state across, so this only re-asserts it — but micOpen may
      // have changed again while getUserMedia was in flight.
      applyMicTracks()
    } finally {
      reviving = false
    }
  }

  /** Switch your microphone. Remembered either way; applied on the spot if a call is live. */
  async function setMicDevice(deviceId: string) {
    micId.value = deviceId
    saveSettings()
    if (!inCall.value) return

    await swapMicCapture(deviceId, noiseSuppression.value)
    void refreshDevices() // labels firm up once a device is actually in use
  }

  /**
   * Choose how hard your microphone is cleaned up — see ~/lib/micProcessing for what each
   * level means. Mid-call this re-opens the capture, because the browser's own processing is
   * decided at capture time and nothing downstream can add or remove it after the fact.
   */
  async function setNoiseSuppression(level: NoiseSuppression) {
    if (level === noiseSuppression.value) return

    noiseSuppression.value = level
    saveSettings()
    if (!inCall.value) return

    await swapMicCapture(micId.value, level)
  }

  /**
   * Choose how hard the "Aggressive" chain works.
   *
   * The one audio setting that needs neither a new capture nor a rebuilt graph: it's a value on
   * a live AudioParam, so a call in progress hears the change as it's dragged. That's the point
   * — this is a setting nobody can pick correctly in the abstract, and the only way to find
   * your number is to talk while you move it.
   */
  function setSuppressionStrength(value: number) {
    const strength = clampStrength(value)
    if (strength === suppressionStrength.value) return

    suppressionStrength.value = strength
    saveSettings()
    micChain?.setStrength(strength)
  }

  /**
   * Turn automatic levelling on or off. Unlike the suppression level this needs no new capture
   * — it's a node in our own chain — but the chain is built once per capture, so mid-call it
   * rides the same swap rather than growing a second way to rebuild itself.
   */
  async function setNormalizeVolume(enabled: boolean) {
    if (enabled === normalizeVolume.value) return

    normalizeVolume.value = enabled
    saveSettings()
    if (!inCall.value) return

    await swapMicCapture(micId.value, noiseSuppression.value)
  }

  /**
   * Point the *WebAudio* output at a chosen speaker.
   *
   * Needed because a positioned voice doesn't come out of its <audio> element any more — it
   * comes out of the AudioContext, which has its own sink and ignores every setSinkId we do
   * below. Chromium has `AudioContext.setSinkId`; where it doesn't exist, spatial audio plays
   * out of the system default however the picker is set, which is worth knowing and not worth
   * refusing to spatialise over.
   */
  async function applyContextSink(deviceId: string) {
    const ctx = audioCtx as (AudioContext & { setSinkId?: (id: string) => Promise<void> }) | null
    if (!ctx?.setSinkId) return
    try {
      await ctx.setSinkId(deviceId)
    } catch {
      // Stale or unplugged id — the context keeps the sink it has.
    }
  }

  /** Switch which speaker the call plays out of — every peer's voice and every shared screen. */
  async function setSpeaker(deviceId: string) {
    speakerId.value = deviceId
    saveSettings()
    await applyContextSink(deviceId)
    await Promise.all([...handles.values()].flatMap(h => [
      applySinkId(h.audio, deviceId),
      applySinkId(h.screenAudio, deviceId),
    ]))
  }

  // --- screen sharing ---

  /**
   * Hand back a version of a share's audio with the call taken out of it — or the same track
   * again, if that can't be done here.
   *
   * Only on the desktop app, and only in a call, because that is exactly the situation the echo
   * comes from: Electron can only capture the machine's *whole* output, so a screen share's
   * sound necessarily contains the other people in the call, played back to them a moment late.
   * A browser tab share doesn't have this problem — the capture is scoped to the tab — and a
   * share outside a call has nothing to cancel. See shareEcho.ts.
   */
  async function withoutCallEcho(track: MediaStreamTrack): Promise<MediaStreamTrack> {
    if (!audioCtx || !inCall.value) return track
    if (!(window as any).sideChatDesktop) return track

    const reference = openReferenceMix()
    if (!reference) return track

    shareCleanup = await cancelCallEcho(audioCtx, track, reference)
    if (!shareCleanup) {
      closeReferenceMix()
      return track
    }

    // The raw capture stays alive and feeding the graph; it's stopped alongside the cleaned one
    // when the share ends — see releaseShareAudio.
    rawShareAudioTrack = track

    return shareCleanup.track
  }

  /** Undo {@link withoutCallEcho}: the graph, the tap, and the capture that was feeding them. */
  function releaseShareAudio() {
    shareCleanup?.destroy()
    shareCleanup = null
    closeReferenceMix()
    rawShareAudioTrack?.stop()
    rawShareAudioTrack = null
  }

  async function startScreenShare() {
    if (!inCall.value || isSharing.value) return

    // Both kinds of share send their sound down the one screen-audio slot, so they take
    // turns. A screen share is the fuller thing — it brings its own audio — so starting one
    // supersedes an audio-only share rather than being refused because of it.
    if (isAudioSharing.value) await stopAudioShare()

    let display: MediaStream
    try {
      display = await captureDisplay({
        // Cap what we capture, not just what we send: a 1440p/4K desktop encoded once per
        // peer is what stutters a machine that's also gaming. The capture is downscaled to the
        // chosen height (default 720p), which is what actually cuts the encode load — see
        // setScreenResolution for changing it live.
        height: screenResolution.value,
        frameRate: SCREEN_MAX_FRAMERATE,
        // Ask for the tab/system audio too — sharing a video with no sound is half a share.
        // It's the picker's "Share tab audio" tick, so it's opt-in and often simply absent
        // (a whole-screen share on many platforms has none), which the code below tolerates.
        // No echo risk: this is the source's own audio, captured directly, never the mic.
        audio: true,
      })
    } catch {
      return // the user changed their mind at the picker; that isn't an error
    }

    screenTrack = display.getVideoTracks()[0] ?? null
    if (!screenTrack) return

    // The audio track only exists if the source had sound and the user ticked "share audio".
    screenAudioTrack = display.getAudioTracks()[0] ?? null

    // Resolve the content mode for the first frames; the sampler (started below) corrects an
    // 'auto' guess within a second or two once it can see what's on screen.
    //
    // 'auto' opens as *motion*, which is the cheaper mistake. Opening as detail meant every
    // share began at SCREEN_DETAIL_FRAMERATE — so a film or a game visibly juddered for its
    // first second or two and then snapped to full rate, which is exactly the moment someone
    // is watching to see whether the share works. The other way round costs a fraction of a
    // second of bitrate on a slide deck that nobody can perceive.
    const initialMode = screenMode.value === 'auto' ? 'motion' : screenMode.value
    resolvedScreenMode = initialMode
    screenTrack.contentHint = screenModeSettings(initialMode).hint

    // The browser's own "Stop sharing" bar bypasses our button entirely. Either track ending
    // (the user stops the video, or just the audio) tears the whole share down.
    screenTrack.onended = () => { void stopScreenShare() }
    if (screenAudioTrack) {
      // Music, not speech — see startAudioShare.
      screenAudioTrack.contentHint = 'music'
      screenAudioTrack.onended = () => { void stopScreenShare() }
      screenAudioTrack = await withoutCallEcho(screenAudioTrack)
    }

    // Hand both halves to the transport. What that costs — the per-peer bitrate arithmetic a
    // mesh needs, or the single simulcast publish an SFU wants — is its business now, and it
    // is the one part of screen sharing that genuinely differs between the two.
    // Where a share travels is the sharer's standing choice, so the SFU is dialled here — on
    // the share that wants it — rather than held open across calls that never share anything.
    const carrier = screenTransport.value === 'sfu' ? await openScreenSfu() : activeTransport

    if (!carrier) {
      // The server wouldn't have us. Direct is what screen sharing always was, so it is a
      // perfectly good answer rather than a failure worth stopping for.
      screenTransport.value = 'mesh'
    }

    await (carrier ?? activeTransport)?.publish('screen', screenTrack)
    if (screenAudioTrack) await (carrier ?? activeTransport)?.publish('screenAudio', screenAudioTrack)

    screenStream.value = markRaw(display)
    startScreenSampler() // a no-op unless the mode is 'auto'
    await publishState()
  }

  async function stopScreenShare() {
    if (!isSharing.value) return

    stopScreenSampler()

    await screenCarrier()?.publish('screen', null)
    await screenCarrier()?.publish('screenAudio', null)

    screenTrack?.stop()
    screenTrack = null
    screenAudioTrack?.stop()
    screenAudioTrack = null
    // And the echo canceller, if this share's sound was going through one.
    releaseShareAudio()
    screenStream.value = null
    // Stopping the tracks is enough for a browser capture; a native one is an OS-level
    // recording that outlives them, notification and all, until it's told to stop.
    await releaseDisplayCapture()

    // Tell the desktop shell the capture is over, so it forgets which display it was aiming
    // remote-control input at and lifts anything still held. No-op everywhere else.
    ;(window as any).sideChatDesktop?.screenShare?.stopped?.()

    // After the track is cleared, not before: this asks whether anything still needs the room,
    // and a screenTrack still sitting there would answer yes and keep it open for good.
    await maybeCloseScreenSfu()

    await publishState()
  }

  function toggleScreenShare() {
    return isSharing.value ? stopScreenShare() : startScreenShare()
  }

  // --- sharing sound, and nothing else ---

  /**
   * Play something to the room without showing it: a track, a video's soundtrack, whatever a
   * tab is making noise about.
   *
   * It reuses the screen-audio path wholesale — same transceiver, same <audio> element at the
   * far end, same per-peer volume — so from the network's point of view this is a screen share
   * with the expensive half left out. What it doesn't reuse is `screen_sharing`: telling peers
   * that would put a "watch my screen" tile in front of them for a picture that never arrives,
   * and would leave the sound gated behind a stage nobody can open (see applyAudio).
   *
   * The picture is not optional at the *capture* end, which is the wrinkle. No browser will
   * hand over tab or system audio on its own — getDisplayMedia only offers sound alongside a
   * video track — so we ask for both and stop the video the instant it arrives. It is never
   * encoded and never sent: the cost of an audio share is the audio.
   */
  async function startAudioShare() {
    if (!inCall.value || isAudioSharing.value) return
    if (isSharing.value) await stopScreenShare()

    let display: MediaStream
    try {
      display = await captureDisplay({
        // Asked for as cheaply as the capture will allow, since it's stopped a line later.
        frameRate: 1,
        height: 240,
        audio: true,
      })
    } catch {
      return // the picker was dismissed; that isn't an error
    }

    const track = display.getAudioTracks()[0] ?? null

    // Stopped immediately and unconditionally — including when there turned out to be no
    // audio, or the capture would quietly keep a tab marked as being shared.
    display.getVideoTracks().forEach(video => video.stop())

    if (!track) {
      // Overwhelmingly the common mistake, and worth naming precisely: the tick box is in
      // the browser's own picker, where we can neither set it nor see it.
      error.value = 'That source had no sound to share. Pick a tab or window and tick "Also share tab audio".'
      return
    }

    // Tells the encoder this is music, not speech: no DTX-style silence trimming, no
    // speech-shaped decisions about what's worth spending bits on.
    track.contentHint = 'music'
    // The browser's own "Stop sharing" bar never touches our button. Left on the *capture*
    // rather than what's sent, since the cleaned track below can't end on its own.
    track.onended = () => { void stopAudioShare() }

    // Sharing sound alone on the desktop is the same whole-machine capture a screen share
    // takes, so it carries the same echo and gets the same treatment.
    screenAudioTrack = await withoutCallEcho(track)

    await activeTransport?.publish('screenAudio', screenAudioTrack)

    // A stream of its own rather than the capture, which still holds the stopped video track.
    audioShareStream.value = markRaw(new MediaStream([screenAudioTrack]))
    await publishState()
  }

  async function stopAudioShare() {
    if (!isAudioSharing.value) return

    await activeTransport?.publish('screenAudio', null)

    // Stopped, not merely un-sent — this is what drops the "sharing" indicator.
    screenAudioTrack?.stop()
    screenAudioTrack = null
    releaseShareAudio()
    audioShareStream.value = null
    await releaseDisplayCapture()

    await publishState()
  }

  function toggleAudioShare() {
    return isAudioSharing.value ? stopAudioShare() : startAudioShare()
  }

  /**
   * Change the capture resolution — live if a share is already up, remembered either way.
   *
   * The browser re-scales the *same* capture to the new height, so there's no second picker
   * and no gap; the far end just sees the picture sharpen or soften. This is the real lever
   * on encode cost, so it's also the first thing to reach for when a share is stuttering.
   */
  async function setScreenResolution(resolution: ScreenResolution) {
    screenResolution.value = resolution
    saveSettings()
    if (!screenTrack) return
    await screenTrack.applyConstraints({
      height: { ideal: resolution, max: resolution },
      frameRate: { ideal: SCREEN_MAX_FRAMERATE, max: SCREEN_MAX_FRAMERATE },
    }).catch(() => {})
  }

  /**
   * Choose how a share is encoded — 'auto', 'detail', or 'motion'.
   *
   * A fixed choice stops the sampler and applies straight away. 'auto' (re)starts the sampler
   * and lets it decide; whatever's applied right now stays until the first sample lands, so
   * flipping to auto never blanks the picture.
   */
  function setScreenMode(mode: ScreenMode) {
    screenMode.value = mode
    saveSettings()
    if (mode === 'auto') {
      startScreenSampler()
    } else {
      stopScreenSampler()
      void applyScreenMode(mode)
    }
  }

  /** Push a resolved detail/motion decision onto the live share: contentHint + degradation. */
  async function applyScreenMode(resolved: 'detail' | 'motion') {
    resolvedScreenMode = resolved
    if (!screenTrack) return

    const { hint, degradation, maxFramerate } = screenModeSettings(resolved)
    screenTrack.contentHint = hint

    await screenCarrier()?.setScreenEncoding({ degradationPreference: degradation, maxFramerate })
  }

  /**
   * Start the adaptive sampler that guesses detail vs motion from the picture itself.
   *
   * Only meaningful while a screen is up and the mode is 'auto'. It draws the shared track
   * into a 32×32 canvas about once a second and measures how much the pixels moved: a lot
   * (a game, a video) tips it to 'motion', near-stillness (a code editor) back to 'detail'.
   * That's ~1000 pixels a second to read — nothing beside the encode it's tuning — and it
   * only touches the encoder when the guess actually flips.
   */
  function startScreenSampler() {
    stopScreenSampler()
    if (screenMode.value !== 'auto' || !screenStream.value || typeof document === 'undefined') return

    sampleVideo = document.createElement('video')
    sampleVideo.muted = true
    sampleVideo.srcObject = screenStream.value
    void sampleVideo.play().catch(() => {})

    sampleCanvas = document.createElement('canvas')
    sampleCanvas.width = 32
    sampleCanvas.height = 32
    samplePrev = null
    sampleDissent = 0

    sampleTimer = setInterval(sampleScreen, 1000)
  }

  function stopScreenSampler() {
    clearInterval(sampleTimer)
    sampleTimer = undefined
    if (sampleVideo) {
      sampleVideo.srcObject = null
      sampleVideo = null
    }
    sampleCanvas = null
    samplePrev = null
  }

  function sampleScreen() {
    if (!sampleVideo || !sampleCanvas || !sampleVideo.videoWidth) return
    const ctx = sampleCanvas.getContext('2d', { willReadFrequently: true })
    if (!ctx) return

    ctx.drawImage(sampleVideo, 0, 0, 32, 32)
    const { data } = ctx.getImageData(0, 0, 32, 32)

    if (samplePrev) {
      let diff = 0
      for (let i = 0; i < data.length; i += 4) {
        diff += Math.abs(data[i]! - samplePrev[i]!)
          + Math.abs(data[i + 1]! - samplePrev[i + 1]!)
          + Math.abs(data[i + 2]! - samplePrev[i + 2]!)
      }
      // Mean per-channel change across the frame, 0–255. Video and games sit well above the
      // threshold; a mostly static editor barely moves. Kept generous so a blinking cursor or
      // a line of scrolling text isn't mistaken for motion.
      const meanChange = diff / ((data.length / 4) * 3)
      const guess: 'detail' | 'motion' = meanChange > 8 ? 'motion' : 'detail'

      if (guess === resolvedScreenMode) {
        sampleDissent = 0
      } else {
        sampleDissent++
        // Asymmetric on purpose. Motion is believed immediately: a video that starts playing
        // should get the full framerate this second, not in three. Going *back* to detail
        // needs a run of quiet samples, because a film is full of them — a dialogue shot, a
        // slow pan, a fade — and dropping a movie to SCREEN_DETAIL_FRAMERATE every time the
        // camera stops moving is the flapping this counter exists to stop.
        if (guess === 'motion' || sampleDissent >= SCREEN_DETAIL_DWELL_SAMPLES) {
          sampleDissent = 0
          void applyScreenMode(guess)
        }
      }
    }

    samplePrev = data
  }

  // --- camera ---

  /**
   * Turn your camera on.
   *
   * Same trick as the screen, into the other slot: the transceiver was negotiated empty
   * when the peer connection was built, so this is a `replaceTrack` into a slot already
   * there — followed by one renegotiation so the far end knows the slot went live (without
   * it the picture never actually reaches them; see renegotiate).
   *
   * Capped well below what a webcam will happily hand you. This is a mesh: your camera
   * goes up your (thin, asymmetric) upload pipe once *per person in the call*, so a 720p
   * stream in a call of six is six times 720p leaving your laptop. `motion` is the honest
   * content hint for a face — unlike a screen full of text, we'd rather it stayed smooth
   * than stayed sharp.
   */
  /** What we ask a camera for. `ideal` throughout so a device that can't oblige still opens. */
  function cameraConstraints(facing: 'user' | 'environment'): MediaTrackConstraints {
    return {
      width: { ideal: 640 },
      height: { ideal: 360 },
      frameRate: { ideal: 24 },
      // Ideal, not exact: a laptop with one camera that calls itself neither would fail an
      // `exact` constraint outright, and a call is not worth losing over which way it faces.
      facingMode: { ideal: facing },
    }
  }

  /**
   * How many cameras this device has, for the flip button.
   *
   * Called when the camera goes on rather than at connect: before then we may have no
   * permission and no reason to ask, and after it the labels (and the count) are reliable.
   */
  async function countCameras() {
    try {
      const devices = await navigator.mediaDevices.enumerateDevices()
      cameraCount.value = devices.filter(d => d.kind === 'videoinput').length
    } catch {
      // Refused or unimplemented. Nothing to offer, which is the same as one camera.
      cameraCount.value = 0
    }
  }

  async function startCamera() {
    if (!inCall.value || isCameraOn.value) return

    let capture: MediaStream
    try {
      capture = await navigator.mediaDevices.getUserMedia({
        video: cameraConstraints(cameraFacing.value),
        // The microphone is already open and already being sent. Opening a second one here
        // is how you end up sending yourself twice, and hearing an echo.
        audio: false,
      })
    } catch {
      // Not fatal, and not worth tearing a call down over: a camera you can't reach still
      // leaves you perfectly audible. Unlike the microphone in connect(), which is the
      // whole point of being here.
      error.value = 'We couldn\'t reach your camera. Check the site\'s permissions.'
      return
    }

    cameraTrack = capture.getVideoTracks()[0] ?? null
    if (!cameraTrack) return

    cameraTrack.contentHint = 'motion'
    cameraTrack.onended = () => { void stopCamera() }

    await activeTransport?.publish('camera', cameraTrack)

    cameraStream.value = markRaw(capture)
    void countCameras()
    await publishState()
  }

  /**
   * Turn round: swap the front camera for the back one, or back again.
   *
   * A `replaceTrack` into the slot the old camera was already in, so there's no renegotiation
   * and nobody at the far end sees so much as a flicker — the picture simply changes. The old
   * track is stopped only *after* the new one is open, so a device that refuses the other
   * facing (or is busy) leaves you exactly as you were rather than with the camera off.
   */
  async function switchCamera() {
    if (!isCameraOn.value) return

    const next = cameraFacing.value === 'user' ? 'environment' : 'user'

    let capture: MediaStream
    try {
      capture = await navigator.mediaDevices.getUserMedia({ video: cameraConstraints(next), audio: false })
    } catch {
      error.value = 'We couldn\'t reach that camera.'
      return
    }

    const track = capture.getVideoTracks()[0] ?? null
    if (!track) return

    track.contentHint = 'motion'
    track.onended = () => { void stopCamera() }

    // Same slot, same encodings — only the source changes, so nobody sees a flicker.
    await activeTransport?.publish('camera', track)

    const previous = cameraTrack
    cameraTrack = track
    cameraFacing.value = next
    // Old capture down only now, and the whole stream: a MediaStream holding a stopped track
    // still renders its last frame in the self-view.
    previous?.stop()
    cameraStream.value?.getTracks().forEach(t => t.stop())
    cameraStream.value = markRaw(capture)
  }

  async function stopCamera() {
    if (!isCameraOn.value) return

    await Promise.all(
      [activeTransport?.publish('camera', null)],
    )

    // Stopping the track is what turns the little green light off. Leaving it running and
    // merely un-sent is the thing people (rightly) do not forgive.
    cameraTrack?.stop()
    cameraTrack = null
    cameraStream.value = null

    await publishState()
  }

  function toggleCamera() {
    return isCameraOn.value ? stopCamera() : startCamera()
  }

  // --- moderation ---
  //
  // Anyone in the call may do this — these just ask the server, which enforces channel
  // membership and nothing more. The person on the receiving end isn't torn down from here:
  // the server deletes their seat and tells their own browser to hang up (see useUserStream),
  // which drops them from the presence channel, which is how everyone else — us included —
  // sees them go.

  /** Disconnect one person from this call. */
  async function disconnectUser(userId: number) {
    if (!channelId.value) return
    try {
      await api(`/api/channels/${channelId.value}/voice/disconnect`, {
        method: 'POST',
        body: { user_id: userId },
      })
    } catch {
      error.value = 'Couldn\'t disconnect that person.'
    }
  }

  /** Clear the room: disconnect everyone except you. */
  async function disconnectAll() {
    if (!channelId.value) return
    try {
      await api(`/api/channels/${channelId.value}/voice/disconnect`, { method: 'POST' })
    } catch {
      error.value = 'Couldn\'t disconnect everyone.'
    }
  }

  /**
   * Move somebody else's microphone. Owner only — the server refuses anyone else, and the
   * button that calls this is only drawn for the owner in the first place.
   */
  async function muteUser(userId: number, muted: boolean) {
    if (!channelId.value) return
    try {
      await api(`/api/channels/${channelId.value}/voice/mute`, {
        method: 'POST',
        body: { user_id: userId, muted },
      })
    } catch {
      error.value = muted ? 'Couldn\'t mute that person.' : 'Couldn\'t unmute that person.'
    }
  }

  /**
   * The owner moved *your* microphone. Nothing on the server can reach a mic track, so this
   * is where it actually happens — the same three steps toggleMute takes, so the room finds
   * out through the ordinary channels and there's no second path for a mute to travel.
   *
   * Push-to-talk has to give way when the line is being opened for you: leaving it on would
   * make an "unmute" that unmuted nothing, since the mic would stay shut until you held the
   * key. Deafening is left alone — it's about your speakers, not your microphone, and being
   * unmuted while deafened is a perfectly coherent thing to be.
   */
  function mutedByModerator(muted: boolean) {
    // Unmuting has a second thing to undo (push-to-talk), so "already in that state" isn't
    // simply a matter of the mute flag — otherwise a re-send would leave the key mode on.
    const releasingPtt = !muted && pushToTalk.value
    if (muted === selfMuted.value && !releasingPtt) return

    selfMuted.value = muted

    // setPushToTalk does the applying and publishing itself, so don't do it twice.
    if (releasingPtt) setPushToTalk(false)
    else {
      applyMic()
      void publishState()
    }

    notice.value = muted
      ? 'You were muted by the owner.'
      : 'The owner turned your microphone on.'
    setTimeout(() => { notice.value = null }, 8000)
  }

  /**
   * Someone in the call turned you out of it. Tear the call down and leave a word behind —
   * otherwise the audio just stops and the tiles vanish with nothing said about why.
   */
  async function disconnectedByModerator() {
    await disconnect()
    notice.value = 'You were disconnected from the call.'
    setTimeout(() => { notice.value = null }, 8000)
  }

  return {
    channelId,
    status,
    error,
    notice,
    screenTransport,
    setScreenTransport,
    peers,
    selfMuted,
    selfDeafened,
    selfSpeaking,
    pushToTalk,
    pttHeld,
    micOpen,
    setPushToTalk,
    holdTalk,
    releaseTalk,
    screenStream,
    cameraStream,
    audioShareStream,
    inCall,
    isSharing,
    isCameraOn,
    cameraFacing,
    canSwitchCamera,
    isAudioSharing,
    sharingPeer,
    voiceEffects,
    effectFor,
    loadChannelEffects,
    setChannelEffects,
    applyChannelEffects,
    connect,
    disconnect,
    toggleMute,
    toggleDeafen,
    togglePeerMute,
    setPeerVolume,
    setPeerScreenVolume,
    togglePeerScreenMute,
    setWatchedScreens,
    // The mixed audio of the call, for a recording — see startRecordingMix.
    startRecordingMix,
    stopRecordingMix,
    // Proximity — a Side Space's distance rules, driven from the stage each frame.
    setProximityMode,
    setPeerProximity,
    setPeerInRange,
    outOfEarshot,
    knownMembers,
    /** Who is on the presence channel, as a ref — see {@link memberIds}. */
    memberIds,
    fireEffect,
    inputDevices,
    outputDevices,
    micId,
    speakerId,
    noiseSuppression,
    // Spatial audio: the setting, whether it's possible, and the room you arrange.
    spatialAudio,
    canSpatialise,
    roomPlacesPeople,
    spatialWidth,
    spatialTurnsWithYou,
    setSpatialAudio,
    setSpatialWidth,
    setSpatialTurnsWithYou,
    setPeerPlacement,
    unplacePeer,
    resetPlacements,
    noiseSuppressionOptions: NOISE_SUPPRESSION_OPTIONS,
    setNoiseSuppression,
    suppressionStrength,
    setSuppressionStrength,
    suppressionStrengthRange: SUPPRESSION_STRENGTH_RANGE,
    normalizeVolume,
    setNormalizeVolume,
    // The call's own fader, over everybody at once.
    outputVolume,
    setOutputVolume,
    screenResolution,
    screenMode,
    canPickSpeaker,
    screenResolutions: SCREEN_RESOLUTIONS,
    refreshDevices,
    setMicDevice,
    setSpeaker,
    setScreenResolution,
    setScreenMode,
    toggleScreenShare,
    toggleAudioShare,
    // Whether this device can share at all, and why not when it can't — so the call bar can
    // withhold the button (or explain it) instead of offering one that throws.
    canShareScreen,
    screenShareUnavailableReason,
    // Remote control's plumbing. The consent protocol on top of it is useRemoteControl's.
    sendControlInput,
    sendControlSignal,
    onControl,
    controlChannelReady,
    probeDisplayCapture,
    toggleCamera,
    switchCamera,
    disconnectUser,
    disconnectAll,
    disconnectedByModerator,
    muteUser,
    mutedByModerator,
  }
}
