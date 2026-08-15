/**
 * How a call is carried.
 *
 * There are two answers and there will be more: a full mesh of peer connections (what this app
 * has always done), and an SFU that takes one copy of your media and fans it out. This file is
 * the line between them — the vocabulary both can speak, so that everything above it (the call
 * UI, per-peer volume, spatial audio, the Side Space stage) never learns which one is running.
 *
 * Three things shaped this interface, and they're worth stating because they explain what is
 * *not* here:
 *
 * 1. **The server chooses.** It knows the admin's policy, which providers exist, and holds
 *    their credentials, so a join response names a transport (see VoiceTransportResolver on
 *    the backend). A transport never decides it should be the one running.
 *
 * 2. **The client may disagree.** An SFU that is out of quota, unreachable, or simply slow to
 *    connect must not end the call — the caller falls back to the mesh, which is why
 *    `ice_servers` arrives with every join response whatever the chosen transport, and why
 *    `connect` rejecting is a normal outcome rather than a crash.
 *
 * 3. **Only the transport is abstracted.** Everything that happens to media *after* it arrives
 *    — per-peer volume, spatial placement, the speaking meter, the echo canceller — is local
 *    and identical either way. None of it belongs here.
 *
 * What's deliberately absent: any notion of an offer, an answer, a transceiver, or a peer
 * connection. Those are mesh mechanics. An SFU has no equivalent, and leaking them into this
 * interface would make the SFU adapter a pile of no-op methods pretending to negotiate.
 */

import type { IceServer, VoiceParticipant } from '~/types'

/** What the backend's VoiceTransportResolver decided. */
export type TransportKind = 'mesh' | 'sfu'

/** The SFU block of a join response — absent when the answer was 'mesh'. */
export interface SfuCredentials {
  /** Which client adapter to use. Matches the backend driver name. */
  driver: string
  /** Which configured provider answered. Diagnostics only; the client doesn't branch on it. */
  provider: string
  url: string
  room: string
  token: string
}

/** Everything a transport needs to open a call, taken straight from the join response. */
export interface TransportContext {
  channelId: number
  /** Us. A transport needs to know which participant is not a peer. */
  selfId: number
  iceServers: IceServer[]
  /** Present only when the server chose 'sfu'. */
  sfu: SfuCredentials | null
  /**
   * Proximity mode — a Side Space, where distance decides who you can hear.
   *
   * The mesh implements this by not opening a connection at all; an SFU implements it by not
   * subscribing. Same intent, opposite mechanism, which is exactly why the transport is told
   * *that it applies* rather than how to do it.
   */
  proximity: boolean
}

/**
 * What a transport tells the world.
 *
 * Events rather than return values because media arrives when it arrives: a peer's camera can
 * come up minutes after they joined, and in an SFU it may arrive without anything local having
 * asked for it.
 */
export interface TransportEvents {
  /** Someone is now reachable. Fired once per peer, before any of their tracks. */
  peerJoined: (peerId: number, participant?: VoiceParticipant) => void
  peerLeft: (peerId: number) => void
  /**
   * A track from a peer. `kind` says which slot it belongs in, because a call carries four
   * distinct streams per person and mixing them up is the bug that makes a screen share mute
   * somebody's voice.
   */
  trackReceived: (peerId: number, kind: TrackKind, track: MediaStreamTrack) => void
  trackEnded: (peerId: number, kind: TrackKind) => void
  /** Connection quality for one peer, as the transport understands it. */
  peerStateChanged: (peerId: number, state: PeerConnectionState) => void
  /**
   * The transport has failed in a way it cannot recover from.
   *
   * The signal for a fallback: the caller tears this transport down and builds a mesh in its
   * place. Distinct from a single peer failing, which is that peer's problem and not the
   * call's.
   */
  failed: (reason: string) => void
  /** One remote-control input frame. Sent ~60×/second while a pointer is moving. */
  controlReceived: (peerId: number, payload: unknown) => void
}

export type TrackKind = 'mic' | 'camera' | 'screen' | 'screenAudio'

export type PeerConnectionState = 'connecting' | 'connected' | 'reconnecting' | 'failed'

/**
 * One way of carrying a call.
 *
 * Implementations are stateful and single-use: `connect` once, `close` once, build a new one
 * to rejoin. That's what makes fallback clean — a failed SFU transport is discarded whole
 * rather than reset, so none of its half-open state can leak into the mesh that replaces it.
 */
export interface VoiceTransport {
  /** Matches the backend's transport name, and what the caller logs when falling back. */
  readonly kind: TransportKind

  /**
   * Open the call.
   *
   * Rejects if the call cannot be carried this way — an SFU refusing the token, a room at
   * capacity, a connection that never completes. A rejection is the fallback's cue and must
   * leave nothing running behind it.
   */
  connect: (context: TransportContext, events: TransportEvents) => Promise<void>

  /** Start sending a local track, or stop sending one by passing null. */
  publish: (kind: TrackKind, track: MediaStreamTrack | null) => Promise<void>

  /**
   * Whether we want this peer's media at all.
   *
   * Proximity in a Side Space. In a mesh this decides whether a peer connection exists; on an
   * SFU it decides whether we subscribe — the second being strictly better, since a person
   * across the room stops costing the *sender* anything.
   */
  setSubscribed: (peerId: number, subscribed: boolean) => void

  /** Send a remote-control input frame to one peer. Lossy: the next frame is ~16ms away. */
  sendControl: (peerId: number, payload: unknown) => void

  /**
   * Whether input would actually reach this peer right now.
   *
   * "Request control" shouldn't be offered against a pipe that isn't up. The two transports
   * answer it from different facts — a mesh from its data channel's readyState, an SFU from
   * whether the participant is in the room — which is exactly why the caller asks rather than
   * inspecting anything itself.
   */
  controlReady: (peerId: number) => boolean

  /**
   * Retune the live screen encoding — what to shed first under pressure, and how fast to send.
   *
   * The *decision* is the caller's: it watches the picture and works out whether it's looking
   * at a slide deck or a film (see the sampler in useVoice), and that judgement has nothing to
   * do with how the bytes travel. Applying it does, which is why it's asked for here rather
   * than reached for — a mesh sets it on every peer's sender, an SFU on its one publication.
   *
   * A no-op when nothing is being shared.
   */
  setScreenEncoding: (options: {
    degradationPreference: RTCDegradationPreference
    maxFramerate: number
  }) => Promise<void>

  /** Tear everything down. Idempotent — fallback and a user hanging up can both call it. */
  close: () => Promise<void>
}
