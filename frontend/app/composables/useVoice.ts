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
  /** Their microphone. Kept alone, because it's the only thing the <audio> should sink. */
  audioStream: MediaStream
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
}

// Module scope, not component scope: one call, however many components are looking at it.
// None of this belongs in reactive state — Vue would proxy the RTCPeerConnections and
// MediaStreams, and a proxied MediaStream is not a MediaStream as far as the DOM is
// concerned (assigning one to `srcObject` throws).
const handles = new Map<number, PeerHandle>()
let localStream: MediaStream | null = null
let screenTrack: MediaStreamTrack | null = null
let cameraTrack: MediaStreamTrack | null = null
let iceServers: IceServer[] = []
let presence: any = null
let audioCtx: AudioContext | null = null
let localAnalyser: AnalyserNode | null = null
let heartbeatTimer: ReturnType<typeof setInterval> | undefined
let speakingFrame: number | undefined
let leaveOnUnload: (() => void) | undefined

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

    handle.audio.volume = peer.volume
    handle.audio.muted = peer.localMuted || selfDeafened.value
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
    const cameraStream = new MediaStream()
    const screenStream = new MediaStream()

    const audio = new Audio()
    audio.autoplay = true
    audio.srcObject = audioStream
    audioRoot().appendChild(audio)

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
      audioStream,
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
      cameraTransceiver: null,
      screenTransceiver: null,
    }

    for (const track of localStream.getAudioTracks()) {
      pc.addTrack(track, localStream)
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
      handle.cameraTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })
      handle.screenTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })

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
      // everybody, and then discovering the browser won't give us a microphone.
      localStream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
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
    const localSource = audioCtx.createMediaStreamSource(localStream)
    localAnalyser = audioCtx.createAnalyser()
    localAnalyser.fftSize = 512
    localSource.connect(localAnalyser)

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
    for (const id of [...handles.keys()]) destroyPeer(id)

    localStream?.getTracks().forEach(track => track.stop())
    localStream = null

    // Every capture is stopped, not merely un-sent. This is what turns the camera light
    // and the "sharing your screen" bar off — and leaving either running after a call has
    // ended is the one bug in here that people would be right never to forgive.
    screenTrack?.stop()
    screenTrack = null
    screenStream.value = null

    cameraTrack?.stop()
    cameraTrack = null
    cameraStream.value = null

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
    savePref(id, { volume: peer.volume, muted: localMuted })
  }

  /** Turn one person up or down, for you alone. `volume` is 0–1. */
  function setPeerVolume(id: number, volume: number) {
    const peer = peers.value.find(p => p.id === id)
    if (!peer) return

    const clamped = Math.min(1, Math.max(0, volume))
    patchPeer(id, { volume: clamped })
    applyAudio(id)
    savePref(id, { volume: clamped, muted: peer.localMuted })
  }

  // --- screen sharing ---

  async function startScreenShare() {
    if (!inCall.value || isSharing.value) return

    let display: MediaStream
    try {
      display = await navigator.mediaDevices.getDisplayMedia({
        video: { frameRate: 30 },
        // Some browsers can capture system/tab audio too. We only want the picture — the
        // conversation is already going the other way round, and mixing the two is how you
        // get an echo.
        audio: false,
      })
    } catch {
      return // the user changed their mind at the picker; that isn't an error
    }

    screenTrack = display.getVideoTracks()[0] ?? null
    if (!screenTrack) return

    // Tells the encoder to favour sharp text over smooth motion — the difference between
    // readable code and a blurry smear.
    screenTrack.contentHint = 'detail'

    // The browser's own "Stop sharing" bar bypasses our button entirely.
    screenTrack.onended = () => { void stopScreenShare() }

    // Slot it into each peer's screen transceiver, then tell them the slot is live — see
    // renegotiate() for why the second half isn't optional. The direction bump matters for
    // the polite peer, whose adopted slot came up recvonly: without flipping it to sendrecv
    // it can receive a screen but never send one.
    await Promise.all([...handles.values()].map(async (handle) => {
      const transceiver = handle.screenTransceiver
      if (!transceiver) return

      if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'
      const sender = transceiver.sender
      await sender.replaceTrack(screenTrack)

      const params = sender.getParameters()
      params.encodings = params.encodings?.length ? params.encodings : [{}]
      params.encodings[0]!.maxBitrate = SCREEN_MAX_BITRATE
      await sender.setParameters(params).catch(() => {})
    }))
    await renegotiateAll()

    screenStream.value = markRaw(display)
    await publishState()
  }

  async function stopScreenShare() {
    if (!isSharing.value) return

    await Promise.all(
      [...handles.values()].map(handle => handle.screenTransceiver?.sender.replaceTrack(null)),
    )
    await renegotiateAll()

    screenTrack?.stop()
    screenTrack = null
    screenStream.value = null

    await publishState()
  }

  function toggleScreenShare() {
    return isSharing.value ? stopScreenShare() : startScreenShare()
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
  // Only an owner may do this, and it's the *server* that decides so — these just ask, and
  // a 403 comes back to anyone who shouldn't have had the button in the first place. The
  // person on the receiving end isn't torn down from here: the server deletes their seat
  // and tells their own browser to hang up (see useUserStream), which drops them from the
  // presence channel, which is how everyone else — us included — sees them go.

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
   * You were disconnected by a moderator. Tear the call down and leave a word behind —
   * otherwise the audio just stops and the tiles vanish with nothing said about why.
   */
  async function disconnectedByModerator() {
    await disconnect()
    notice.value = 'You were disconnected from the call by a moderator.'
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
    toggleScreenShare,
    toggleCamera,
    disconnectUser,
    disconnectAll,
    disconnectedByModerator,
  }
}
