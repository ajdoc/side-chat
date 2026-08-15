/**
 * The peer-to-peer implementation of {@link VoiceTransport} — the original way this app has
 * always carried a call, now behind the same interface as the SFU.
 *
 * Everything here is about *transport*: peer connections, the negotiation dance, which
 * transceiver a track belongs in, and what a screen is allowed to cost. Deliberately nothing
 * about how media is presented — audio elements, the speaking meter, spatial placement, per-peer
 * volume and the roster all stay with the caller, because none of them change when the transport
 * does. A track goes out through {@link VoiceTransport.publish} and comes back as a
 * `trackReceived` event; what happens to it after that was never this file's business.
 *
 * Two things here have no counterpart in the SFU adapter, and both are inherent to a mesh:
 *
 * - **Perfect negotiation.** With no server in the middle both ends learn about each other at
 *   the same instant and both try to offer. One side is designated polite and rolls back on a
 *   collision. An SFU has one authority and needs none of this.
 *
 * - **The upload budget.** The same picture is encoded once per peer, so what a screen may cost
 *   depends on how many people are receiving it, and on what the network says back. Behind an
 *   SFU you publish once and this arithmetic disappears.
 */

import type {
  PeerConnectionState,
  TrackKind,
  TransportContext,
  TransportEvents,
  VoiceTransport,
} from './VoiceTransport'

import {
  base64ToGunzip,
  gzipToBase64,
  mungeOpus,
  preferEfficientVideo,
  preferOpus,
} from './sdp'

/** One signalling message, as it travels between two browsers. */
export interface SignalPayload {
  to: number
  from: number
  description?: RTCSessionDescriptionInit & { sdpz?: string }
  candidate?: RTCIceCandidateInit
}

/**
 * How signalling reaches the other browser.
 *
 * Injected rather than reached for, because a mesh needs a message bus and this file should not
 * care which one: today it's a Reverb presence channel's whispers, and the transport only needs
 * "send this" and "tell me when one arrives".
 */
export interface MeshSignaling {
  send: (body: Record<string, unknown>) => void
  /** Register the inbox. Returns an unsubscribe. */
  subscribe: (handler: (payload: SignalPayload) => void) => () => void
}

export interface MeshDeps {
  signaling: MeshSignaling
  /**
   * Whether an unsolicited offer from this person should be answered.
   *
   * In an ordinary call this never happens — everyone is dialled the moment they appear. In a
   * Side Space it happens constantly: peers are dialled off *positions*, and somebody standing
   * still hasn't broadcast one recently enough for a newcomer to know they exist. The newcomer
   * dials us, and refusing its offer would leave neither of us able to hear the other. Being
   * offered to is itself evidence we're in range — the far end computed the same distance we
   * would have — so the caller only has to confirm this is somebody who belongs in the room.
   */
  accepts?: (peerId: number) => boolean
}

/** Everything the transport keeps about one peer. Compare PeerHandle in useVoice, which keeps
 *  the presentation half — elements, analysers, spatial nodes — that this deliberately doesn't. */
interface MeshPeer {
  pc: RTCPeerConnection
  /** Perfect-negotiation state. `polite` rolls back on a collision; the other side ignores it. */
  polite: boolean
  makingOffer: boolean
  ignoreOffer: boolean
  settingRemoteAnswer: boolean
  /** Set once the first exchange completes, which releases the initial-glare guard. */
  negotiated: boolean
  micSender: RTCRtpSender | null
  screenAudioTransceiver: RTCRtpTransceiver | null
  cameraTransceiver: RTCRtpTransceiver | null
  screenTransceiver: RTCRtpTransceiver | null
  control: RTCDataChannel | null
}

// --- encoding budgets (see the mesh notes in useVoice for the full reasoning) ---

const SCREEN_MAX_BITRATE = 2_500_000
const SCREEN_UPLOAD_BUDGET = 6_000_000
const SCREEN_MIN_BITRATE = 500_000
const CAMERA_MAX_BITRATE = 600_000
const MIC_MAX_BITRATE = 32_000
const SHARED_AUDIO_MAX_BITRATE = 128_000

const SCREEN_STATS_INTERVAL_MS = 3000
const SCREEN_BACKOFF_STEP = 0.75
const SCREEN_RECOVER_STEP = 1.1
const SCREEN_MIN_SCALE = 0.35
const SCREEN_RECOVER_AFTER_POLLS = 4

export function createMeshTransport(deps: MeshDeps): VoiceTransport {
  const peers = new Map<number, MeshPeer>()
  /** What we're currently sending, by slot, so a peer dialled later gets it too. */
  const published = new Map<TrackKind, MediaStreamTrack>()
  /** Per-peer congestion scale — a bandwidth limit to one person is not the others' problem. */
  const scale = new Map<number, number>()
  const cleanPolls = new Map<number, number>()

  let context: TransportContext | null = null
  let events: TransportEvents | null = null
  let unsubscribe: (() => void) | null = null
  let statsTimer: ReturnType<typeof setInterval> | undefined
  let closed = false

  // --- signalling ---

  async function send(to: number, payload: { description?: RTCSessionDescriptionInit, candidate?: RTCIceCandidateInit }) {
    if (!context) return

    // The SDP rides compressed as `sdpz`; the tiny ICE candidates pass through untouched.
    const body: Record<string, unknown> = { to, from: context.selfId }

    if (payload.description) {
      body.description = {
        type: payload.description.type,
        sdpz: await gzipToBase64(mungeOpus(payload.description.sdp ?? '')),
      }
    }
    if (payload.candidate) body.candidate = payload.candidate

    deps.signaling.send(body)
  }

  async function onSignal(payload: SignalPayload) {
    // Whispers reach every subscriber — there's no way to address one. Everyone else's
    // handshake is simply not our business.
    if (!context || payload.to !== context.selfId) return

    let peer = peers.get(payload.from)

    if (!peer && deps.accepts?.(payload.from)) {
      dial(payload.from)
      peer = peers.get(payload.from)
    }

    if (!peer) return

    const { pc } = peer

    try {
      if (payload.description) {
        // Older/uncompressed `sdp` is still accepted so a half-deployed pair doesn't wedge.
        const wire = payload.description
        const description: RTCSessionDescriptionInit = wire.sdpz
          ? { type: wire.type, sdp: await base64ToGunzip(wire.sdpz) }
          : wire

        const readyForOffer = !peer.makingOffer
          && (pc.signalingState === 'stable' || peer.settingRemoteAnswer)
        const collision = description.type === 'offer' && !readyForOffer

        // Both of us offered at once. The impolite peer pretends it didn't hear — its own
        // offer is already in flight and will be the one that lands.
        peer.ignoreOffer = !peer.polite && collision
        if (peer.ignoreOffer) return

        peer.settingRemoteAnswer = description.type === 'answer'
        // The polite peer, mid-collision, rolls its own offer back here implicitly.
        await pc.setRemoteDescription(description)
        peer.settingRemoteAnswer = false
        // First exchange done, so the initial-glare guard can stand down.
        peer.negotiated = true

        if (description.type === 'offer') {
          await pc.setLocalDescription()
          await send(payload.from, { description: pc.localDescription!.toJSON() })
        }
      } else if (payload.candidate) {
        try {
          await pc.addIceCandidate(payload.candidate)
        } catch (err) {
          // Candidates for an offer we deliberately ignored are noise, not failure.
          if (!peer.ignoreOffer) throw err
        }
      }
    } catch {
      // Signalling is best-effort; a wedged connection is recovered by the ICE restart in
      // onconnectionstatechange rather than by unwinding this.
    }
  }

  /**
   * Force a fresh offer to one peer, after the tracks a sender carries have changed.
   *
   * The promise that `replaceTrack` needs no renegotiation is only half true. Swapping one live
   * track for another is genuinely free — but going from *nothing* to a track changes what the
   * m-line carries, and a peer that never hears about it drops the packets. That's the "they
   * can see the roster says I'm sharing, but the picture never arrives" bug.
   *
   * Skipped unless the connection is stable: mid-negotiation the offer we'd make here is the
   * one already in flight, and `onnegotiationneeded` will fire again anyway once it settles.
   */
  async function renegotiate(id: number) {
    const peer = peers.get(id)
    if (!peer || peer.pc.signalingState !== 'stable') return

    try {
      peer.makingOffer = true
      await peer.pc.setLocalDescription()
      await send(id, { description: peer.pc.localDescription!.toJSON() })
    } catch {
      // A failed offer isn't fatal: the ICE restart path recovers the connection.
    } finally {
      peer.makingOffer = false
    }
  }

  // --- encoding ---

  /** What one peer's copy of the screen may cost: the budget split, scaled by what stats say. */
  function screenBitrate(peerId: number) {
    const receivers = Math.max(1, [...peers.values()].filter(p => p.screenTransceiver?.sender.track).length)

    return Math.round(Math.min(
      SCREEN_MAX_BITRATE,
      Math.max(SCREEN_MIN_BITRATE, (SCREEN_UPLOAD_BUDGET / receivers) * (scale.get(peerId) ?? 1)),
    ))
  }

  async function capSender(sender: RTCRtpSender, maxBitrate: number) {
    const params = sender.getParameters()
    params.encodings = params.encodings?.length ? params.encodings : [{}]
    params.encodings[0]!.maxBitrate = maxBitrate

    await sender.setParameters(params).catch(() => {})
  }

  /** Re-cap every live screen sender: one person arriving changes everyone else's share. */
  async function refreshScreenBitrate() {
    if (!published.has('screen')) return

    await Promise.all([...peers.entries()].map(([id, peer]) => {
      const sender = peer.screenTransceiver?.sender

      return sender?.track ? capSender(sender, screenBitrate(id)) : undefined
    }))
  }

  /**
   * Ask each connection how the screen encode is going, and back off what's struggling.
   *
   * `bandwidth` is scoped to the peer that reported it — the person on hotel wifi gets a
   * smaller picture and nobody else notices. `cpu` is one machine's problem and so everyone's.
   */
  async function pollCongestion() {
    if (!published.has('screen')) return

    const limited = new Map<number, string>()

    await Promise.all([...peers.entries()].map(async ([id, peer]) => {
      const sender = peer.screenTransceiver?.sender
      if (!sender?.track) return

      const stats = await sender.getStats().catch(() => null)
      stats?.forEach((report: any) => {
        if (report.type !== 'outbound-rtp' || report.kind !== 'video') return
        if (report.qualityLimitationReason === 'bandwidth' || report.qualityLimitationReason === 'cpu') {
          limited.set(id, report.qualityLimitationReason)
        }
      })
    }))

    const cpuBound = [...limited.values()].includes('cpu')
    let changed = false

    for (const id of peers.keys()) {
      const current = scale.get(id) ?? 1
      let next = current

      if (cpuBound || limited.has(id)) {
        cleanPolls.set(id, 0)
        next = Math.max(SCREEN_MIN_SCALE, current * SCREEN_BACKOFF_STEP)
      } else if (current < 1) {
        const clean = (cleanPolls.get(id) ?? 0) + 1

        if (clean >= SCREEN_RECOVER_AFTER_POLLS) {
          cleanPolls.set(id, 0)
          next = Math.min(1, current * SCREEN_RECOVER_STEP)
        } else {
          cleanPolls.set(id, clean)
        }
      }

      if (next !== current) {
        scale.set(id, next)
        changed = true
      }
    }

    if (changed) await refreshScreenBitrate()
  }

  /** Put whatever we're sending into a video slot, with the right cap on it. */
  function fillVideoSlot(peerId: number, transceiver: RTCRtpTransceiver, kind: 'camera' | 'screen') {
    const track = published.get(kind)
    if (!track) return

    if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'

    void transceiver.sender.replaceTrack(track).then(() =>
      capSender(transceiver.sender, kind === 'screen' ? screenBitrate(peerId) : CAMERA_MAX_BITRATE),
    )
  }

  // --- peers ---

  function dial(id: number) {
    if (peers.has(id) || !context || closed) return

    const pc = new RTCPeerConnection({ iceServers: context.iceServers })

    const peer: MeshPeer = {
      pc,
      // Comparing user ids agrees on politeness without exchanging a word, and always yields
      // opposite answers on the two sides.
      polite: context.selfId < id,
      makingOffer: false,
      ignoreOffer: false,
      settingRemoteAnswer: false,
      negotiated: false,
      micSender: null,
      screenAudioTransceiver: null,
      cameraTransceiver: null,
      screenTransceiver: null,
      control: null,
    }

    /**
     * The remote-control input pipe, negotiated out-of-band.
     *
     * `negotiated: true` with a fixed id is doing real work: an in-band channel would fire
     * onnegotiationneeded at the exact moment both ends are already racing to set up video —
     * the glare the video-slot comment below spends its length avoiding.
     */
    try {
      peer.control = pc.createDataChannel('control', { negotiated: true, id: 0, ordered: true })
      peer.control.onmessage = (event) => {
        try {
          events?.controlReceived(id, JSON.parse(event.data as string))
        } catch {
          // A malformed frame is not worth tearing the session down over.
        }
      }
    } catch {
      // Data channels unavailable — remote control simply won't be offered for this peer.
    }

    const mic = published.get('mic')
    if (mic) {
      peer.micSender = pc.addTrack(mic)
      void capSender(peer.micSender, MIC_MAX_BITRATE)

      // Before the first offer, so the session negotiates Opus rather than a narrowband codec
      // none of the app's audio tuning reaches.
      const transceiver = pc.getTransceivers().find(t => t.sender === peer.micSender)
      if (transceiver) preferOpus(transceiver)
    }

    /**
     * Only the *impolite* peer lays out the video slots.
     *
     * The tidy-looking alternative — both ends creating the same transceivers — does not
     * survive contact with reality: both offer at once, and the rolled-back side's video
     * transceivers are left stranded, duplicated and stuck sendonly. That was the bug behind
     * every black remote video. One creator means there is nothing to duplicate, whatever the
     * timing; the polite peer adopts them as tracks arrive (see ontrack).
     */
    if (!peer.polite) {
      // Screen audio first, so the m-lines are [mic, screen-audio, camera, screen] on both
      // ends and the polite peer can adopt them in that order.
      peer.screenAudioTransceiver = pc.addTransceiver('audio', { direction: 'sendrecv' })
      preferOpus(peer.screenAudioTransceiver)
      peer.cameraTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })
      peer.screenTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' })

      preferEfficientVideo(peer.cameraTransceiver)
      preferEfficientVideo(peer.screenTransceiver)

      const screenAudio = published.get('screenAudio')
      if (screenAudio) void peer.screenAudioTransceiver.sender.replaceTrack(screenAudio)

      fillVideoSlot(id, peer.cameraTransceiver, 'camera')
      fillVideoSlot(id, peer.screenTransceiver, 'screen')
    }

    pc.onnegotiationneeded = async () => {
      // Break the initial glare: until the first exchange is done, only the impolite peer
      // offers. Afterwards `negotiated` is set and either side may.
      if (peer.polite && !peer.negotiated) return

      try {
        peer.makingOffer = true
        await pc.setLocalDescription()
        await send(id, { description: pc.localDescription!.toJSON() })
      } catch {
        // A failed offer isn't fatal: the ICE restart below picks the connection back up.
      } finally {
        peer.makingOffer = false
      }
    }

    pc.onicecandidate = ({ candidate }) => {
      if (candidate) void send(id, { candidate: candidate.toJSON() })
    }

    pc.onconnectionstatechange = () => {
      const state: PeerConnectionState = pc.connectionState === 'connected'
        ? 'connected'
        : pc.connectionState === 'failed'
          ? 'failed'
          : 'connecting'

      events?.peerStateChanged(id, state)

      // A network that moved under us (wifi → cellular, VPN up) fails the connection without
      // either side going anywhere. Re-gathering candidates usually recovers it.
      if (pc.connectionState === 'failed') pc.restartIce()
    }

    /**
     * Sort each incoming track into the slot it belongs to.
     *
     * A MediaStreamTrack carries nothing that says "webcam" rather than "screen". What it does
     * carry is the transceiver it arrived on — so telling a face from a screen is an identity
     * comparison against the two slots, never a guess. The polite peer, which created no slots,
     * learns them here in arrival order.
     */
    pc.ontrack = ({ track, transceiver }) => {
      if (track.kind === 'audio') {
        // Mic, or shared tab/system audio? The impolite peer knows its dedicated slot by
        // identity. The polite peer adopts: its own microphone slot has a local send-track,
        // whereas the screen-audio slot it merely received into does not.
        const isScreenAudio = peer.screenAudioTransceiver
          ? transceiver === peer.screenAudioTransceiver
          : !transceiver.sender.track

        if (isScreenAudio) {
          if (!peer.screenAudioTransceiver) {
            peer.screenAudioTransceiver = transceiver

            // We may already be sharing when this slot becomes known — somebody joining
            // mid-share has to be sent the sound, not merely be able to receive it.
            const screenAudio = published.get('screenAudio')
            if (screenAudio) {
              if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'
              void transceiver.sender.replaceTrack(screenAudio)
            }
          }

          events?.trackReceived(id, 'screenAudio', track)
          return
        }

        events?.trackReceived(id, 'mic', track)
        return
      }

      if (!peer.cameraTransceiver) {
        peer.cameraTransceiver = transceiver
        fillVideoSlot(id, transceiver, 'camera')
      } else if (transceiver !== peer.cameraTransceiver && !peer.screenTransceiver) {
        peer.screenTransceiver = transceiver
        fillVideoSlot(id, transceiver, 'screen')
      }

      events?.trackReceived(id, transceiver === peer.cameraTransceiver ? 'camera' : 'screen', track)
    }

    peers.set(id, peer)
    events?.peerJoined(id)

    // One more receiver: everybody's share of the upload budget just shrank.
    void refreshScreenBitrate()
  }

  function drop(id: number) {
    const peer = peers.get(id)
    if (!peer) return

    peer.pc.onnegotiationneeded = null
    peer.pc.onicecandidate = null
    peer.pc.onconnectionstatechange = null
    peer.pc.ontrack = null

    // Detached before close so a half-delivered input event can't land in a session whose peer
    // has just gone. `pc.close()` takes the channel with it.
    if (peer.control) peer.control.onmessage = null
    peer.pc.close()

    peers.delete(id)
    // Their congestion history goes with them: a rejoin on a different network shouldn't
    // inherit the throttle the last connection earned.
    scale.delete(id)
    cleanPolls.delete(id)

    events?.peerLeft(id)

    // One fewer copy to send — hand the budget back rather than staying throttled to the
    // call's busiest moment.
    void refreshScreenBitrate()
  }

  return {
    kind: 'mesh',

    async connect(ctx: TransportContext, handlers: TransportEvents) {
      context = ctx
      events = handlers
      closed = false

      unsubscribe = deps.signaling.subscribe(payload => { void onSignal(payload) })

      // Nothing to wait for: a mesh has no room to enter. Peers arrive as the caller dials
      // them with setSubscribed, which is also how proximity works in a Side Space.
      statsTimer = setInterval(() => { void pollCongestion() }, SCREEN_STATS_INTERVAL_MS)
    },

    async publish(kind: TrackKind, track: MediaStreamTrack | null) {
      if (track) published.set(kind, track)
      else published.delete(kind)

      await Promise.all([...peers.entries()].map(async ([id, peer]) => {
        if (kind === 'mic') {
          // A live swap into the slot that's already there — no renegotiation.
          if (peer.micSender) return peer.micSender.replaceTrack(track).catch(() => {})
          if (!track) return

          peer.micSender = peer.pc.addTrack(track)
          return capSender(peer.micSender, MIC_MAX_BITRATE)
        }

        const transceiver = kind === 'camera'
          ? peer.cameraTransceiver
          : kind === 'screen'
            ? peer.screenTransceiver
            : peer.screenAudioTransceiver

        // The polite peer may not have adopted this slot yet. Nothing is lost: `published`
        // holds the track and the slot is filled the moment it's adopted (see ontrack).
        if (!transceiver) return

        /*
         * Direction, not just the track — and this is load-bearing.
         *
         * `replaceTrack(null)` on its own leaves the m-line saying we still send, so the far
         * end merely sees the track go *muted*: the object stays, and `ontrack` will never
         * fire for that slot again. Anything downstream that dropped the muted track (a viewer
         * tidying a stream when a share moves to the SFU, say) then has nothing to put back
         * when we resume, because resuming only unmutes the track it already threw away.
         *
         * Standing the slot down to recvonly makes the withdrawal real: the far end's track
         * ends, and turning it back on delivers a *new* one through `ontrack` like any other
         * fresh publish. Costs a renegotiation, which the caller below is doing regardless.
         */
        if (track) {
          if (transceiver.direction !== 'sendrecv') transceiver.direction = 'sendrecv'
        } else if (transceiver.direction === 'sendrecv') {
          transceiver.direction = 'recvonly'
        }

        await transceiver.sender.replaceTrack(track).catch(() => {})

        if (!track) return

        if (kind === 'screen') await capSender(transceiver.sender, screenBitrate(id))
        else if (kind === 'camera') await capSender(transceiver.sender, CAMERA_MAX_BITRATE)
        else await capSender(transceiver.sender, SHARED_AUDIO_MAX_BITRATE)
      }))

      // A slot that went from empty to carrying something (or back) has to be re-offered —
      // see renegotiate. The microphone is exempt: its sender was there from the first offer,
      // so a swap into it is genuinely free.
      if (kind !== 'mic') await Promise.all([...peers.keys()].map(renegotiate))
    },

    /**
     * In a mesh, "subscribed" is whether a connection exists at all.
     *
     * That's the honest mapping of proximity here: there is no server to stop sending on our
     * behalf, so the only way to stop paying for somebody across the room is to not be
     * connected to them. It's also why the SFU does this better.
     */
    setSubscribed(peerId: number, subscribed: boolean) {
      if (subscribed) dial(peerId)
      else drop(peerId)
    },

    sendControl(peerId: number, payload: unknown) {
      const channel = peers.get(peerId)?.control
      if (channel?.readyState !== 'open') return
      // Backed-up buffer: drop it. These are pointer positions and a stale one is worse than
      // none — the next is along in ~16ms.
      if (channel.bufferedAmount > 64 * 1024) return

      try {
        channel.send(JSON.stringify(payload))
      } catch {
        // Channel closed under us; teardown will notice.
      }
    },

    async setScreenEncoding({ degradationPreference, maxFramerate }) {
      await Promise.all([...peers.values()].map(async (peer) => {
        const sender = peer.screenTransceiver?.sender
        if (!sender?.track) return

        const params = sender.getParameters()
        if (!params.encodings?.length) return

        params.degradationPreference = degradationPreference
        // Slides drop to a low framerate; a video that starts playing gets the full rate back.
        params.encodings[0]!.maxFramerate = maxFramerate

        await sender.setParameters(params).catch(() => {})
      }))
    },

    controlReady(peerId: number) {
      return peers.get(peerId)?.control?.readyState === 'open'
    },

    async close() {
      closed = true
      clearInterval(statsTimer)
      statsTimer = undefined

      unsubscribe?.()
      unsubscribe = null

      for (const id of [...peers.keys()]) drop(id)

      published.clear()
      scale.clear()
      cleanPolls.clear()
      events = null
      context = null
    },
  }
}
