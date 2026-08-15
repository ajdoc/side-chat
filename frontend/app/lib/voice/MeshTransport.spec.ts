import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createMeshTransport, type SignalPayload } from './MeshTransport'
import type { TransportEvents } from './VoiceTransport'

/**
 * The mesh's transport half, tested against fake peer connections.
 *
 * What's worth testing here is the part with no server to arbitrate it: perfect negotiation,
 * which side lays out the video slots, how a track is matched back to the slot it arrived in,
 * and the upload budget that exists only because a mesh sends the same picture N times. Those
 * are the places where a mistake shows up as "their video is black" or "the call stutters for
 * everyone", and none of them were covered before.
 *
 * RTCPeerConnection is faked. The browser's implementation is not what can break.
 */

const created: FakePeerConnection[] = []

class FakeTransceiver {
  direction = 'sendrecv'
  sender: any = {
    track: null as any,
    replaceTrack: vi.fn(async function (this: any, t: any) { this.track = t }),
    getParameters: vi.fn(() => ({ encodings: [{}] })),
    setParameters: vi.fn(async () => {}),
    getStats: vi.fn(async () => new Map()),
  }

  constructor(public readonly kind: string) {
    this.sender.replaceTrack = vi.fn(async (t: any) => { this.sender.track = t })
  }

  setCodecPreferences = vi.fn()
}

class FakePeerConnection {
  static config: any
  signalingState = 'stable'
  connectionState = 'new'
  localDescription = { type: 'offer', sdp: 'v=0', toJSON: () => ({ type: 'offer', sdp: 'v=0' }) }
  transceivers: FakeTransceiver[] = []
  senders: any[] = []
  channels: any[] = []

  onnegotiationneeded: any = null
  onicecandidate: any = null
  onconnectionstatechange: any = null
  ontrack: any = null

  setLocalDescription = vi.fn(async () => {})
  setRemoteDescription = vi.fn(async (d: any) => { this.remote = d })
  addIceCandidate = vi.fn(async () => {})
  restartIce = vi.fn()
  close = vi.fn()
  remote: any = null

  constructor(config: any) {
    FakePeerConnection.config = config
    created.push(this)
  }

  addTransceiver(kind: string) {
    const t = new FakeTransceiver(kind)
    this.transceivers.push(t)

    return t
  }

  addTrack(track: any) {
    const sender = {
      track,
      replaceTrack: vi.fn(async () => {}),
      getParameters: vi.fn(() => ({ encodings: [{}] })),
      setParameters: vi.fn(async () => {}),
      getStats: vi.fn(async () => new Map()),
    }
    this.senders.push(sender)
    const t = new FakeTransceiver('audio')
    t.sender = sender
    this.transceivers.push(t)

    return sender
  }

  getTransceivers() {
    return this.transceivers
  }

  createDataChannel() {
    const channel = {
      readyState: 'open',
      bufferedAmount: 0,
      send: vi.fn(),
      onmessage: null as any,
    }
    this.channels.push(channel)

    return channel
  }
}

vi.stubGlobal('RTCPeerConnection', FakePeerConnection)
// The codec helpers bail out politely when this is absent, which is what we want in a test —
// they're covered by their own behaviour, not by the mesh's.
vi.stubGlobal('RTCRtpReceiver', undefined)

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

/** A signalling bus that records what was sent and can deliver what "arrived". */
function signaling() {
  const sent: any[] = []
  let inbox: ((payload: SignalPayload) => void) | null = null

  return {
    sent,
    deliver: (payload: SignalPayload) => inbox?.(payload),
    bus: {
      send: (body: any) => { sent.push(body) },
      subscribe: (handler: (payload: SignalPayload) => void) => {
        inbox = handler

        return () => { inbox = null }
      },
    },
  }
}

function context(selfId = 10) {
  return { channelId: 1, selfId, iceServers: [{ urls: 'stun:x' }], sfu: null, proximity: false }
}

const track = (id: string, kind = 'video') => ({ id, kind }) as any

let bus: ReturnType<typeof signaling>
let handlers: TransportEvents

beforeEach(() => {
  vi.clearAllMocks()
  created.length = 0
  bus = signaling()
  handlers = events()
})

describe('politeness', () => {
  it('lays out the video slots on the impolite side only', async () => {
    // Lower id is polite, so self=10 dialling 5 makes us impolite.
    const impolite = createMeshTransport({ signaling: bus.bus })
    await impolite.connect(context(10), handlers)
    impolite.setSubscribed(5, true)

    // screen-audio, camera, screen — in that order, so the polite end can adopt by arrival.
    expect(created[0]!.transceivers.map(t => t.kind)).toEqual(['audio', 'video', 'video'])

    created.length = 0

    const polite = createMeshTransport({ signaling: signaling().bus })
    await polite.connect(context(10), events())
    polite.setSubscribed(20, true)

    // Creating them on both sides is what stranded transceivers and made remote video black.
    expect(created[0]!.transceivers).toHaveLength(0)
  })

  it('holds the polite peer back from offering until the first exchange is done', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(20, true) // 10 < 20, so we are polite

    await created[0]!.onnegotiationneeded()
    expect(bus.sent).toHaveLength(0)

    // An answer completes the first exchange and releases the guard.
    bus.deliver({ to: 10, from: 20, description: { type: 'answer', sdp: 'v=0' } })
    await vi.waitFor(() => expect(created[0]!.setRemoteDescription).toHaveBeenCalled())

    await created[0]!.onnegotiationneeded()
    expect(bus.sent.length).toBeGreaterThan(0)
  })

  it('ignores a colliding offer when impolite, and takes it when polite', async () => {
    const impolite = createMeshTransport({ signaling: bus.bus })
    await impolite.connect(context(10), handlers)
    impolite.setSubscribed(5, true)

    const pc = created[0]!
    pc.signalingState = 'have-local-offer' // mid-offer: a collision

    bus.deliver({ to: 10, from: 5, description: { type: 'offer', sdp: 'v=0' } })
    await new Promise(r => setTimeout(r, 0))

    // Our own offer is already in flight and is the one that will land.
    expect(pc.setRemoteDescription).not.toHaveBeenCalled()

    created.length = 0
    const politeBus = signaling()
    const polite = createMeshTransport({ signaling: politeBus.bus })
    await polite.connect(context(10), events())
    polite.setSubscribed(20, true)

    created[0]!.signalingState = 'have-local-offer'
    politeBus.deliver({ to: 10, from: 20, description: { type: 'offer', sdp: 'v=0' } })

    // The polite peer rolls its own offer back and answers.
    await vi.waitFor(() => expect(created[0]!.setRemoteDescription).toHaveBeenCalled())
  })
})

describe('signal routing', () => {
  it('ignores a whisper meant for somebody else', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    bus.deliver({ to: 999, from: 5, description: { type: 'offer', sdp: 'v=0' } })
    await new Promise(r => setTimeout(r, 0))

    expect(created[0]!.setRemoteDescription).not.toHaveBeenCalled()
  })

  it('answers an unsolicited offer only when the caller vouches for the sender', async () => {
    const transport = createMeshTransport({ signaling: bus.bus, accepts: id => id === 7 })
    await transport.connect(context(10), handlers)

    // A stranger: no connection, and none created.
    bus.deliver({ to: 10, from: 99, description: { type: 'offer', sdp: 'v=0' } })
    await new Promise(r => setTimeout(r, 0))
    expect(created).toHaveLength(0)

    // Somebody the room vouches for — the Side Space case, where being offered to is itself
    // evidence we're in range.
    bus.deliver({ to: 10, from: 7, description: { type: 'offer', sdp: 'v=0' } })
    await vi.waitFor(() => expect(created).toHaveLength(1))
    expect(handlers.peerJoined).toHaveBeenCalledWith(7)
  })
})

describe('track slots', () => {
  it('tells a camera from a screen by the transceiver it arrived on', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true) // impolite: slots exist up front

    const pc = created[0]!
    const [, camera, screen] = pc.transceivers

    pc.ontrack({ track: track('cam'), transceiver: camera })
    pc.ontrack({ track: track('scr'), transceiver: screen })

    expect(handlers.trackReceived).toHaveBeenNthCalledWith(1, 5, 'camera', { id: 'cam', kind: 'video' })
    expect(handlers.trackReceived).toHaveBeenNthCalledWith(2, 5, 'screen', { id: 'scr', kind: 'video' })
  })

  it('adopts video slots in arrival order on the polite side', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(20, true) // polite: no slots of its own

    const pc = created[0]!
    const first = new FakeTransceiver('video')
    const second = new FakeTransceiver('video')

    pc.ontrack({ track: track('a'), transceiver: first })
    pc.ontrack({ track: track('b'), transceiver: second })

    // The impolite peer creates them camera-then-screen and the m-lines arrive in that order.
    expect(handlers.trackReceived).toHaveBeenNthCalledWith(1, 20, 'camera', { id: 'a', kind: 'video' })
    expect(handlers.trackReceived).toHaveBeenNthCalledWith(2, 20, 'screen', { id: 'b', kind: 'video' })
  })

  it('separates the shared audio from the microphone', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    const pc = created[0]!
    const screenAudio = pc.transceivers[0]!

    pc.ontrack({ track: track('sound', 'audio'), transceiver: screenAudio })
    // Mixing these would let a screen mute silence somebody's voice.
    expect(handlers.trackReceived).toHaveBeenCalledWith(5, 'screenAudio', { id: 'sound', kind: 'audio' })

    const mic = new FakeTransceiver('audio')
    mic.sender.track = track('mine', 'audio') // a slot we send on is the microphone's
    pc.ontrack({ track: track('voice', 'audio'), transceiver: mic })
    expect(handlers.trackReceived).toHaveBeenCalledWith(5, 'mic', { id: 'voice', kind: 'audio' })
  })
})

describe('publishing', () => {
  it('gives a peer dialled mid-share whatever is already being sent', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)

    await transport.publish('screen', track('shared'))
    transport.setSubscribed(5, true)

    const screenSlot = created[0]!.transceivers[2]!
    // Without this, somebody joining mid-share sees a person the roster says is sharing, and
    // no picture, until the sharer stops and starts again.
    await vi.waitFor(() => expect(screenSlot.sender.replaceTrack).toHaveBeenCalled())
  })

  it('swaps the microphone into the slot that already exists, without renegotiating', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)

    await transport.publish('mic', track('mic-1', 'audio'))
    transport.setSubscribed(5, true)

    const sender = created[0]!.senders[0]!
    await transport.publish('mic', track('mic-2', 'audio'))

    expect(sender.replaceTrack).toHaveBeenCalledWith({ id: 'mic-2', kind: 'audio' })
  })

  it('re-offers when a slot goes from empty to carrying something', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    // Complete the first exchange so the connection is stable and offers are allowed.
    bus.deliver({ to: 10, from: 5, description: { type: 'answer', sdp: 'v=0' } })
    await vi.waitFor(() => expect(created[0]!.setRemoteDescription).toHaveBeenCalled())

    const before = bus.sent.length
    await transport.publish('screen', track('shared'))

    // Without this the far end drops the packets: they see the roster say we're sharing and
    // never get a picture.
    expect(bus.sent.length).toBeGreaterThan(before)
  })

  it('does not renegotiate for a microphone swap, which needs no offer', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    await transport.publish('mic', track('mic-1', 'audio'))
    transport.setSubscribed(5, true)

    bus.deliver({ to: 10, from: 5, description: { type: 'answer', sdp: 'v=0' } })
    await vi.waitFor(() => expect(created[0]!.setRemoteDescription).toHaveBeenCalled())

    const before = bus.sent.length
    await transport.publish('mic', track('mic-2', 'audio'))

    // The sender was in the very first offer; swapping the track behind it is free.
    expect(bus.sent).toHaveLength(before)
  })

  it('stands the slot down when a track is withdrawn, so the far end sees it end', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    const screenSlot = created[0]!.transceivers[2]!

    await transport.publish('screen', track('shared'))
    expect(screenSlot.direction).toBe('sendrecv')

    await transport.publish('screen', null)
    // Not merely replaceTrack(null): that leaves the m-line claiming we still send, so the
    // viewer's track goes muted rather than ending and `ontrack` never fires again. A share
    // that came back — moved off the SFU, say — would then never reach them.
    expect(screenSlot.direction).toBe('recvonly')

    // And putting it back re-opens the slot, which is what delivers a fresh track.
    await transport.publish('screen', track('again'))
    expect(screenSlot.direction).toBe('sendrecv')
  })

  it('stops sending when a track is withdrawn', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    await transport.publish('camera', track('cam'))
    await transport.publish('camera', null)

    const cameraSlot = created[0]!.transceivers[1]!
    expect(cameraSlot.sender.replaceTrack).toHaveBeenLastCalledWith(null)
  })
})

describe('upload budget', () => {
  it('shrinks each peer\'s share as the call grows, and hands it back as it empties', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    await transport.publish('screen', track('shared'))

    transport.setSubscribed(1, true)
    await vi.waitFor(() => expect(created[0]!.transceivers[2]!.sender.setParameters).toHaveBeenCalled())

    const capOf = (pc: FakePeerConnection) => {
      const calls = pc.transceivers[2]!.sender.setParameters.mock.calls

      return calls[calls.length - 1]![0].encodings[0].maxBitrate
    }

    const alone = capOf(created[0]!)

    for (const id of [2, 3, 4, 5]) transport.setSubscribed(id, true)
    await vi.waitFor(() => expect(capOf(created[0]!)).toBeLessThan(alone))

    const crowded = capOf(created[0]!)
    // 2.5Mbps each to seven people is ~17Mbps of upload — past what a home connection carries,
    // on a machine that is very often also running the game being shared.
    expect(crowded).toBeLessThan(alone)

    for (const id of [2, 3, 4, 5]) transport.setSubscribed(id, false)
    await vi.waitFor(() => expect(capOf(created[0]!)).toBeGreaterThan(crowded))
  })

  it('never caps below the floor, however busy it gets', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    await transport.publish('screen', track('shared'))

    for (let id = 1; id <= 30; id++) transport.setSubscribed(id, true)

    await vi.waitFor(() => {
      const calls = created[0]!.transceivers[2]!.sender.setParameters.mock.calls
      const last = calls[calls.length - 1]![0].encodings[0].maxBitrate
      // Past a point a smaller number stops buying smoothness and just looks broken.
      expect(last).toBeGreaterThanOrEqual(500_000)
    })
  })
})

describe('lifecycle', () => {
  it('restarts ice rather than giving up when a connection fails', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    const pc = created[0]!
    pc.connectionState = 'failed'
    pc.onconnectionstatechange()

    expect(handlers.peerStateChanged).toHaveBeenCalledWith(5, 'failed')
    // A network that moved under us (wifi → cellular) fails without either side going anywhere.
    expect(pc.restartIce).toHaveBeenCalled()
  })

  it('closes everything and stops listening', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    await transport.close()

    expect(created[0]!.close).toHaveBeenCalled()
    expect(handlers.peerLeft).toHaveBeenCalledWith(5)

    // Unsubscribed: a whisper arriving after teardown must not resurrect anything.
    bus.deliver({ to: 10, from: 5, description: { type: 'offer', sdp: 'v=0' } })
    await new Promise(r => setTimeout(r, 0))
    expect(created).toHaveLength(1)
  })
})

describe('remote control', () => {
  it('sends over the data channel, and drops rather than queueing when it backs up', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    const channel = created[0]!.channels[0]!

    transport.sendControl(5, { t: 'move' })
    expect(channel.send).toHaveBeenCalledWith('{"t":"move"}')

    // Replaying a backed-up queue would drag the sharer's cursor through where the controller
    // used to be. The next move is along in ~16ms.
    channel.bufferedAmount = 128 * 1024
    transport.sendControl(5, { t: 'move2' })
    expect(channel.send).toHaveBeenCalledTimes(1)
  })

  it('delivers an incoming frame, and survives a corrupt one', async () => {
    const transport = createMeshTransport({ signaling: bus.bus })
    await transport.connect(context(10), handlers)
    transport.setSubscribed(5, true)

    const channel = created[0]!.channels[0]!

    channel.onmessage({ data: '{"t":"click"}' })
    expect(handlers.controlReceived).toHaveBeenCalledWith(5, { t: 'click' })

    expect(() => channel.onmessage({ data: 'not json' })).not.toThrow()
  })
})
