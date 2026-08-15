import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { TransportEvents } from './VoiceTransport'

/**
 * The adapter's job is translation, so these tests are about translation: user ids in and out
 * of LiveKit identities, the app's four track slots to and from Track.Source, and the handful
 * of places where getting the LiveKit call *shape* wrong would break something real (stopping
 * a track the caller still owns, a stalled connect that never falls back).
 *
 * LiveKit itself is faked. Standing up a real Room would be testing their SDK, which they
 * already do; what can break here is our end of the contract with it.
 */

/** The room the transport builds, captured so a test can drive it. */
let room: FakeRoom

class FakeRoom {
  handlers = new Map<string, (...args: any[]) => void>()
  remoteParticipants = new Map<string, any>()
  connect = vi.fn(async () => {})
  disconnect = vi.fn(async () => {})
  removeAllListeners = vi.fn()
  localParticipant = {
    publishTrack: vi.fn(async () => ({})),
    unpublishTrack: vi.fn(async () => ({})),
    publishData: vi.fn(async () => {}),
  }

  constructor(public options: any) {
    room = this
  }

  on(event: string, handler: (...args: any[]) => void) {
    this.handlers.set(event, handler)

    return this
  }

  /** Fire an event as the SDK would. */
  emit(event: string, ...args: any[]) {
    this.handlers.get(event)?.(...args)
  }
}

vi.mock('livekit-client', () => ({
  Room: FakeRoom,
  RoomEvent: {
    ParticipantConnected: 'participantConnected',
    ParticipantDisconnected: 'participantDisconnected',
    TrackSubscribed: 'trackSubscribed',
    TrackUnsubscribed: 'trackUnsubscribed',
    ConnectionStateChanged: 'connectionStateChanged',
    Disconnected: 'disconnected',
    DataReceived: 'dataReceived',
  },
  ConnectionState: {
    Disconnected: 'disconnected',
    Connecting: 'connecting',
    Connected: 'connected',
    Reconnecting: 'reconnecting',
    SignalReconnecting: 'signalReconnecting',
  },
  DisconnectReason: { 1: 'CLIENT_INITIATED', 2: 'DUPLICATE_IDENTITY' },
  Track: {
    Source: {
      Camera: 'camera',
      Microphone: 'microphone',
      ScreenShare: 'screen_share',
      ScreenShareAudio: 'screen_share_audio',
      Unknown: 'unknown',
    },
  },
}))

const { createLiveKitTransport } = await import('./LiveKitTransport')

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

function context(overrides: Partial<any> = {}) {
  return {
    channelId: 7,
    selfId: 1,
    iceServers: [],
    sfu: { driver: 'livekit', provider: 'livekit', url: 'wss://x', room: 'channel-7', token: 'tok' },
    proximity: false,
    ...overrides,
  }
}

/** A remote participant as the SDK models one. */
function participant(identity: string, publications: any[] = []) {
  return {
    identity,
    trackPublications: new Map(publications.map((p, i) => [String(i), p])),
  }
}

function publication(source: string, track: any = { mediaStreamTrack: { id: 'm' } }) {
  return { source, track, setSubscribed: vi.fn() }
}

let transport: ReturnType<typeof createLiveKitTransport>
let handlers: TransportEvents

beforeEach(() => {
  vi.clearAllMocks()
  transport = createLiveKitTransport()
  handlers = events()
})

describe('connect', () => {
  it('subscribes to everyone up front, except in a Side Space', async () => {
    await transport.connect(context(), handlers)
    expect(room.connect).toHaveBeenCalledWith('wss://x', 'tok', { autoSubscribe: true })

    // Proximity means distance decides who you hear, so nobody is subscribed until they walk
    // into range — the whole reason this is better on an SFU than in a mesh.
    const other = createLiveKitTransport()
    await other.connect(context({ proximity: true }), events())
    expect(room.connect).toHaveBeenCalledWith('wss://x', 'tok', { autoSubscribe: false })
  })

  it('reports people who were already in the room, and their tracks', async () => {
    room = undefined as any
    transport = createLiveKitTransport()
    room.remoteParticipants.set('a', participant('42', [publication('screen_share')]))

    await transport.connect(context(), handlers)

    // ParticipantConnected only fires for people who arrive after us; without this sweep a
    // late joiner sees an empty call.
    expect(handlers.peerJoined).toHaveBeenCalledWith(42)
    expect(handlers.trackReceived).toHaveBeenCalledWith(42, 'screen', { id: 'm' })
  })

  it('gives up rather than letting a stalled connect block the fallback', async () => {
    vi.useFakeTimers()
    transport = createLiveKitTransport()
    room.connect = vi.fn(() => new Promise(() => {})) // never settles

    const attempt = transport.connect(context(), handlers)
    const asserted = expect(attempt).rejects.toThrow('timed out')

    await vi.advanceTimersByTimeAsync(11_000)
    await asserted

    // And it left nothing running for the mesh to trip over.
    expect(room.disconnect).toHaveBeenCalled()
    vi.useRealTimers()
  })

  it('refuses to connect without credentials', async () => {
    await expect(transport.connect(context({ sfu: null }), handlers)).rejects.toThrow('no sfu')
  })
})

describe('identity mapping', () => {
  it('maps a participant identity back to a user id', async () => {
    await transport.connect(context(), handlers)

    room.emit('participantConnected', participant('99'))
    expect(handlers.peerJoined).toHaveBeenCalledWith(99)

    room.emit('participantDisconnected', participant('99'))
    expect(handlers.peerLeft).toHaveBeenCalledWith(99)
  })

  it('ignores participants that are not one of our users', async () => {
    await transport.connect(context(), handlers)

    // A recorder, an agent, anything joining the room with a name rather than an id.
    room.emit('participantConnected', participant('egress-bot'))
    room.emit('participantConnected', participant('0'))

    expect(handlers.peerJoined).not.toHaveBeenCalled()
  })
})

describe('track slots', () => {
  it.each([
    ['microphone', 'mic'],
    ['camera', 'camera'],
    ['screen_share', 'screen'],
    ['screen_share_audio', 'screenAudio'],
  ])('routes %s into the %s slot', async (source, kind) => {
    await transport.connect(context(), handlers)

    const track = { mediaStreamTrack: { id: source } }
    room.emit('trackSubscribed', track, publication(source, track), participant('5'))

    // Keeping these apart is what stops a screen mute from silencing somebody's voice.
    expect(handlers.trackReceived).toHaveBeenCalledWith(5, kind, { id: source })
  })

  it('ignores a source it has no slot for', async () => {
    await transport.connect(context(), handlers)

    const track = { mediaStreamTrack: { id: 'x' } }
    room.emit('trackSubscribed', track, publication('unknown', track), participant('5'))

    expect(handlers.trackReceived).not.toHaveBeenCalled()
  })

  it('reports a track ending', async () => {
    await transport.connect(context(), handlers)

    const track = { mediaStreamTrack: { id: 'x' } }
    room.emit('trackUnsubscribed', track, publication('camera', track), participant('5'))

    expect(handlers.trackEnded).toHaveBeenCalledWith(5, 'camera')
  })
})

describe('publish', () => {
  it('publishes into the right source, with simulcast for a screen', async () => {
    await transport.connect(context(), handlers)

    const track = { id: 'screen' } as any
    await transport.publish('screen', track)

    const [published, options] = room.localParticipant.publishTrack.mock.calls[0]! as unknown as [any, any]

    expect(published).toBe(track)
    expect(options.source).toBe('screen_share')
    // Simulcast is correct here and was wrong in the mesh: the SFU picks a layer per receiver
    // instead of the sender uploading every layer to everybody.
    expect(options.simulcast).toBe(true)
  })

  it('retracts a track without stopping it, because the caller still owns it', async () => {
    await transport.connect(context(), handlers)

    const track = { id: 'cam' } as any
    await transport.publish('camera', track)
    await transport.publish('camera', null)

    // `false` is the load-bearing argument: stopping it here would kill the local preview.
    expect(room.localParticipant.unpublishTrack).toHaveBeenCalledWith(track, false)
  })

  it('replaces the track in a slot rather than stacking two', async () => {
    await transport.connect(context(), handlers)

    const first = { id: 'a' } as any
    const second = { id: 'b' } as any

    await transport.publish('mic', first)
    await transport.publish('mic', second)

    expect(room.localParticipant.unpublishTrack).toHaveBeenCalledWith(first, false)
    expect(room.localParticipant.publishTrack).toHaveBeenCalledTimes(2)
  })
})

describe('proximity', () => {
  it('subscribes and unsubscribes every track a person is publishing', async () => {
    const screen = publication('screen_share')
    const mic = publication('microphone')

    room = undefined as any
    transport = createLiveKitTransport()
    room.remoteParticipants.set('a', participant('42', [screen, mic]))

    await transport.connect(context({ proximity: true }), handlers)

    transport.setSubscribed(42, true)
    expect(screen.setSubscribed).toHaveBeenCalledWith(true)
    expect(mic.setSubscribed).toHaveBeenCalledWith(true)

    transport.setSubscribed(42, false)
    expect(mic.setSubscribed).toHaveBeenLastCalledWith(false)
  })

  it('shrugs at somebody who has already left', async () => {
    await transport.connect(context(), handlers)

    expect(() => transport.setSubscribed(999, true)).not.toThrow()
  })
})

describe('remote control', () => {
  it('sends lossy, to one person', async () => {
    await transport.connect(context(), handlers)

    transport.sendControl(42, { t: 'move', x: 1 })

    const [payload, options] = room.localParticipant.publishData.mock.calls[0]! as unknown as [any, any]

    expect(JSON.parse(new TextDecoder().decode(payload))).toEqual({ t: 'move', x: 1 })
    // Pointer movement at ~60/s: a late frame is worse than a dropped one.
    expect(options).toMatchObject({ reliable: false, destinationIdentities: ['42'] })
  })

  it('decodes an incoming frame, and survives a corrupt one', async () => {
    await transport.connect(context(), handlers)

    room.emit('dataReceived', new TextEncoder().encode('{"t":"click"}'), participant('7'))
    expect(handlers.controlReceived).toHaveBeenCalledWith(7, { t: 'click' })

    expect(() => room.emit('dataReceived', new TextEncoder().encode('not json'), participant('7')))
      .not.toThrow()
  })
})

describe('failure', () => {
  it('raises a disconnect we did not ask for, so the caller can fall back', async () => {
    await transport.connect(context(), handlers)

    room.emit('disconnected', 2)
    expect(handlers.failed).toHaveBeenCalledWith('DUPLICATE_IDENTITY')
  })

  it('stays quiet about a disconnect we asked for', async () => {
    await transport.connect(context(), handlers)
    await transport.close()

    room.emit('disconnected', 1)
    expect(handlers.failed).not.toHaveBeenCalled()
  })

  it('reports connection trouble against every peer', async () => {
    room = undefined as any
    transport = createLiveKitTransport()
    room.remoteParticipants.set('a', participant('42'))
    room.remoteParticipants.set('b', participant('43'))

    await transport.connect(context(), handlers)
    room.emit('connectionStateChanged', 'reconnecting')

    // Losing the one connection to the server is losing everybody.
    expect(handlers.peerStateChanged).toHaveBeenCalledWith(42, 'reconnecting')
    expect(handlers.peerStateChanged).toHaveBeenCalledWith(43, 'reconnecting')
  })
})
