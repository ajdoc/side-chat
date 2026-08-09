import { describe, expect, it } from 'vitest'
import { admits, DEFAULT_LEVELS, isMuted, resolveLevel } from './notifyPolicy'

/**
 * These cases are deliberately the same ones the backend asserts in
 * tests/Feature/NotificationSettingTest.php. Two implementations of one rule only stay in
 * step if they're held to the same examples.
 */

const HOUR = 60 * 60 * 1000
const NOW = Date.UTC(2026, 7, 9, 12, 0, 0)

const channel = (over: Record<string, unknown> = {}) => ({ server_id: 1, parent_id: null, ...over })
const chat = (over: Record<string, unknown> = {}) => ({ server_id: null, parent_id: null, ...over })

describe('resolveLevel', () => {
  it('falls back to the account default when nothing is set', () => {
    expect(resolveLevel(channel(), null, DEFAULT_LEVELS, NOW)).toBe('mentions')
  })

  it('uses the DM default in a chat rather than the channel one', () => {
    // The two differ on purpose: a DM was addressed to you, a channel of two hundred
    // people was not.
    expect(resolveLevel(chat(), null, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('prefers an explicit level over the default', () => {
    expect(resolveLevel(channel({ notify_level: 'all' }), null, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('keeps following the default while the level is null', () => {
    // Null is "no opinion", not "all" — so a changed default has to move it.
    const loud = { channel: 'all' as const, dm: 'all' as const }

    expect(resolveLevel(channel({ notify_level: null }), null, loud, NOW)).toBe('all')
  })

  it('pins a place that was set explicitly, whatever the default becomes', () => {
    const loud = { channel: 'all' as const, dm: 'all' as const }

    expect(resolveLevel(channel({ notify_level: 'mentions' }), null, loud, NOW)).toBe('mentions')
  })

  it('silences a live mute whatever the level says', () => {
    const target = channel({ notify_level: 'all', muted_until: new Date(NOW + HOUR).toISOString() })

    expect(resolveLevel(target, null, DEFAULT_LEVELS, NOW)).toBe('none')
  })

  it('restores the level underneath once the mute lapses', () => {
    // The mute suspends the preference rather than destroying it — which is the whole
    // reason mute and level are separate fields.
    const target = channel({ notify_level: 'all', muted_until: new Date(NOW - HOUR).toISOString() })

    expect(resolveLevel(target, null, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('inherits a muted parent into a discussion that says nothing', () => {
    const parent = channel({ muted_until: new Date(NOW + HOUR).toISOString() })
    const discussion = channel({ parent_id: 7 })

    expect(resolveLevel(discussion, parent, DEFAULT_LEVELS, NOW)).toBe('none')
  })

  it('lets a discussion override the channel it lives in', () => {
    const parent = channel({ notify_level: 'none' })
    const discussion = channel({ parent_id: 7, notify_level: 'all' })

    expect(resolveLevel(discussion, parent, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('lets a discussion outrank even a muted parent when set explicitly', () => {
    // The child's own row is checked in full before the parent is consulted at all.
    const parent = channel({ muted_until: new Date(NOW + HOUR).toISOString() })
    const discussion = channel({ parent_id: 7, notify_level: 'all' })

    expect(resolveLevel(discussion, parent, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('silences one discussion while the channel around it stays loud', () => {
    // The case worth naming: muting a busy sub-conversation without going quiet on the
    // channel it lives in. The discussion's own row is checked first and in full, so a
    // mute set here never reaches the parent's louder answer.
    const parent = channel({ notify_level: 'all' })
    const discussion = channel({ parent_id: 7, muted_until: new Date(NOW + HOUR).toISOString() })

    expect(resolveLevel(discussion, parent, DEFAULT_LEVELS, NOW)).toBe('none')
    expect(resolveLevel(parent, null, DEFAULT_LEVELS, NOW)).toBe('all')
  })

  it('treats a missing target as silent rather than as a default', () => {
    expect(resolveLevel(null, null, DEFAULT_LEVELS, NOW)).toBe('none')
  })
})

describe('isMuted', () => {
  it('is false with no mute set, and while one has already lapsed', () => {
    expect(isMuted(channel(), NOW)).toBe(false)
    expect(isMuted(channel({ muted_until: new Date(NOW - 1).toISOString() }), NOW)).toBe(false)
  })

  it('is true while the mute is still running', () => {
    expect(isMuted(channel({ muted_until: new Date(NOW + 1).toISOString() }), NOW)).toBe(true)
  })
})

describe('admits', () => {
  it('lets everything through at all, and nothing through at none', () => {
    expect(admits('all', false)).toBe(true)
    expect(admits('none', true)).toBe(false)
  })

  it('lets only a mention through at mentions', () => {
    expect(admits('mentions', true)).toBe(true)
    expect(admits('mentions', false)).toBe(false)
  })
})
