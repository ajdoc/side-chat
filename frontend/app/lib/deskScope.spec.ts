import { describe, expect, it } from 'vitest'
import { CHANNEL_SCOPED_APPS, isChannelPath, scopeFor } from './deskScope'

/**
 * The surface-scoping rule, on its own.
 *
 * Worth a test rather than a regex inline in a component: getting it wrong doesn't throw — the
 * tab renders, fetches a 404 and sits empty, which is exactly the failure that shipped before
 * this rule was written down.
 */
describe('isChannelPath', () => {
  it('accepts a channel surface and rejects a side chat', () => {
    expect(isChannelPath('/api/channels/12')).toBe(true)
    expect(isChannelPath('/api/side-chats/3')).toBe(false)
  })

  it('rejects a path with anything after the channel id', () => {
    // The app endpoints are built as `${basePath}/apps/...`, so a base path that already has a
    // segment on the end would compose into a URL that exists nowhere.
    expect(isChannelPath('/api/channels/12/whiteboard')).toBe(false)
  })
})

describe('scopeFor', () => {
  it('sends the channel-scoped apps to the parent channel on a side chat', () => {
    for (const app of CHANNEL_SCOPED_APPS) {
      expect(scopeFor(app, '/api/side-chats/3', 'sidechat.3', 12)).toEqual({
        basePath: '/api/channels/12',
        streamName: 'channel.12',
      })
    }
  })

  it('leaves the per-surface apps on the surface they are on', () => {
    // A side chat's whiteboard is its own whiteboard — the whole point of per-surface storage.
    expect(scopeFor('board', '/api/side-chats/3', 'sidechat.3', 12)).toEqual({
      basePath: '/api/side-chats/3',
      streamName: 'sidechat.3',
    })
    expect(scopeFor('notes', '/api/side-chats/3', 'sidechat.3', 12).basePath).toBe('/api/side-chats/3')
  })

  it('is a no-op on a channel desk, whichever app it is', () => {
    for (const app of [...CHANNEL_SCOPED_APPS, 'board', 'notes'] as const) {
      expect(scopeFor(app, '/api/channels/12', 'channel.12', 12)).toEqual({
        basePath: '/api/channels/12',
        streamName: 'channel.12',
      })
    }
  })
})
