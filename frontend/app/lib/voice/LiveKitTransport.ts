/**
 * The LiveKit implementation of {@link VoiceTransport}.
 *
 * The whole point of this file is how little of it there is. Everything the mesh spends
 * thousands of lines on — offers, answers, polite/impolite collision handling, pre-negotiated
 * transceiver slots, renegotiation storms when a camera comes on — has no equivalent here,
 * because there is exactly one connection (to the SFU) and publishing a track is a method call.
 * What's left is translation: LiveKit's vocabulary into the app's.
 *
 * Three translations carry most of the weight:
 *
 * - **Identity is the user id.** The backend signs it into the token's `sub` claim (see
 *   LiveKitProvider), LiveKit hands it back as `participant.identity`, and this parses it back
 *   to a number. That is the entire mapping between "a participant on the SFU" and "a person in
 *   this app", and it is why the token may not use a prettier identity.
 *
 * - **Track.Source is the slot.** A call carries four distinct streams per person and the app
 *   has always kept them apart — mixing the screen's audio into the microphone's slot is what
 *   makes a screen mute silence a voice. LiveKit's `source` is that same distinction, so the
 *   two enums are mapped directly rather than inferred from track kind.
 *
 * - **Failure is a first-class outcome.** Anything that means "this call cannot be carried this
 *   way" — a rejected token, a room at capacity, a disconnect we didn't ask for — surfaces as a
 *   rejection or the `failed` event, because the caller's answer to that is to fall back to the
 *   mesh, not to show an error.
 */

import {
  ConnectionState,
  DisconnectReason,
  type RemoteParticipant,
  type RemoteTrack,
  type RemoteTrackPublication,
  Room,
  RoomEvent,
  Track,
} from 'livekit-client'

import type {
  PeerConnectionState,
  TrackKind,
  TransportContext,
  TransportEvents,
  VoiceTransport,
} from './VoiceTransport'

/**
 * How long to wait for the room to come up before giving up on it.
 *
 * A ceiling on the fallback, not on the SDK: LiveKit will patiently keep retrying a connection
 * that is never going to work, and every second of that is a second the caller is not spending
 * building the mesh that would have worked. Long enough for a slow phone on mobile data.
 */
const CONNECT_TIMEOUT_MS = 10_000

/** The app's slots, in LiveKit's vocabulary. */
const SOURCE_FOR: Record<TrackKind, Track.Source> = {
  mic: Track.Source.Microphone,
  camera: Track.Source.Camera,
  screen: Track.Source.ScreenShare,
  screenAudio: Track.Source.ScreenShareAudio,
}

/** And back again. Anything we don't recognise isn't ours to route. */
function kindForSource(source: Track.Source): TrackKind | null {
  switch (source) {
    case Track.Source.Microphone: return 'mic'
    case Track.Source.Camera: return 'camera'
    case Track.Source.ScreenShare: return 'screen'
    case Track.Source.ScreenShareAudio: return 'screenAudio'
    default: return null
  }
}

/**
 * Screen encoding.
 *
 * Simulcast is switched on here and *only* here, which is the thing that was wrong to do in a
 * mesh: three layers to every peer multiplies an upload that was already the problem. Behind an
 * SFU it inverts — the server picks a layer per receiver, so one person on hotel wifi takes the
 * small one and nobody else is dragged down to meet them. This is the case simulcast exists for.
 */
const SCREEN_PUBLISH = {
  simulcast: true,
  // Matches the mesh's own ceiling (SCREEN_MAX_BITRATE in useVoice) so the picture doesn't
  // visibly change quality when a call falls back — the failure should be invisible.
  videoEncoding: { maxBitrate: 2_500_000, maxFramerate: 30 },
} as const

/** Voice is mono speech and gets the mesh's budget, for the same reason. */
const MIC_PUBLISH = { audioPreset: undefined, dtx: true, red: true } as const

export function createLiveKitTransport(): VoiceTransport {
  const room = new Room({
    // Let the SDK stop paying for video nobody is looking at — a tile scrolled out of view or
    // rendered tiny doesn't need full resolution. Free on the mesh (there was no server to ask)
    // and one of the things an SFU buys.
    adaptiveStream: true,
    dynacast: true,
  })

  let listeners: TransportEvents | null = null
  /** Local tracks we published, by slot, so `publish(kind, null)` knows what to retract. */
  const published = new Map<TrackKind, MediaStreamTrack>()
  let closed = false

  /** LiveKit identities are strings; ours are user ids. Anything else isn't a person we know. */
  function peerIdOf(participant: { identity: string }): number | null {
    const id = Number(participant.identity)

    return Number.isInteger(id) && id > 0 ? id : null
  }

  function emitTrack(
    track: RemoteTrack,
    publication: RemoteTrackPublication,
    participant: RemoteParticipant,
    ended: boolean,
  ) {
    const peerId = peerIdOf(participant)
    const kind = kindForSource(publication.source)

    if (peerId === null || kind === null || !track.mediaStreamTrack) return

    if (ended) listeners?.trackEnded(peerId, kind)
    else listeners?.trackReceived(peerId, kind, track.mediaStreamTrack)
  }

  function wire(events: TransportEvents) {
    room
      .on(RoomEvent.ParticipantConnected, (participant) => {
        const peerId = peerIdOf(participant)
        if (peerId !== null) events.peerJoined(peerId)
      })
      .on(RoomEvent.ParticipantDisconnected, (participant) => {
        const peerId = peerIdOf(participant)
        if (peerId !== null) events.peerLeft(peerId)
      })
      .on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
        emitTrack(track, publication, participant, false)
      })
      .on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
        emitTrack(track, publication, participant, true)
      })
      .on(RoomEvent.ConnectionStateChanged, (state) => {
        const mapped = PEER_STATE[state]
        if (!mapped) return

        // LiveKit reports the state of *our* one connection to the server, not of a link to a
        // particular person — but from where the UI sits those are the same fact, since losing
        // the server is losing everyone. So it's reported for every peer.
        for (const participant of room.remoteParticipants.values()) {
          const peerId = peerIdOf(participant)
          if (peerId !== null) events.peerStateChanged(peerId, mapped)
        }
      })
      .on(RoomEvent.Disconnected, (reason) => {
        // A disconnect we asked for is not a failure — close() has already run and the caller
        // is not waiting to hear about it.
        if (closed) return

        events.failed(reason === undefined ? 'disconnected' : DisconnectReason[reason] ?? String(reason))
      })
      .on(RoomEvent.DataReceived, (payload, participant) => {
        if (!participant) return

        const peerId = peerIdOf(participant)
        if (peerId === null) return

        try {
          events.controlReceived(peerId, JSON.parse(new TextDecoder().decode(payload)))
        } catch {
          // Not ours, or truncated. Remote control sends another frame in ~16ms.
        }
      })
  }

  return {
    kind: 'sfu',

    async connect(context: TransportContext, events: TransportEvents) {
      if (!context.sfu) throw new Error('no sfu credentials')

      listeners = events
      wire(events)

      try {
        await withTimeout(
          room.connect(context.sfu.url, context.sfu.token, {
            // In a Side Space, proximity decides who you hear — so subscribe to nobody up front
            // and let the caller open people up as they walk into range. This is the SFU's real
            // advantage over the mesh's version of the same rule: walking away stops the media
            // at the *server*, so it costs the sender nothing rather than merely costing you.
            autoSubscribe: !context.proximity,
          }),
          CONNECT_TIMEOUT_MS,
        )
      } catch (err) {
        // Leave nothing running behind a rejection — the caller is about to build a mesh, and a
        // half-open room would go on emitting events into a transport nobody is listening to.
        await this.close()
        throw err
      }

      // Anyone already in the room when we arrived. ParticipantConnected only fires for people
      // who show up *after* us, so without this a late joiner sees an empty call.
      for (const participant of room.remoteParticipants.values()) {
        const peerId = peerIdOf(participant)
        if (peerId === null) continue

        events.peerJoined(peerId)

        for (const publication of participant.trackPublications.values()) {
          if (publication.track) emitTrack(publication.track as RemoteTrack, publication, participant, false)
        }
      }
    },

    async publish(kind: TrackKind, track: MediaStreamTrack | null) {
      const previous = published.get(kind)

      if (previous && previous !== track) {
        published.delete(kind)
        // `false`: the track belongs to the caller, which stops it when the *user* stops
        // sharing. Stopping it here would kill the local preview too.
        await room.localParticipant.unpublishTrack(previous, false).catch(() => {})
      }

      if (!track) return

      published.set(kind, track)

      await room.localParticipant.publishTrack(track, {
        source: SOURCE_FOR[kind],
        ...(kind === 'screen' ? SCREEN_PUBLISH : {}),
        ...(kind === 'mic' ? MIC_PUBLISH : {}),
      })
    },

    setSubscribed(peerId: number, subscribed: boolean) {
      const participant = [...room.remoteParticipants.values()]
        .find(p => peerIdOf(p) === peerId)

      if (!participant) return

      for (const publication of participant.trackPublications.values()) {
        publication.setSubscribed(subscribed)
      }
    },

    sendControl(peerId: number, payload: unknown) {
      void room.localParticipant.publishData(
        new TextEncoder().encode(JSON.stringify(payload)),
        {
          // Lossy on purpose, exactly as the mesh's data channel is: this carries pointer
          // movement at ~60/second and a frame that arrives late is worse than one that never
          // arrives at all.
          reliable: false,
          destinationIdentities: [String(peerId)],
        },
      ).catch(() => {})
    },

    async setScreenEncoding({ degradationPreference, maxFramerate }) {
      // One publication rather than one per peer — the SFU fans it out, so there is a single
      // encoding to retune however many people are watching.
      const publication = room.localParticipant.getTrackPublication(Track.Source.ScreenShare)
      const sender = (publication?.track as any)?.sender as RTCRtpSender | undefined
      if (!sender) return

      const params = sender.getParameters()
      if (!params.encodings?.length) return

      params.degradationPreference = degradationPreference
      // Applied to the top simulcast layer; the lower ones are already framerate-limited.
      params.encodings[0]!.maxFramerate = maxFramerate

      await sender.setParameters(params).catch(() => {})
    },

    controlReady(peerId: number) {
      // No per-peer pipe to check: data rides the one room connection, so anybody actually in
      // the room is reachable.
      return room.state === ConnectionState.Connected
        && [...room.remoteParticipants.values()].some(p => peerIdOf(p) === peerId)
    },

    async close() {
      closed = true
      listeners = null
      published.clear()
      room.removeAllListeners()
      // `false` again: the caller owns its tracks and its own teardown.
      await room.disconnect(false).catch(() => {})
    },
  }
}

/** LiveKit's view of our one connection, in the app's per-peer vocabulary. */
const PEER_STATE: Partial<Record<ConnectionState, PeerConnectionState>> = {
  [ConnectionState.Connecting]: 'connecting',
  [ConnectionState.Connected]: 'connected',
  [ConnectionState.Reconnecting]: 'reconnecting',
  [ConnectionState.SignalReconnecting]: 'reconnecting',
  // Disconnected deliberately absent: it arrives with RoomEvent.Disconnected, which is the
  // fallback signal and a stronger statement than any per-peer state.
}

/** Reject if a promise hasn't settled in time, so a stalled connect can't stall the fallback. */
function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('sfu connect timed out')), ms)

    promise.then(
      (value) => { clearTimeout(timer); resolve(value) },
      (error) => { clearTimeout(timer); reject(error) },
    )
  })
}
