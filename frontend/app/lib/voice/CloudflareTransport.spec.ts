import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createCloudflareTransport, type CloudflareAnnouncement } from './CloudflareTransport'
import type { TransportEvents } from './VoiceTransport'

/**
 * The Cloudflare adapter, against a fake peer connection and a fake relay.
 *
 * What's worth testing here is the part Cloudflare doesn't do for us. LiveKit's adapter is
 * mostly translation and its tests are about mapping; this one has to *build* discovery —
 * announcing where our tracks live, pulling other people's from their coordinates, and
 * attributing whatever arrives back to a person via the mid Cloudflare hands out. Getting any
 * of that wrong is a black tile, and none of it is checked by anything else.
 */

const pcs: FakePeerConnection[] = []

class FakeTransceiver {
  mid: string
  direction = 'sendonly'
  sender = {
    replaceTrack: vi.fn(async () => {}),
    getParameters: vi.fn(() => ({ encodings: [{}] })),
    setParameters: vi.fn(async () => {}),
  }

  constructor(mid: string, public track: any) {
    this.mid = mid
  }
}

class FakePeerConnection {
  connectionState = 'new'
  localDescription: any = { type: 'offer', sdp: 'v=0-local' }
  transceivers: FakeTransceiver[] = []
  ontrack: any = null
  onconnectionstatechange: any = null
  remote: any = null

  createOffer = vi.fn(async () => ({ type: 'offer', sdp: 'v=0-local' }))
  createAnswer = vi.fn(async () => ({ type: 'answer', sdp: 'v=0-answer' }))
  setLocalDescription = vi.fn(async () => {})
  setRemoteDescription = vi.fn(async (d: any) => { this.remote = d })
  createDataChannel = vi.fn()
  close = vi.fn()

  constructor(public config: any) {
    pcs.push(this)
  }

  addTransceiver(track: any, init?: any) {
    const t = new FakeTransceiver(String(this.transceivers.length), track)
    ;(t as any).init = init
    this.transceivers.push(t)

    return t
  }
}

vi.stubGlobal('RTCPeerConnection', FakePeerConnection)

function events(): TransportEvents {
  return {
    peerJoined: vi.fn(),
    peerLeft: vi.fn(),
    trackReceived: vi.fn(),
    trackEnded: vi.fn(),
    peerStateChanged: vi.fn(),
    failed: vi.fn(),
    controlReceived: vi.fn(),
  }
}

/** A relay that records calls and answers with whatever the test queues up. */
function relay() {
  const calls: { path: string, body: any, method: string }[] = []
  const replies = new Map<string, any>()

  return {
    calls,
    reply: (path: string, value: any) => replies.set(path, value),
    api: vi.fn(async (path: string, body: any, method = 'POST') => {
      calls.push({ path, body, method })

      return replies.get(path) ?? {}
    }),
  }
}

function deps(bus: ReturnType<typeof relay>) {
  const announced: (CloudflareAnnouncement | null)[] = []
  let handler: ((peerId: number, a: CloudflareAnnouncement | null) => void) | null = null

  return {
    announced,
    tell: (peerId: number, a: CloudflareAnnouncement | null) => handler?.(peerId, a),
    deps: {
      api: bus.api as any,
      announce: (a: CloudflareAnnouncement | null) => { announced.push(a) },
      onAnnounce: (h: any) => {
        handler = h

        return () => { handler = null }
      },
    },
  }
}

const context = { channelId: 1, selfId: 42, iceServers: [], sfu: null, proximity: false }
const track = (id: string) => ({ id, kind: 'video' }) as any

let bus: ReturnType<typeof relay>
let wiring: ReturnType<typeof deps>
let handlers: TransportEvents

beforeEach(() => {
  vi.clearAllMocks()
  pcs.length = 0
  bus = relay()
  wiring = deps(bus)
  handlers = events()
  bus.reply('session', { session_id: 'sess-me', session_description: { type: 'answer', sdp: 'v=0' } })
})

describe('session', () => {
  it('opens with an offer, because cloudflare will not touch tracks on a dead connection', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    const session = bus.calls.find(c => c.path === 'session')!

    // A session created with no description has nothing to connect with, and the first track
    // call would race the handshake and lose.
    expect(session.body.session_description.type).toBe('offer')
    expect(pcs[0]!.createDataChannel).toHaveBeenCalled()
    expect(pcs[0]!.setRemoteDescription).toHaveBeenCalledWith({ type: 'answer', sdp: 'v=0' })
  })
})

describe('publishing', () => {
  it('offers the track and announces where it landed', async () => {
    bus.reply('tracks', { sessionDescription: { type: 'answer', sdp: 'v=0-cf' } })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))

    const publish = bus.calls.find(c => c.path === 'tracks')!

    expect(publish.body.tracks[0]).toMatchObject({ location: 'local', mid: '0', trackName: 'screen-42' })
    expect(publish.body.session_description.type).toBe('offer')

    // Announced only after Cloudflare accepted it — a name published earlier is a name peers
    // would fail to pull.
    expect(wiring.announced.at(-1)).toEqual({ session_id: 'sess-me', tracks: { screen: 'screen-42' } })
  })

  it('publishes on the same encoding budget as every other route', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))

    // An SFU forwards rather than transcodes, so what is published here is exactly what every
    // viewer gets. Publishing weaker than the mesh would make the server route look worse for
    // a reason nobody watching could guess at.
    const init = (pcs[0]!.transceivers[0]! as any).init

    expect(init.direction).toBe('sendonly')
    expect(init.sendEncodings[0]).toMatchObject({ maxBitrate: 2_500_000, maxFramerate: 30 })
  })

  it('keeps the bitrate cap when the detail/motion mode flips', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))

    await transport.setScreenEncoding({ degradationPreference: 'maintain-framerate', maxFramerate: 30 })

    const [params] = pcs[0]!.transceivers[0]!.sender.setParameters.mock.calls.at(-1)! as unknown as [any]

    // setParameters replaces the encoding wholesale, so a cap omitted here is a cap silently
    // dropped the first time the sampler changes its mind.
    expect(params.encodings[0].maxBitrate).toBe(2_500_000)
    expect(params.degradationPreference).toBe('maintain-framerate')
  })

  it('swaps a live track without renegotiating or re-announcing', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('a'))

    const before = bus.calls.length
    await transport.publish('screen', track('b'))

    expect(bus.calls).toHaveLength(before)
    expect(pcs[0]!.transceivers[0]!.sender.replaceTrack).toHaveBeenCalledWith({ id: 'b', kind: 'video' })
  })

  it('closes the track rather than leaving cloudflare to collect it', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))
    await transport.publish('screen', null)

    const close = bus.calls.find(c => c.path === 'tracks/close')!

    // Idle tracks are collected after 30s; until then it is still a track, and anything
    // flowing down it is still egress somebody pays for.
    expect(close.method).toBe('PUT')
    expect(close.body.tracks[0]).toMatchObject({ mid: '0', trackName: 'screen-42' })
    expect(wiring.announced.at(-1)).toBeNull()
  })

  it('offers the new shape when closing, which cloudflare refuses to do without', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))
    await transport.publish('screen', null)

    const close = bus.calls.find(c => c.path === 'tracks/close')!

    // A bare close comes back `invalid_params: sessionDescription must be present when
    // closing tracks` — dropping a track changes what our transceivers carry.
    expect(close.body.session_description).toMatchObject({ type: 'offer' })
  })
})

describe('discovery', () => {
  it('pulls a peer\'s track once it knows both that it wants it and where it is', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    // Knowing where it is, without wanting it, pulls nothing — that is proximity.
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })
    await vi.waitFor(() => expect(bus.calls.filter(c => c.path === 'tracks')).toHaveLength(0))

    transport.setSubscribed(5, true)

    await vi.waitFor(() => {
      const pull = bus.calls.find(c => c.path === 'tracks')!
      expect(pull.body.tracks[0]).toMatchObject({
        location: 'remote',
        sessionId: 'sess-them',
        trackName: 'screen-5',
      })
    })
  })

  it('pulls when the wanting comes first and the announcement second', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    transport.setSubscribed(5, true)
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })

    // Either order has to work: whether you walk up to somebody before or after they start
    // sharing is not something the transport gets to choose.
    await vi.waitFor(() => expect(bus.calls.some(c => c.path === 'tracks')).toBe(true))
  })

  it('attributes an arriving track by the mid cloudflare promised', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })

    await vi.waitFor(() => expect(bus.calls.some(c => c.path === 'tracks')).toBe(true))

    // The mid is the only thing tying a track that turns up to a person.
    pcs[0]!.ontrack({ track: track('theirs'), transceiver: { mid: '7' } })
    expect(handlers.trackReceived).toHaveBeenCalledWith(5, 'screen', { id: 'theirs', kind: 'video' })
  })

  it('ignores a track it cannot attribute', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    pcs[0]!.ontrack({ track: track('mystery'), transceiver: { mid: '99' } })
    expect(handlers.trackReceived).not.toHaveBeenCalled()
  })

  it('does not pull the same thing twice', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)

    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })
    await vi.waitFor(() => expect(bus.calls.filter(c => c.path === 'tracks')).toHaveLength(1))

    // A re-announcement is routine — every mute and camera toggle carries one.
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })
    await new Promise(r => setTimeout(r, 0))

    expect(bus.calls.filter(c => c.path === 'tracks')).toHaveLength(1)
  })

  it('pulls again when a peer comes back under a new session', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)

    wiring.tell(5, { session_id: 'sess-old', tracks: { screen: 'screen-5' } })
    await vi.waitFor(() => expect(bus.calls.filter(c => c.path === 'tracks')).toHaveLength(1))

    // They reconnected: the old session is gone, so what we pulled from it is gone too.
    wiring.tell(5, { session_id: 'sess-new', tracks: { screen: 'screen-5' } })
    await vi.waitFor(() => expect(bus.calls.filter(c => c.path === 'tracks')).toHaveLength(2))
  })

  it('answers cloudflare\'s offer when a pull reshapes the connection', async () => {
    bus.reply('tracks', {
      tracks: [{ mid: '7' }],
      requiresImmediateRenegotiation: true,
      sessionDescription: { type: 'offer', sdp: 'v=0-cf' },
    })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })

    await vi.waitFor(() => {
      const answer = bus.calls.find(c => c.path === 'renegotiate')!
      // Skipping this leaves the track negotiated but silent.
      expect(answer.body.session_description.type).toBe('answer')
    })
  })

  it('offers the new shape when dropping somebody\'s tracks too', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })

    await vi.waitFor(() => expect(bus.calls.some(c => c.path === 'tracks')).toBe(true))

    transport.setSubscribed(5, false)

    await vi.waitFor(() => {
      const close = bus.calls.find(c => c.path === 'tracks/close')!
      expect(close.body.session_description).toMatchObject({ type: 'offer' })
    })
  })

  it('stops paying for somebody who has walked away', async () => {
    bus.reply('tracks', { tracks: [{ mid: '7' }] })

    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    transport.setSubscribed(5, true)
    wiring.tell(5, { session_id: 'sess-them', tracks: { screen: 'screen-5' } })

    await vi.waitFor(() => expect(bus.calls.some(c => c.path === 'tracks')).toBe(true))

    transport.setSubscribed(5, false)

    // Cloudflare bills what it sends, so a subscription nobody is watching is pure waste.
    await vi.waitFor(() => expect(bus.calls.some(c => c.path === 'tracks/close')).toBe(true))
  })
})

describe('failure', () => {
  it('asks the caller to fall back when the session dies', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    pcs[0]!.connectionState = 'failed'
    pcs[0]!.onconnectionstatechange()

    expect(handlers.failed).toHaveBeenCalled()
  })

  it('withdraws its announcement on close, so nobody pulls a session that has gone', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)
    await transport.publish('screen', track('scr'))

    await transport.close()

    expect(wiring.announced.at(-1)).toBeNull()
    expect(pcs[0]!.close).toHaveBeenCalled()
  })

  it('stays quiet about a connection that fails after we closed it', async () => {
    const transport = createCloudflareTransport(wiring.deps)
    await transport.connect(context, handlers)

    const pc = pcs[0]!
    await transport.close()

    pc.connectionState = 'failed'
    pc.onconnectionstatechange?.()

    expect(handlers.failed).not.toHaveBeenCalled()
  })
})
