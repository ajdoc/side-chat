import type { IceServer, Peer, PeerConnectionState, VoiceEffectPair, VoiceEffects, VoiceParticipant } from '~/types'

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

/**
 * Video is the genuinely expensive thing in a mesh, so both kinds stay leashed.
 *
 * Remember the shape of the cost: every stream you send goes up your upload pipe once per
 * *other person in the call*. A camera at 600kbps in a call of six is 3Mbps leaving your
 * laptop for the camera alone — which is why the camera is capped an order of magnitude
 * below the screen, and why a webcam is asked for at 360p rather than whatever it offers.
 */
const SCREEN_MAX_BITRATE = 2_500_000
const CAMERA_MAX_BITRATE = 600_000

/**
 * Voice is mono speech, and it goes up your upload pipe once per *other person in the call* —
 * so a few dozen kbps per peer is both plenty and the thing worth being stingy about. Capped on
 * the mic *sender's* encoding (applyMicParams) so it's the microphone that's held down and not
 * the shared-audio stream, and captured mono at source (channelCount: 1). DTX (see mungeOpus)
 * then makes the silences between words nearly free on top.
 */
const MIC_MAX_BITRATE = 32_000

/**
 * How the microphone is captured, in one place so a fresh join and a mid-call swap can't drift
 * apart. `channelCount: 1` is the mono capture the encoding budget is built around (see
 * MIC_MAX_BITRATE); the three processing flags are the browser's own echo/noise/gain cleanup.
 */
const MIC_AUDIO: MediaTrackConstraints = {
  echoCancellation: true,
  noiseSuppression: true,
  autoGainControl: true,
  channelCount: 1,
}

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

interface PeerHandle {
  pc: RTCPeerConnection
  /**
   * Their audio, playing outside Vue's control.
   *
   * Kept as a bare element appended to the document rather than something a component
   * renders, because a call outlives the page you started it on: you can wander off to a
   * text channel and keep talking, and nothing about that should interrupt the audio. The
   * element is also what per-peer volume and per-peer mute actually *are* — see setVolume.
   */
  audio: HTMLAudioElement
  /**
   * The sender carrying *your* microphone to this peer. Held onto so switching input devices
   * is a `replaceTrack` into a slot already there — no renegotiation, and it lets us tell the
   * mic sender apart from the screen-audio one, which is the other audio sender on the pc.
   */
  micSender: RTCRtpSender | null
  /** Their microphone. Kept alone, because it's the only thing the <audio> should sink. */
  audioStream: MediaStream
  /**
   * The audio *of* what they're sharing — a video playing in the tab, say — kept on its own
   * element and its own transceiver, deliberately apart from the microphone. Mixing the two
   * would let a screen mute silence a voice, run the shared audio through the mic's echo
   * cancellation, and set the speaking ring flickering to a YouTube clip. One <audio> each
   * keeps per-peer volume and mute honest for both.
   */
  screenAudio: HTMLAudioElement
  screenAudioStream: MediaStream
  screenAudioTransceiver: RTCRtpTransceiver | null
  /** Their face, and the thing they're presenting. Separate — see the two slots below. */
  cameraStream: MediaStream
  screenStream: MediaStream
  /**
   * The two pre-negotiated video slots: one for a camera, one for a screen.
   *
   * *Two*, not one, and that's the point. Someone on camera who starts presenting has to
   * appear in two places at once — their face on their tile, their screen on the stage —
   * and a single video slot forces a choice between them. Keeping the transceivers around
   * is also how `ontrack` knows which is which: a track carries no label saying "this is a
   * webcam", but it does arrive on the transceiver it was negotiated into.
   *
   * Null until known. The impolite peer creates both up front; the polite peer starts with
   * null and adopts them in ontrack as the impolite peer's m-lines arrive (camera first,
   * then screen). See createPeer for why only one side creates them.
   */
  cameraTransceiver: RTCRtpTransceiver | null
  screenTransceiver: RTCRtpTransceiver | null
  analyser: AnalyserNode | null
  speakingUntil: number
  // --- perfect negotiation bookkeeping (see negotiate/onSignal) ---
  polite: boolean
  makingOffer: boolean
  ignoreOffer: boolean
  settingRemoteAnswer: boolean
  /**
   * Has the first offer/answer for this pair completed yet?
   *
   * Used to break the *initial* glare: both ends create the same transceivers the instant
   * they see each other, so if both also fire the first offer they collide, and the polite
   * peer's rollback strands its video transceivers as sendonly (every remote video black).
   * So the polite peer holds its first offer and answers the impolite peer's instead; once
   * that's done either side may offer freely (a camera toggle, say).
   */
  negotiated: boolean
}

interface SignalPayload {
  to: number
  from: number
  description?: RTCSessionDescriptionInit
  candidate?: RTCIceCandidateInit
}

interface StatePayload {
  id: number
  muted: boolean
  deafened: boolean
  screen_sharing: boolean
  camera_on: boolean
  audio_sharing: boolean
}

interface JoinResponse {
  data: VoiceParticipant[]
  ice_servers: IceServer[]
  max_participants: number
  effects: VoiceEffects
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
}

// Module scope, not component scope: one call, however many components are looking at it.
// None of this belongs in reactive state — Vue would proxy the RTCPeerConnections and
// MediaStreams, and a proxied MediaStream is not a MediaStream as far as the DOM is
// concerned (assigning one to `srcObject` throws).
const handles = new Map<number, PeerHandle>()
let localStream: MediaStream | null = null
let screenTrack: MediaStreamTrack | null = null
let screenAudioTrack: MediaStreamTrack | null = null
let cameraTrack: MediaStreamTrack | null = null
let iceServers: IceServer[] = []
let presence: any = null
let audioCtx: AudioContext | null = null
let localAnalyser: AnalyserNode | null = null
// The WebAudio node feeding the speaking indicator from your mic. Kept in module scope so a
// mid-call microphone swap can unhook the old capture and hook the new one. See setMicDevice.
let localSource: MediaStreamAudioSourceNode | null = null
let heartbeatTimer: ReturnType<typeof setInterval> | undefined
let speakingFrame: number | undefined
let leaveOnUnload: (() => void) | undefined
// Kept so it can be removed on leave — an anonymous listener would leak one per call.
let deviceChangeHandler: (() => void) | undefined

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

/**
 * gzip a string to base64, and back.
 *
 * The SDP is the one signalling field big enough to matter: an offer can run to ~17KB
 * once every codec and — with TURN — every relay candidate is spelled out, and Reverb
 * closes the socket with a 1009 on any whisper past its message-size limit (which the
 * mesh experiences as peers that flap in and out or never connect). SDP is highly
 * repetitive text, so gzip takes that ~17KB down to a couple of KB, comfortably under
 * the cap and independent of how many codecs or candidates the browser decided to list.
 */
async function gzipToBase64(text: string): Promise<string> {
  const stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'))
  const bytes = new Uint8Array(await new Response(stream).arrayBuffer())

  // Chunked so a large SDP can't overflow the argument list of String.fromCharCode.
  let binary = ''
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  }
  return btoa(binary)
}

async function base64ToGunzip(b64: string): Promise<string> {
  const binary = atob(b64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

  const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))
  return new Response(stream).text()
}

/**
 * Turn Opus DTX on: while a line is silent the encoder sends only the occasional comfort-noise
 * update instead of a full stream, so quiet costs almost nothing — and in a call most mics are
 * quiet most of the time.
 *
 * Applied to every description we *send* (see signal), which is enough on its own: an Opus
 * encoder configures itself from the *remote* fmtp — its peer's declared receive preferences —
 * so both ends running this switch DTX on for both directions, without touching our own local
 * description or the perfect-negotiation state machine. A half-deployed pair simply gets the
 * saving in one direction rather than wedging.
 *
 * DTX *only*, deliberately. In BUNDLE both audio m-lines — your microphone and the shared
 * tab/system audio — share one Opus payload type and so one fmtp line; forcing mono or a low
 * bitrate here would also crush shared music. Those two belong to the mic alone, so they live
 * on the mic *sender* (applyMicParams caps its bitrate; channelCount: 1 captures it mono) and
 * leave the shared-audio stream at full quality. DTX is the one nudge that suits both: it does
 * nothing to continuous music (there's no silence to trim) and saves on everything else.
 */
function mungeOpus(sdp: string): string {
  const pt = sdp.match(/a=rtpmap:(\d+) opus\/48000/i)?.[1]
  if (!pt) return sdp

  const want = ['usedtx=1']
  const fmtp = new RegExp(`a=fmtp:${pt} ([^\\r\\n]*)`)

  if (fmtp.test(sdp)) {
    // Merge into the existing fmtp: overwrite any key we care about, keep the rest untouched.
    return sdp.replace(fmtp, (_line, existing: string) => {
      const parts = existing.split(';').map(s => s.trim()).filter(Boolean)
      for (const entry of want) {
        const key = entry.split('=')[0]!
        const at = parts.findIndex(p => p.startsWith(`${key}=`))
        if (at === -1) parts.push(entry)
        else parts[at] = entry
      }
      return `a=fmtp:${pt} ${parts.join(';')}`
    })
  }

  // No fmtp line yet — add one right after Opus's rtpmap.
  return sdp.replace(
    new RegExp(`(a=rtpmap:${pt} opus/48000[^\\r\\n]*\\r?\\n)`, 'i'),
    `$1a=fmtp:${pt} ${want.join(';')}\r\n`,
  )
}

/**
 * Prefer VP9, then VP8, for a video transceiver.
 *
 * This is a *reorder* of the full codec list, not the payload-type pinning that once broke
 * BUNDLE demux (see the note in createPeer) — every codec stays offered, so distinct payload
 * types and the fallback path are both intact. VP9 buys noticeably sharper screen text at the
 * same bitrate than VP8.
 *
 * AV1 is deliberately left where it is and never raised. In a mesh a share is encoded once per
 * peer on the sharer's machine, and realtime AV1 at these sizes is a CPU trap — the exact cost
 * this file is built to avoid. Best-effort: browsers without the capability API keep their
 * default order.
 */
function preferEfficientVideo(transceiver: RTCRtpTransceiver) {
  if (typeof RTCRtpReceiver === 'undefined' || !RTCRtpReceiver.getCapabilities) return
  if (!('setCodecPreferences' in transceiver)) return

  const caps = RTCRtpReceiver.getCapabilities('video')
  if (!caps) return

  const rank = (mimeType: string) => {
    switch (mimeType.toLowerCase()) {
      case 'video/vp9': return 0
      case 'video/vp8': return 1
      case 'video/av1': return 4 // available, but never preferred for a realtime mesh encode
      default: return 2 // H.264, and the rtx/red/ulpfec machinery that must stay present
    }
  }

  // Stable sort so codecs of equal rank keep the browser's own ordering (profiles, rtx pairs).
  const ordered = caps.codecs
    .map((codec, index) => ({ codec, index }))
    .sort((a, b) => rank(a.codec.mimeType) - rank(b.codec.mimeType) || a.index - b.index)
    .map(entry => entry.codec)

  try {
    transceiver.setCodecPreferences(ordered)
  } catch {
    // An engine that rejects the list keeps its default order rather than losing video.
  }
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
async function getMicStream(deviceId: string | null): Promise<MediaStream> {
  if (deviceId) {
    for (let attempt = 0; attempt < MIC_RETRY_ATTEMPTS; attempt++) {
      try {
        return await navigator.mediaDevices.getUserMedia({
          audio: { deviceId: { exact: deviceId }, ...MIC_AUDIO },
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

  return navigator.mediaDevices.getUserMedia({ audio: { ...MIC_AUDIO }, video: false })
}

/** Cap the mic sender's bitrate — speech is cheap and this upload is paid once per peer. */
async function applyMicParams(sender: RTCRtpSender) {
  const params = sender.getParameters()
  params.encodings = params.encodings?.length ? params.encodings : [{}]
  params.encodings[0]!.maxBitrate = MIC_MAX_BITRATE
  await sender.setParameters(params).catch(() => {})
}

export function useVoice() {
  const api = useApi()
  const config = useRuntimeConfig()
  const echo: any = useNuxtApp().$echo
  const { user } = useAuth()
  const token = useCookie<string | null>('auth_token')

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
  const screenStream = useState<MediaStream | null>('voice:screenStream', () => null)
  const cameraStream = useState<MediaStream | null>('voice:cameraStream', () => null)
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
   * Which shared screen is on the stage right now — a peer id, `'self'`, or null when you're
   * watching nobody. Only this screen's audio is allowed to play (see applyAudio); the stage
   * UI keeps it in step via setWatchedScreen.
   */
  const watchedScreen = useState<number | 'self' | null>('voice:watchedScreen', () => null)

  // --- device & quality settings (yours, remembered across calls) ---

  /** The audio devices the browser will show you — refreshed on demand and on hot-plug. */
  const inputDevices = useState<MediaDeviceInfo[]>('voice:inputDevices', () => [])
  const outputDevices = useState<MediaDeviceInfo[]>('voice:outputDevices', () => [])
  /** Chosen device ids — null means "let the browser pick its default". */
  const micId = useState<string | null>('voice:micId', () => loadSettings().micId)
  const speakerId = useState<string | null>('voice:speakerId', () => loadSettings().speakerId)
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
    resolution: ScreenResolution
    mode: ScreenMode
    pushToTalk: boolean
  } {
    const fallback = {
      micId: null,
      speakerId: null,
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
   * Apply a peer's audio settings to their audio element.
   *
   * This *is* per-peer mute and per-peer volume: one <audio> per person, so turning one
   * of them down is a property assignment and cannot possibly affect anybody else.
   * Deafening yourself is the same operation applied to all of them at once.
   */
  function applyAudio(id: number) {
    const handle = handles.get(id)
    const peer = peers.value.find(p => p.id === id)
    if (!handle || !peer) return

    const muted = peer.localMuted || selfDeafened.value
    handle.audio.volume = peer.volume
    handle.audio.muted = muted
    // The shared audio still answers to *mute* and *deafen* — silencing someone silences
    // their screen too — but rides its own volume so a loud shared clip can be turned down
    // without quietening the person talking over it. See setPeerScreenVolume.
    //
    // It also plays *only while you're watching that screen*: clicking "Stop watching" (or
    // switching the stage to someone else) hides the picture, and this is what stops the
    // sound coming with it — otherwise a screen you'd closed kept playing audio out of a
    // stream you couldn't see. See setWatchedScreen.
    //
    // An audio-only share is exempt, and has to be: there is no picture, so there is nothing
    // to be watching, and gating it on the stage would mean nobody ever heard it. Someone
    // sharing sound alone is heard the moment they start, like a person talking.
    //
    // `screenMuted` is the listener's own veto on top of all that — "keep talking, but I've
    // heard enough of your music". Yours alone, never sent, and remembered for next time.
    handle.screenAudio.volume = peer.screenVolume
    handle.screenAudio.muted = muted
      || peer.screenMuted
      || (peer.screenSharing && watchedScreen.value !== id)
  }

  // --- signalling ---

  async function signal(to: number, payload: Omit<SignalPayload, 'to' | 'from'>) {
    if (!presence || !user.value) return

    // The SDP rides compressed (see gzipToBase64) as `sdpz`; everything else — the tiny
    // ICE candidates and the routing ids — passes through untouched.
    const body: Record<string, unknown> = { to, from: user.value.id }
    if (payload.description) {
      body.description = {
        type: payload.description.type,
        sdpz: await gzipToBase64(mungeOpus(payload.description.sdp ?? '')),
      }
    }
    if (payload.candidate) body.candidate = payload.candidate

    presence.whisper('signal', body)
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

  // --- peer connections ---

  function createPeer(id: number, name: string, avatar: string | null) {
    if (handles.has(id) || !localStream || !user.value) return

    const pc = new RTCPeerConnection({ iceServers })
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

    /**
     * Perfect negotiation (the WebRTC spec's own pattern).
     *
     * Both ends of a new pair learn about each other at the same instant — I see you in
     * `here`, you see me in `joining` — so both will try to make the offer, and the
     * collision would leave the connection wedged. Rather than inventing a rule about who
     * gets to call whom (which then has to be re-derived every time a screen share forces
     * a renegotiation), one side is designated *polite*: on a collision it rolls back its
     * own offer and takes the other's. Comparing user ids is enough to agree on that
     * without exchanging a word, and it always yields opposite answers on the two sides.
     */
    const handle: PeerHandle = {
      pc,
      audio,
      micSender: null,
      audioStream,
      screenAudio,
      screenAudioStream,
      cameraStream,
      screenStream,
      polite: user.value.id < id,
      makingOffer: false,
      ignoreOffer: false,
      settingRemoteAnswer: false,
      negotiated: false,
      analyser: null,
      speakingUntil: 0,
      // The impolite peer fills these in just below; the polite peer adopts them in ontrack.
      screenAudioTransceiver: null,
      cameraTransceiver: null,
      screenTransceiver: null,
    }

    for (const track of localStream.getAudioTracks()) {
      handle.micSender = pc.addTrack(track, localStream)
      void applyMicParams(handle.micSender)
    }

    // If a speaker was chosen, route this peer's two audio elements to it as they're born —
    // otherwise a device picked before someone joined wouldn't reach the people who arrive
    // after. Best-effort: setSinkId is Chromium-only and rejects if the id has gone stale.
    if (speakerId.value) {
      void applySinkId(audio, speakerId.value)
      void applySinkId(screenAudio, speakerId.value)
    }

    /**
     * Two video slots, one for a camera and one for a screen — but created by *one* side
     * only, and this is the crux of the whole thing.
     *
     * The tidy-looking idea is for both ends to create the same two transceivers, so the
     * m-lines line up by construction. It does not survive contact with reality: both ends
     * see each other at the same instant, both fire an offer, and that collision (even with
     * perfect negotiation resolving it) leaves the rolled-back side's video transceivers
     * stranded — duplicated, stuck `sendonly`, never receiving. That was the bug behind
     * every black remote video: `#0 audio, #1 video sendonly, #2 video sendonly` (mine,
     * orphaned) plus `#3 video recvonly, #4 video recvonly` (adopted) — four video m-lines
     * where there should be two.
     *
     * So only the *impolite* peer lays out the video slots. The polite peer creates none and
     * adopts the impolite peer's when they arrive — see ontrack, which assigns
     * camera/screen in arrival order (the impolite peer creates them camera-then-screen, and
     * m-lines arrive in that order). One creator means there is nothing to duplicate, whatever
     * the timing. We keep the slots pre-negotiated rather than added on the click so that
     * turning a camera on is a cheap replaceTrack, not an offer storm across every peer.
     *
     * No codec pinning: forcing both video m-lines to one shared VP8 payload type made them
     * indistinguishable on the shared BUNDLE transport, so the receiver dropped every packet.
     * Letting Chrome number them gives distinct payload types; gzip (see signal) keeps the
     * larger SDP well under the wire limit.
     */
    if (!handle.polite) {
      // A third slot beside the two video ones, for the shared audio. Created first so the
      // m-lines are [mic, screen-audio, camera, screen] on both ends; the polite peer adopts
      // them in that order (see ontrack).
      handle.screenAudioTransceiver = pc.addTransceiver('audio', { direction: 'sendrecv' })
      handle.cameraTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })
      handle.screenTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })

      // Steer both video slots to VP9 before the first offer, so the whole session negotiates
      // the more efficient codec. Only the impolite peer creates the slots (see below), so
      // setting the preference here sets it for the pair.
      preferEfficientVideo(handle.cameraTransceiver)
      preferEfficientVideo(handle.screenTransceiver)

      if (screenAudioTrack) void handle.screenAudioTransceiver.sender.replaceTrack(screenAudioTrack)
      if (cameraTrack) void handle.cameraTransceiver.sender.replaceTrack(cameraTrack)
      if (screenTrack) void handle.screenTransceiver.sender.replaceTrack(screenTrack)
    }

    pc.onnegotiationneeded = async () => {
      // Break the initial glare: until the first offer/answer is done, only the impolite
      // peer offers. Both ends added the same transceivers a moment ago, so if both offered
      // now they'd collide and the polite peer's rollback would strand its video
      // transceivers as sendonly — the exact "remote video is black" bug. The polite peer
      // instead waits and answers; afterwards `negotiated` is set and either side may offer.
      if (handle.polite && !handle.negotiated) return

      try {
        handle.makingOffer = true
        await pc.setLocalDescription()
        await signal(id, { description: pc.localDescription!.toJSON() })
      } catch {
        // A failed offer isn't fatal: ICE restart below picks the connection back up.
      } finally {
        handle.makingOffer = false
      }
    }

    pc.onicecandidate = ({ candidate }) => {
      if (candidate) void signal(id, { candidate: candidate.toJSON() })
    }

    pc.onconnectionstatechange = () => {
      const state: PeerConnectionState =
        pc.connectionState === 'connected'
          ? 'connected'
          : pc.connectionState === 'failed'
            ? 'failed'
            : 'connecting'

      patchPeer(id, { connection: state })

      // A network that moved under us (wifi → cellular, VPN up) fails the connection
      // without either side going anywhere. Re-gathering candidates usually recovers it.
      if (pc.connectionState === 'failed') pc.restartIce()
    }

    /**
     * Sort each incoming track into the stream it belongs to, by the slot it arrived in.
     *
     * Three things are going on here.
     *
     * First: we assemble the streams by hand rather than trusting `event.streams[0]`. The
     * stream a track claims to belong to comes from an `msid` in the SDP, and the video
     * slots were negotiated *empty* — so there is no msid to speak of, and a track pushed
     * into one later can arrive orphaned. Adding tracks to streams we own sidesteps that.
     *
     * Second: a MediaStreamTrack carries nothing that says "webcam" rather than "screen".
     * What it does carry is the transceiver it came in on — so telling a face from a screen
     * is an identity comparison against the two slots, never a guess.
     *
     * Third, adoption: the polite peer created no video slots (see createPeer), so for it
     * the two are still null here. It learns them now, in arrival order — the impolite peer
     * creates them camera-then-screen and the m-lines arrive in that order, so the first
     * video track is the camera and the second is the screen. The impolite peer already has
     * both set, so this adoption is a no-op for it and the identity comparison stands.
     */
    pc.ontrack = ({ track, transceiver }) => {
      if (track.kind === 'audio') {
        // Mic, or the shared tab/system audio? The impolite peer created a dedicated slot
        // and knows it by identity. The polite peer adopts it: its own microphone slot has a
        // local send-track (the mic it added), whereas the screen-audio slot it merely
        // received into does not — so the empty one is the screen's.
        const isScreenAudio = handle.screenAudioTransceiver
          ? transceiver === handle.screenAudioTransceiver
          : !transceiver.sender.track

        if (isScreenAudio) {
          if (!handle.screenAudioTransceiver) {
            handle.screenAudioTransceiver = transceiver

            // We may already be sharing when this slot finally becomes known to us — the
            // polite peer only learns it here, and somebody who joins mid-share has to be
            // sent the sound too, not just be able to receive it. The renegotiation that
            // needs is raised by onnegotiationneeded, which is free to fire by now.
            if (screenAudioTrack) {
              if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'
              void transceiver.sender.replaceTrack(screenAudioTrack)
            }
          }

          handle.screenAudioStream.addTrack(track)
          handle.screenAudio.play().catch(() => {})
          applyAudio(id)
          return
        }

        audioStream.addTrack(track)

        audio.play().catch(() => {
          // Autoplay policy. Joining a call is a user gesture, so this shouldn't fire —
          // and if it somehow does, the next click anywhere in the page will unblock it.
        })
        listenForSpeech(handle)
        applyAudio(id)

        return
      }

      if (!handle.cameraTransceiver) {
        handle.cameraTransceiver = transceiver
      } else if (transceiver !== handle.cameraTransceiver && !handle.screenTransceiver) {
        handle.screenTransceiver = transceiver
      }

      const camera = transceiver === handle.cameraTransceiver
      const target = camera ? cameraStream : screenStream
      target.addTrack(track)

      // Vue must not proxy a MediaStream: the DOM rejects the proxy on `srcObject`.
      patchPeer(id, camera
        ? { camera: markRaw(target) }
        : { screen: markRaw(target) })
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
    }]
  }

  function destroyPeer(id: number) {
    const handle = handles.get(id)
    if (!handle) return

    handle.pc.onnegotiationneeded = null
    handle.pc.onicecandidate = null
    handle.pc.ontrack = null
    handle.pc.onconnectionstatechange = null
    handle.pc.close()

    handle.audio.srcObject = null
    handle.audio.remove()
    handle.screenAudio.srcObject = null
    handle.screenAudio.remove()
    handle.analyser?.disconnect()

    handles.delete(id)
    peers.value = peers.value.filter(p => p.id !== id)
  }

  /**
   * Force a fresh offer to one peer, after the tracks a sender carries have changed.
   *
   * The video slots are negotiated *empty* (see createPeer), and the promise on the two
   * screen/camera functions — "replaceTrack, no renegotiation" — turns out to be only half
   * true. Swapping one live track for another needs no renegotiation, yes. But going from
   * *no* track to a live one is different: the far end built its m-line expecting nothing,
   * so the SSRC and msid the new track carries have to be announced, or its depacketizer
   * quietly drops packets it was never told to decode. On screen that's a video tile that
   * stays black while the audio is perfectly fine — which is exactly the bug this fixes.
   *
   * Only from a stable state. An offer already in flight will carry the new track when it
   * lands, and a collision (both ends offering at once) is untangled by perfect negotiation
   * in onSignal — the same makingOffer bookkeeping onnegotiationneeded uses.
   */
  async function renegotiate(id: number) {
    const handle = handles.get(id)
    if (!handle || handle.pc.signalingState !== 'stable') return

    try {
      handle.makingOffer = true
      await handle.pc.setLocalDescription()
      await signal(id, { description: handle.pc.localDescription!.toJSON() })
    } catch {
      // Best effort: a dropped renegotiation is recovered by the next one, or by the ICE
      // restart in onconnectionstatechange.
    } finally {
      handle.makingOffer = false
    }
  }

  /** Renegotiate with everyone at once — after a camera or screen goes on or off. */
  function renegotiateAll() {
    return Promise.all([...handles.keys()].map(id => renegotiate(id)))
  }

  /** The other half of perfect negotiation: what to do with what they sent. */
  async function onSignal(payload: SignalPayload) {
    // Whispers reach every subscriber — Reverb has no way to address one. Everyone else's
    // handshake is simply not our business.
    if (payload.to !== user.value?.id) return

    const handle = handles.get(payload.from)
    if (!handle) return

    const { pc } = handle

    try {
      if (payload.description) {
        // The SDP arrives gzip'd as `sdpz` (see signal); older/uncompressed `sdp` is still
        // accepted so a half-deployed pair doesn't wedge.
        const wire = payload.description as RTCSessionDescriptionInit & { sdpz?: string }
        const description: RTCSessionDescriptionInit = wire.sdpz
          ? { type: wire.type, sdp: await base64ToGunzip(wire.sdpz) }
          : wire

        const readyForOffer = !handle.makingOffer
          && (pc.signalingState === 'stable' || handle.settingRemoteAnswer)
        const collision = description.type === 'offer' && !readyForOffer

        // Both of us offered at once. The impolite peer simply pretends it didn't hear —
        // its own offer is already in flight and will be the one that lands.
        handle.ignoreOffer = !handle.polite && collision
        if (handle.ignoreOffer) return

        handle.settingRemoteAnswer = description.type === 'answer'
        // The polite peer, mid-collision, rolls its own offer back here implicitly.
        await pc.setRemoteDescription(description)
        handle.settingRemoteAnswer = false
        // The first exchange is done, so the initial-glare guard in onnegotiationneeded can
        // stand down: from here the polite peer is free to offer too (to add its camera).
        handle.negotiated = true

        if (description.type === 'offer') {
          await pc.setLocalDescription()
          await signal(payload.from, { description: pc.localDescription!.toJSON() })
        }
      } else if (payload.candidate) {
        try {
          await pc.addIceCandidate(payload.candidate)
        } catch (err) {
          // Candidates for an offer we deliberately ignored are noise, not failure.
          if (!handle.ignoreOffer) throw err
        }
      }
    } catch {
      // Signalling is best-effort; a wedged connection is recovered by the ICE restart in
      // onconnectionstatechange rather than by unwinding this.
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
      localStream = await getMicStream(micId.value)
    } catch {
      status.value = 'error'
      error.value = 'We couldn\'t reach your microphone. Check the site\'s permissions and try again.'
      channelId.value = null
      return
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
      return
    }

    iceServers = joined.ice_servers
    voiceEffects.value = {
      default: joined.effects?.default ?? { join: null, leave: null },
      people: joined.effects?.people ?? [],
    }

    audioCtx = new AudioContext()
    await audioCtx.resume()
    localSource = audioCtx.createMediaStreamSource(localStream)
    localAnalyser = audioCtx.createAnalyser()
    localAnalyser.fftSize = 512
    localSource.connect(localAnalyser)

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

          createPeer(member.id, member.name, member.avatar)

          const state = known.get(member.id)
          if (state) {
            patchPeer(member.id, {
              muted: state.muted,
              deafened: state.deafened,
              screenSharing: state.screen_sharing,
              cameraOn: state.camera_on,
              audioSharing: state.audio_sharing,
            })
          }
        }
        status.value = 'connected'

        // Your own arrival, for you. `here` is the one place we know we've actually landed —
        // and deliberately *only* your own: everyone in this list arrived before you did, and
        // firing an effect for each of them would greet you with six fireworks at once.
        if (user.value) fireEffect('join', user.value.id, 'You')
      })
      .joining((member: { id: number, name: string, avatar: string | null }) => {
        createPeer(member.id, member.name, member.avatar)
        // They joined after our last state change, so their roster snapshot may predate
        // it. Say where we stand; it's one whisper.
        whisperState()
        fireEffect('join', member.id, nameOf(member.id, member.name))
      })
      .leaving((member: { id: number, name: string }) => {
        // Read before the teardown: destroyPeer drops them from `peers`, and the effect
        // wants to say whose exit it is.
        const name = peers.value.find(p => p.id === member.id)?.name ?? member.name
        destroyPeer(member.id)
        fireEffect('leave', member.id, nameOf(member.id, name))
      })
      .listenForWhisper('signal', onSignal)
      .listenForWhisper('state', (state: StatePayload) => {
        patchPeer(state.id, {
          muted: state.muted,
          deafened: state.deafened,
          screenSharing: state.screen_sharing,
          cameraOn: state.camera_on,
          audioSharing: state.audio_sharing,
        })
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
  }

  function teardownMedia() {
    stopScreenSampler()

    if (deviceChangeHandler) {
      navigator.mediaDevices?.removeEventListener?.('devicechange', deviceChangeHandler)
      deviceChangeHandler = undefined
    }

    for (const id of [...handles.keys()]) destroyPeer(id)

    localStream?.getTracks().forEach(track => track.stop())
    localStream = null

    // Every capture is stopped, not merely un-sent. This is what turns the camera light
    // and the "sharing your screen" bar off — and leaving either running after a call has
    // ended is the one bug in here that people would be right never to forgive.
    screenTrack?.stop()
    screenTrack = null
    screenAudioTrack?.stop()
    screenAudioTrack = null
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
    const open = micOpen.value
    localStream?.getAudioTracks().forEach(track => {
      track.enabled = open
    })
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

  /** Turn one person up or down, for you alone. `volume` is 0–1. */
  function setPeerVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { volume: clamped })
    applyAudio(id)
    savePref(id, { volume: clamped })
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
  function setWatchedScreen(key: number | 'self' | null) {
    watchedScreen.value = key
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
   * Switch your microphone mid-call.
   *
   * Open the new device, then `replaceTrack` it into every peer's mic sender — same-kind
   * swap, so no renegotiation and no gap the far end can hear. The catch is everything
   * *else* pointed at the old track: the speaking meter is re-hooked to the new capture, and
   * the new track inherits your current mute state so switching devices can't quietly unmute
   * you. The old capture is stopped last, once nothing depends on it.
   */
  async function setMicDevice(deviceId: string) {
    micId.value = deviceId
    saveSettings()
    if (!inCall.value) return

    let stream: MediaStream
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: { deviceId: { exact: deviceId }, ...MIC_AUDIO },
        video: false,
      })
    } catch {
      return // device vanished or was denied; the current mic keeps working
    }

    const track = stream.getAudioTracks()[0]
    if (!track) return

    // Carry mute across the swap — a fresh capture starts enabled, which would un-mute you
    // (and on push-to-talk, open the line without the key).
    track.enabled = micOpen.value

    await Promise.all([...handles.values()].map(h => h.micSender?.replaceTrack(track)))

    const old = localStream
    localStream = stream

    // Re-point the speaking meter at the new capture.
    if (audioCtx) {
      localSource?.disconnect()
      localSource = audioCtx.createMediaStreamSource(stream)
      if (localAnalyser) localSource.connect(localAnalyser)
    }

    old?.getTracks().forEach(t => t.stop())
    void refreshDevices() // labels firm up once a device is actually in use
  }

  /** Switch which speaker the call plays out of — every peer's voice and every shared screen. */
  async function setSpeaker(deviceId: string) {
    speakerId.value = deviceId
    saveSettings()
    await Promise.all([...handles.values()].flatMap(h => [
      applySinkId(h.audio, deviceId),
      applySinkId(h.screenAudio, deviceId),
    ]))
  }

  // --- screen sharing ---

  async function startScreenShare() {
    if (!inCall.value || isSharing.value) return

    // Both kinds of share send their sound down the one screen-audio slot, so they take
    // turns. A screen share is the fuller thing — it brings its own audio — so starting one
    // supersedes an audio-only share rather than being refused because of it.
    if (isAudioSharing.value) await stopAudioShare()

    let display: MediaStream
    try {
      display = await navigator.mediaDevices.getDisplayMedia({
        // Cap what we capture, not just what we send: a 1440p/4K desktop encoded once per
        // peer is what stutters a machine that's also gaming. The browser downscales to the
        // chosen height (default 720p), which is what actually cuts the encode load — see
        // setScreenResolution for changing it live.
        video: {
          frameRate: { ideal: SCREEN_MAX_FRAMERATE, max: SCREEN_MAX_FRAMERATE },
          height: { ideal: screenResolution.value, max: screenResolution.value },
        },
        // Ask for the tab/system audio too — sharing a video with no sound is half a share.
        // It's the browser's "Share tab audio" tick, so it's opt-in and often simply absent
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

    // Resolve the content mode for the first frames. 'auto' opens as 'detail' and the sampler
    // (started below) corrects it within a second or two once it can see what's on screen.
    const initialMode = screenMode.value === 'auto' ? 'detail' : screenMode.value
    resolvedScreenMode = initialMode
    const { hint, degradation, maxFramerate } = screenModeSettings(initialMode)
    screenTrack.contentHint = hint

    // The browser's own "Stop sharing" bar bypasses our button entirely. Either track ending
    // (the user stops the video, or just the audio) tears the whole share down.
    screenTrack.onended = () => { void stopScreenShare() }
    if (screenAudioTrack) screenAudioTrack.onended = () => { void stopScreenShare() }

    // Slot the picture into each peer's screen transceiver, and the sound into the audio one,
    // then tell them the slots are live — see renegotiate() for why the second half isn't
    // optional. The direction bump matters for the polite peer, whose adopted slots came up
    // recvonly: without flipping to sendrecv it can receive a screen but never send one.
    await Promise.all([...handles.values()].map(async (handle) => {
      const video = handle.screenTransceiver
      if (video) {
        if (video.direction !== 'sendrecv') video.direction = 'sendrecv'
        await video.sender.replaceTrack(screenTrack)

        const params = video.sender.getParameters()
        params.encodings = params.encodings?.length ? params.encodings : [{}]
        params.encodings[0]!.maxBitrate = SCREEN_MAX_BITRATE
        // Detail content is encoded at a low framerate (see SCREEN_DETAIL_FRAMERATE); motion
        // keeps the full rate. The capture stays at 30 either way, so an 'auto' flip to motion
        // is instant — it's the *encode* rate we're trimming, not the source.
        params.encodings[0]!.maxFramerate = maxFramerate
        // Under CPU/upload pressure, degrade the axis the mode says matters less — for
        // 'detail' shed framerate and keep the text sharp, for 'motion' the reverse. Not
        // every engine honours it, hence the tolerant set. See screenModeSettings.
        params.degradationPreference = degradation
        await video.sender.setParameters(params).catch(() => {})
      }

      const sound = handle.screenAudioTransceiver
      if (sound && screenAudioTrack) {
        if (sound.direction !== 'sendrecv') sound.direction = 'sendrecv'
        await sound.sender.replaceTrack(screenAudioTrack)
      }
    }))
    await renegotiateAll()

    screenStream.value = markRaw(display)
    startScreenSampler() // a no-op unless the mode is 'auto'
    await publishState()
  }

  async function stopScreenShare() {
    if (!isSharing.value) return

    stopScreenSampler()

    await Promise.all(
      [...handles.values()].map(async (handle) => {
        await handle.screenTransceiver?.sender.replaceTrack(null)
        await handle.screenAudioTransceiver?.sender.replaceTrack(null)
      }),
    )
    await renegotiateAll()

    screenTrack?.stop()
    screenTrack = null
    screenAudioTrack?.stop()
    screenAudioTrack = null
    screenStream.value = null

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
      display = await navigator.mediaDevices.getDisplayMedia({
        // Asked for as cheaply as the browser will allow, since it's stopped a line later.
        video: { frameRate: { max: 1 }, height: { max: 240 } },
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

    screenAudioTrack = track
    // The browser's own "Stop sharing" bar never touches our button.
    track.onended = () => { void stopAudioShare() }

    // The direction bump matters for the polite peer, whose slot was adopted recvonly: without
    // it they can hear a share but never send one. See createPeer.
    await Promise.all([...handles.values()].map(async (handle) => {
      const sound = handle.screenAudioTransceiver
      if (!sound) return

      if (sound.direction !== 'sendrecv') sound.direction = 'sendrecv'
      await sound.sender.replaceTrack(track)
    }))
    // Not optional: the slot was negotiated empty, and a far end that wasn't told a track
    // arrived drops its packets. See renegotiate().
    await renegotiateAll()

    // A stream of its own rather than the capture, which still holds the stopped video track.
    audioShareStream.value = markRaw(new MediaStream([track]))
    await publishState()
  }

  async function stopAudioShare() {
    if (!isAudioSharing.value) return

    await Promise.all(
      [...handles.values()].map(handle => handle.screenAudioTransceiver?.sender.replaceTrack(null)),
    )
    await renegotiateAll()

    // Stopped, not merely un-sent — this is what drops the browser's "sharing" indicator.
    screenAudioTrack?.stop()
    screenAudioTrack = null
    audioShareStream.value = null

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

    await Promise.all([...handles.values()].map(async (handle) => {
      const sender = handle.screenTransceiver?.sender
      if (!sender) return
      const params = sender.getParameters()
      if (!params.encodings?.length) return
      params.degradationPreference = degradation
      // Slides drop to a low framerate; a video that starts playing gets the full rate back.
      params.encodings[0]!.maxFramerate = maxFramerate
      await sender.setParameters(params).catch(() => {})
    }))
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
      if (guess !== resolvedScreenMode) void applyScreenMode(guess)
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
  async function startCamera() {
    if (!inCall.value || isCameraOn.value) return

    let capture: MediaStream
    try {
      capture = await navigator.mediaDevices.getUserMedia({
        video: { width: { ideal: 640 }, height: { ideal: 360 }, frameRate: { ideal: 24 } },
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

    await Promise.all([...handles.values()].map(async (handle) => {
      const transceiver = handle.cameraTransceiver
      if (!transceiver) return

      if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'
      const sender = transceiver.sender
      await sender.replaceTrack(cameraTrack)

      const params = sender.getParameters()
      params.encodings = params.encodings?.length ? params.encodings : [{}]
      params.encodings[0]!.maxBitrate = CAMERA_MAX_BITRATE
      await sender.setParameters(params).catch(() => {})
    }))
    await renegotiateAll()

    cameraStream.value = markRaw(capture)
    await publishState()
  }

  async function stopCamera() {
    if (!isCameraOn.value) return

    await Promise.all(
      [...handles.values()].map(handle => handle.cameraTransceiver?.sender.replaceTrack(null)),
    )
    await renegotiateAll()

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
    setWatchedScreen,
    inputDevices,
    outputDevices,
    micId,
    speakerId,
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
    toggleCamera,
    disconnectUser,
    disconnectAll,
    disconnectedByModerator,
    muteUser,
    mutedByModerator,
  }
}
