import type { IceServer, Peer, PeerConnectionState, VoiceParticipant } from '~/types'

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
} {
  return mode === 'motion'
    ? { hint: 'motion', degradation: 'maintain-framerate' }
    : { hint: 'detail', degradation: 'maintain-resolution' }
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
}

interface JoinResponse {
  data: VoiceParticipant[]
  ice_servers: IceServer[]
  max_participants: number
}

/** How loud you like each person, remembered between calls. */
interface LocalPrefs {
  volume: number
  muted: boolean
  /** How loud you like *their shared screen* — independent of their voice. */
  screenVolume?: number
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
  const selfDeafened = useState<boolean>('voice:selfDeafened', () => false)
  const selfSpeaking = useState<boolean>('voice:selfSpeaking', () => false)
  const screenStream = useState<MediaStream | null>('voice:screenStream', () => null)
  const cameraStream = useState<MediaStream | null>('voice:cameraStream', () => null)
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

  const inCall = computed(() => status.value === 'connected' || status.value === 'connecting')
  const isSharing = computed(() => screenStream.value !== null)
  const isCameraOn = computed(() => cameraStream.value !== null)
  /** Whoever is sharing right now — at most one screen is on the stage at a time. */
  const sharingPeer = computed(() => peers.value.find(p => p.screenSharing && p.screen) ?? null)

  // --- local preferences (yours, about other people) ---

  function loadPrefs(): Record<number, LocalPrefs> {
    if (typeof localStorage === 'undefined') return {}
    try {
      return JSON.parse(localStorage.getItem('voice:prefs') ?? '{}')
    } catch {
      return {}
    }
  }

  function savePref(userId: number, pref: LocalPrefs) {
    const prefs = loadPrefs()
    prefs[userId] = pref
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
  } {
    const fallback = {
      micId: null,
      speakerId: null,
      resolution: DEFAULT_SCREEN_RESOLUTION,
      mode: DEFAULT_SCREEN_MODE,
    }
    if (typeof localStorage === 'undefined') return fallback
    try {
      const saved = JSON.parse(localStorage.getItem('voice:settings') ?? '{}')
      return {
        micId: typeof saved.micId === 'string' ? saved.micId : null,
        speakerId: typeof saved.speakerId === 'string' ? saved.speakerId : null,
        resolution: SCREEN_RESOLUTIONS.includes(saved.resolution) ? saved.resolution : DEFAULT_SCREEN_RESOLUTION,
        mode: (['auto', 'detail', 'motion'] as const).includes(saved.mode) ? saved.mode : DEFAULT_SCREEN_MODE,
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
    handle.screenAudio.volume = peer.screenVolume
    handle.screenAudio.muted = muted || watchedScreen.value !== id
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
        sdpz: await gzipToBase64(payload.description.sdp ?? ''),
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
      muted: selfMuted.value,
      deafened: selfDeafened.value,
      screen_sharing: isSharing.value,
      camera_on: isCameraOn.value,
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
          muted: selfMuted.value,
          deafened: selfDeafened.value,
          screen_sharing: isSharing.value,
          camera_on: isCameraOn.value,
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
          if (!handle.screenAudioTransceiver) handle.screenAudioTransceiver = transceiver

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
      localMuted: pref?.muted ?? false,
      volume: pref?.volume ?? 1,
      screenVolume: pref?.screenVolume ?? 1,
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
        const talking = !selfMuted.value && loudness(localAnalyser, buffer) > SPEAKING_THRESHOLD
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
      // everybody, and then discovering the browser won't give us a microphone. Prefer the
      // remembered device with `ideal`, not `exact`, so a since-unplugged mic falls back to a
      // working one rather than failing the whole join.
      localStream = await navigator.mediaDevices.getUserMedia({
        audio: {
          ...(micId.value ? { deviceId: { ideal: micId.value } } : {}),
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
        },
        video: false,
      })
    } catch {
      status.value = 'error'
      error.value = 'We couldn\'t reach your microphone. Check the site\'s permissions and try again.'
      channelId.value = null
      return
    }

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
            })
          }
        }
        status.value = 'connected'
      })
      .joining((member: { id: number, name: string, avatar: string | null }) => {
        createPeer(member.id, member.name, member.avatar)
        // They joined after our last state change, so their roster snapshot may predate
        // it. Say where we stand; it's one whisper.
        whisperState()
      })
      .leaving((member: { id: number }) => destroyPeer(member.id))
      .listenForWhisper('signal', onSignal)
      .listenForWhisper('state', (state: StatePayload) => {
        patchPeer(state.id, {
          muted: state.muted,
          deafened: state.deafened,
          screenSharing: state.screen_sharing,
          cameraOn: state.camera_on,
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
    selfMuted.value = false
    selfDeafened.value = false
    selfSpeaking.value = false
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

  /** Mute *your* microphone, for everyone. Stops sending audio, rather than sending silence. */
  function toggleMute() {
    selfMuted.value = !selfMuted.value

    localStream?.getAudioTracks().forEach(track => {
      track.enabled = !selfMuted.value
    })

    if (selfMuted.value) selfSpeaking.value = false

    void publishState()
  }

  /** Silence everyone, for you. Muting your own mic too is the polite half of it. */
  function toggleDeafen() {
    selfDeafened.value = !selfDeafened.value

    if (selfDeafened.value && !selfMuted.value) {
      selfMuted.value = true
      localStream?.getAudioTracks().forEach(track => { track.enabled = false })
    }

    for (const id of handles.keys()) applyAudio(id)

    void publishState()
  }

  /** Mute one person, for you alone. They are never told. */
  function togglePeerMute(id: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const localMuted = !peer.localMuted
    patchPeer(id, { localMuted })
    applyAudio(id)
    savePref(id, { volume: peer.volume, muted: localMuted, screenVolume: peer.screenVolume })
  }

  /** Turn one person up or down, for you alone. `volume` is 0–1. */
  function setPeerVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { volume: clamped })
    applyAudio(id)
    savePref(id, { volume: clamped, muted: peer.localMuted, screenVolume: peer.screenVolume })
  }

  /** Turn one person's *shared screen* up or down, for you alone. `volume` is 0–1. */
  function setPeerScreenVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { screenVolume: clamped })
    applyAudio(id)
    savePref(id, { volume: peer.volume, muted: peer.localMuted, screenVolume: clamped })
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
        audio: { deviceId: { exact: deviceId }, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        video: false,
      })
    } catch {
      return // device vanished or was denied; the current mic keeps working
    }

    const track = stream.getAudioTracks()[0]
    if (!track) return

    // Carry mute across the swap — a fresh capture starts enabled, which would un-mute you.
    track.enabled = !selfMuted.value

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
    const { hint, degradation } = screenModeSettings(initialMode)
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
        params.encodings[0]!.maxFramerate = SCREEN_MAX_FRAMERATE
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

    const { hint, degradation } = screenModeSettings(resolved)
    screenTrack.contentHint = hint

    await Promise.all([...handles.values()].map(async (handle) => {
      const sender = handle.screenTransceiver?.sender
      if (!sender) return
      const params = sender.getParameters()
      if (!params.encodings?.length) return
      params.degradationPreference = degradation
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
    screenStream,
    cameraStream,
    inCall,
    isSharing,
    isCameraOn,
    sharingPeer,
    connect,
    disconnect,
    toggleMute,
    toggleDeafen,
    togglePeerMute,
    setPeerVolume,
    setPeerScreenVolume,
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
    toggleCamera,
    disconnectUser,
    disconnectAll,
    disconnectedByModerator,
  }
}
