/**
 * The Cloudflare Realtime implementation of {@link VoiceTransport}.
 *
 * Cloudflare's SFU is unopinionated to a degree that reshapes the adapter. There is no room, no
 * participant list, no client SDK and — the part that matters most here — **no track discovery**.
 * What it gives you is a *Session*, which is exactly one RTCPeerConnection to Cloudflare, and
 * *Tracks* within it that are either `local` (going up) or `remote` (coming down, named by
 * whoever published them).
 *
 * So this file sits between the two transports it shares a namespace with. Like LiveKitTransport
 * it publishes to a server rather than to peers; like MeshTransport it does its own SDP, its own
 * transceivers and its own renegotiation, because nothing is doing that for it.
 *
 * Three things are worth understanding before reading on:
 *
 * - **The app secret can't be here.** Every Cloudflare API call goes through our backend (see
 *   SfuController), which is why this takes an `api` dependency rather than a URL and a token.
 *
 * - **Discovery is ours to build.** To pull somebody's screen you need their *session id* and
 *   the *track name* they published under. Cloudflare will never tell you either. We already
 *   have a channel that carries exactly this sort of thing — the state whispers that tell the
 *   call who is sharing — so the transport announces its own coordinates and is told other
 *   people's through the `announce`/`onAnnounce` pair below.
 *
 * - **Pulling renegotiates.** Adding a remote track changes the shape of our connection, so
 *   Cloudflare answers a pull with an *offer* of its own, which we must answer back. Every
 *   negotiation is therefore serialised (see `queue`): two overlapping ones would each set a
 *   local description the other didn't expect, and the connection would wedge.
 */

import type {
  TrackKind,
  TransportContext,
  TransportEvents,
  VoiceTransport,
} from './VoiceTransport'

import { preferEfficientVideo, preferOpus } from './sdp'

/*
 * The same encoding budget the other two transports use.
 *
 * An SFU forwards; it does not transcode. So whatever is published here is exactly what every
 * viewer receives, and a route that publishes on weaker settings than the mesh looks worse for
 * no reason anybody watching could guess at. These match MeshTransport's ceiling and
 * LiveKitTransport's screenShareEncoding deliberately: moving a share between routes should
 * change the route and not the picture.
 */
const SCREEN_MAX_BITRATE = 2_500_000
const SCREEN_MAX_FRAMERATE = 30
const SHARED_AUDIO_MAX_BITRATE = 128_000

/** Where a peer's media lives, as far as Cloudflare is concerned. */
export interface CloudflareAnnouncement {
  session_id: string
  /** Track name per slot — `{ screen: 'screen-42' }`. Absent slots aren't being published. */
  tracks: Partial<Record<TrackKind, string>>
}

export interface CloudflareDeps {
  /**
   * Call one of the relay endpoints. Scoped to the channel by the caller, so this file never
   * builds a URL out of call state it would then have to keep in step.
   */
  api: <T>(path: 'session' | 'tracks' | 'renegotiate' | 'tracks/close', body: unknown, method?: 'POST' | 'PUT') => Promise<T>
  /** Tell the call where our tracks are. Rides the existing state whisper. */
  announce: (announcement: CloudflareAnnouncement | null) => void
  /** Learn where somebody else's are. Returns an unsubscribe. */
  onAnnounce: (handler: (peerId: number, announcement: CloudflareAnnouncement | null) => void) => () => void
}

/** What Cloudflare sends back from a track or session call. */
interface TrackResponse {
  sessionDescription?: RTCSessionDescriptionInit
  requiresImmediateRenegotiation?: boolean
  tracks?: { mid?: string, trackName?: string, error?: unknown }[]
}

export function createCloudflareTransport(deps: CloudflareDeps): VoiceTransport {
  let pc: RTCPeerConnection | null = null
  let sessionId: string | null = null
  let events: TransportEvents | null = null
  let selfId = 0
  let unsubscribe: (() => void) | null = null
  let closed = false

  /** What we're sending, and the transceiver carrying each — needed to close them again. */
  const localTracks = new Map<TrackKind, { transceiver: RTCRtpTransceiver, trackName: string }>()
  /** Who has announced what, whether or not we currently want it. */
  const announced = new Map<number, CloudflareAnnouncement>()
  /** Who we've been asked to listen to. Proximity, in a Side Space. */
  const wanted = new Set<number>()
  /** Pulls already in flight or done, keyed `peerId:kind`, so we don't pull twice. */
  const pulled = new Set<string>()
  /** `mid` → who and what, so an arriving track can be attributed. Cloudflare tells us the mid. */
  const byMid = new Map<string, { peerId: number, kind: TrackKind }>()

  /**
   * Every negotiation, one at a time.
   *
   * Not an optimisation — a correctness requirement. Publishing and pulling both call
   * setLocalDescription, and two of those interleaved leave the peer connection in a state
   * neither half expected. A promise chain is enough because nothing here needs concurrency.
   */
  let queue: Promise<unknown> = Promise.resolve()

  function serialise<T>(work: () => Promise<T>): Promise<T> {
    const run = queue.then(work, work)
    // Swallowed on the chain only — the caller still sees its own rejection.
    queue = run.catch(() => {})

    return run
  }

  /** A stable name for one of our slots. Unique per person, which is all Cloudflare needs. */
  function trackNameFor(kind: TrackKind) {
    return `${kind}-${selfId}`
  }

  function publishAnnouncement() {
    if (!sessionId || localTracks.size === 0) return deps.announce(null)

    const tracks: Partial<Record<TrackKind, string>> = {}
    for (const [kind, { trackName }] of localTracks) tracks[kind] = trackName

    deps.announce({ session_id: sessionId, tracks })
  }

  /**
   * Open the session, and the peer connection with it.
   *
   * The offer is made here rather than on the first publish because Cloudflare requires the
   * connection to actually be *up* before it will do anything with tracks — it waits about five
   * seconds and then refuses. A data channel gives the offer something to describe on a session
   * that has nothing to send yet, which is the normal case: most people in a call are only ever
   * going to be watching somebody else's screen.
   */
  async function openSession(iceServers: RTCIceServer[]) {
    pc = new RTCPeerConnection({ iceServers, bundlePolicy: 'max-bundle' })

    pc.ontrack = ({ track, transceiver }) => {
      const mid = transceiver.mid
      const owner = mid ? byMid.get(mid) : undefined

      // A track we can't attribute is one we didn't ask for; there is no sensible slot for it.
      if (!owner) return

      events?.trackReceived(owner.peerId, owner.kind, track)
    }

    pc.onconnectionstatechange = () => {
      if (closed) return
      // Losing the session is losing every screen on it. The caller's answer is to put the
      // share back on the mesh, which is what `failed` asks for.
      if (pc?.connectionState === 'failed') events?.failed('cloudflare connection failed')
    }

    // Something to describe. Never written to.
    pc.createDataChannel('keepalive')

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)

    const result = await deps.api<{ session_id: string, session_description: RTCSessionDescriptionInit | null }>(
      'session',
      { session_description: { type: 'offer', sdp: pc.localDescription!.sdp } },
    )

    sessionId = result.session_id

    if (result.session_description) await pc.setRemoteDescription(result.session_description)
  }

  /** Answer the offer Cloudflare makes when a pull changes our connection's shape. */
  async function answerCloudflare(offer: RTCSessionDescriptionInit) {
    if (!pc || !sessionId) return

    await pc.setRemoteDescription(offer)
    const answer = await pc.createAnswer()
    await pc.setLocalDescription(answer)

    await deps.api('renegotiate', {
      session_id: sessionId,
      session_description: { type: 'answer', sdp: pc.localDescription!.sdp },
    }, 'PUT')
  }

  /**
   * Close some tracks, offering the connection's new shape along with it.
   *
   * The offer is not optional: Cloudflare answers a bare close with
   * `invalid_params: sessionDescription must be present when closing tracks`. Which is fair —
   * dropping a track changes what our transceivers carry, and a close without a description
   * would leave its idea of the connection and ours disagreeing.
   *
   * @param tracks Cloudflare's own shape: a mid for something we send, a mid for something we
   *               were being sent.
   */
  async function closeTracks(tracks: { mid?: string, trackName?: string }[]) {
    if (!pc || !sessionId || tracks.length === 0) return

    const offer = await pc.createOffer()
    await pc.setLocalDescription(offer)

    const result = await deps.api<TrackResponse>('tracks/close', {
      session_id: sessionId,
      tracks,
      session_description: { type: 'offer', sdp: pc.localDescription!.sdp },
    }, 'PUT').catch(() => null)

    if (result?.sessionDescription) await pc.setRemoteDescription(result.sessionDescription)
  }

  /** Pull one slot from one peer, if we want it, know where it is, and haven't already. */
  async function pull(peerId: number, kind: TrackKind) {
    const key = `${peerId}:${kind}`
    const announcement = announced.get(peerId)
    const trackName = announcement?.tracks[kind]

    if (!pc || !sessionId || !trackName || pulled.has(key) || !wanted.has(peerId)) return

    pulled.add(key)

    try {
      const result = await deps.api<TrackResponse>('tracks', {
        session_id: sessionId,
        tracks: [{ location: 'remote', sessionId: announcement!.session_id, trackName }],
      })

      // Cloudflare names the mid it will deliver on, which is the only thing tying the track
      // that turns up in `ontrack` back to a person.
      const mid = result.tracks?.[0]?.mid
      if (mid) byMid.set(mid, { peerId, kind })

      if (result.requiresImmediateRenegotiation && result.sessionDescription) {
        await answerCloudflare(result.sessionDescription)
      }
    } catch {
      // Let it be retried — a pull that failed because the far end hadn't finished publishing
      // is the common case, and the next announcement will come round again.
      pulled.delete(key)
    }
  }

  /** Everything we want and know how to reach, pulled. Safe to call whenever either changes. */
  function pullAll() {
    for (const peerId of wanted) {
      const announcement = announced.get(peerId)
      if (!announcement) continue

      for (const kind of Object.keys(announcement.tracks) as TrackKind[]) {
        void serialise(() => pull(peerId, kind))
      }
    }
  }

  return {
    kind: 'sfu',

    async connect(context: TransportContext, handlers: TransportEvents) {
      events = handlers
      selfId = context.selfId
      closed = false

      await openSession(context.iceServers as RTCIceServer[])

      unsubscribe = deps.onAnnounce((peerId, announcement) => {
        if (!announcement) {
          announced.delete(peerId)
          return
        }

        const previous = announced.get(peerId)
        announced.set(peerId, announcement)

        // A new session id means they restarted: whatever we pulled before is gone, so forget
        // it rather than sitting on a subscription to a session that no longer exists.
        if (previous && previous.session_id !== announcement.session_id) {
          for (const key of [...pulled]) {
            if (key.startsWith(`${peerId}:`)) pulled.delete(key)
          }
        }

        pullAll()
      })
    },

    async publish(kind: TrackKind, track: MediaStreamTrack | null) {
      const existing = localTracks.get(kind)

      if (!track) {
        if (!existing || !pc || !sessionId) return

        localTracks.delete(kind)

        await serialise(async () => {
          await existing.transceiver.sender.replaceTrack(null).catch(() => {})
          // Stood down so the offer below describes a slot that really has stopped sending.
          existing.transceiver.direction = 'inactive'

          // Closed rather than left to time out: Cloudflare collects an idle track after 30
          // seconds, and until then it is still a track — and still egress, if anything is
          // flowing down it.
          await closeTracks([{ mid: existing.transceiver.mid ?? undefined, trackName: existing.trackName }])
        })

        publishAnnouncement()
        return
      }

      // Same slot, new source — a live swap, no negotiation, nothing for peers to re-pull.
      if (existing) {
        await existing.transceiver.sender.replaceTrack(track).catch(() => {})
        return
      }

      if (!pc || !sessionId) return

      await serialise(async () => {
        const video = track.kind === 'video'

        const transceiver = pc!.addTransceiver(track, {
          direction: 'sendonly',
          // Set at creation rather than patched on afterwards: the first frames go out during
          // negotiation, and an encoding applied a moment later has already let them past.
          sendEncodings: [video
            ? { maxBitrate: SCREEN_MAX_BITRATE, maxFramerate: SCREEN_MAX_FRAMERATE }
            : { maxBitrate: SHARED_AUDIO_MAX_BITRATE }],
        })

        /*
         * The codec, which is the part that was actually costing quality.
         *
         * Left alone, this negotiates VP8 — while the mesh reorders to VP9 (preferEfficientVideo)
         * and LiveKit is told `videoCodec: 'vp9'` outright. VP9 is noticeably sharper on screen
         * text at the same bitrate, so an unset preference here meant the *server* route looked
         * softer than the direct one and nothing in the code said why.
         */
        if (video) preferEfficientVideo(transceiver)
        else preferOpus(transceiver)

        const trackName = trackNameFor(kind)

        const offer = await pc!.createOffer()
        await pc!.setLocalDescription(offer)

        // `mid` is only assigned once the local description is set, which is why the offer has
        // to be made before Cloudflare can be told what it is being offered.
        const result = await deps.api<TrackResponse>('tracks', {
          session_id: sessionId,
          tracks: [{ location: 'local', mid: transceiver.mid, trackName }],
          session_description: { type: 'offer', sdp: pc!.localDescription!.sdp },
        })

        if (result.sessionDescription) await pc!.setRemoteDescription(result.sessionDescription)

        localTracks.set(kind, { transceiver, trackName })
      })

      // Only now — a name announced before Cloudflare has accepted it is a name that peers
      // will fail to pull.
      publishAnnouncement()
    },

    setSubscribed(peerId: number, subscribed: boolean) {
      if (subscribed) {
        wanted.add(peerId)
        pullAll()
        return
      }

      wanted.delete(peerId)

      // Their tracks stop being wanted, so stop paying for them. Cloudflare bills what it
      // sends, and a subscription nobody is watching is the easiest waste to avoid.
      const announcement = announced.get(peerId)

      for (const key of [...pulled]) {
        if (!key.startsWith(`${peerId}:`)) continue
        pulled.delete(key)
      }

      const closing: { mid: string, trackName?: string }[] = []

      for (const [mid, owner] of [...byMid]) {
        if (owner.peerId !== peerId) continue

        byMid.delete(mid)
        closing.push({ mid, trackName: announcement?.tracks[owner.kind] })
      }

      // One close for all of them: each one re-offers, and a burst of separate offers is a
      // burst of renegotiations for something that is a single change.
      if (closing.length) void serialise(() => closeTracks(closing))
    },

    sendControl() {
      // Remote control rides the mesh's data channel. Cloudflare has a DataChannel API, but
      // this transport only ever carries a screen — the mesh is always up alongside it, and
      // duplicating the control path would mean two ways for input to arrive out of order.
    },

    controlReady() {
      return false
    },

    async setScreenEncoding({ degradationPreference, maxFramerate }) {
      const screen = localTracks.get('screen')
      if (!screen) return

      const params = screen.transceiver.sender.getParameters()
      if (!params.encodings?.length) return

      params.degradationPreference = degradationPreference
      params.encodings[0]!.maxFramerate = maxFramerate
      // Re-asserted rather than assumed: setParameters replaces the encoding wholesale, so a
      // cap left out here is a cap silently dropped the first time the sampler flips modes.
      params.encodings[0]!.maxBitrate = SCREEN_MAX_BITRATE

      await screen.transceiver.sender.setParameters(params).catch(() => {})
    },

    async close() {
      closed = true

      unsubscribe?.()
      unsubscribe = null

      // Withdraw our coordinates before going, so nobody tries to pull a session that has gone.
      deps.announce(null)

      localTracks.clear()
      announced.clear()
      wanted.clear()
      pulled.clear()
      byMid.clear()

      pc?.close()
      pc = null
      sessionId = null
      events = null
    },
  }
}
