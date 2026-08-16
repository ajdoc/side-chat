import { describe, expect, it, vi } from 'vitest'
import { watchAlongPosition } from './watchAlong'

/**
 * The arithmetic that keeps a room together.
 *
 * Worth its own test because the failure is silent and gradual: nothing throws, nobody sees an
 * error, and the room simply stops being at the same moment of the film. Two players share this
 * now — the widget and the screens on a Side Space map — and they must agree exactly.
 */
describe('where a watch-along should be', () => {
  const at = (over: Partial<Parameters<typeof watchAlongPosition>[0] & object> = {}) => ({
    status: 'playing' as const,
    position: 100,
    updated_at: new Date(Date.now() - 10_000).toISOString(),
    speed: 1,
    ...over,
  })

  it('carries a playing room forward by the time since the snapshot', () => {
    // Ten seconds since the server said 100.
    expect(watchAlongPosition(at())).toBeCloseTo(110, 0)
  })

  it('scales the elapsed time by the shared speed', () => {
    // The speed is shared precisely so the room stays together at double rate too.
    expect(watchAlongPosition(at({ speed: 2 }))).toBeCloseTo(120, 0)
    expect(watchAlongPosition(at({ speed: 0.5 }))).toBeCloseTo(105, 0)
  })

  it('leaves a paused room exactly where it was', () => {
    // Nothing has elapsed that anybody should catch up on, however long ago it was paused.
    const paused = at({ status: 'paused', updated_at: new Date(Date.now() - 3_600_000).toISOString() })

    expect(watchAlongPosition(paused)).toBe(100)
    expect(watchAlongPosition(at({ status: 'idle' }))).toBe(100)
  })

  it('never asks a player to seek before the start', () => {
    /*
     * A clock behind the server's makes `elapsed` negative. Seeking to -5 is not an error any
     * browser reports — they each do something arbitrary — so it is clamped here instead.
     */
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-01-01T00:00:00Z') - 60_000)

    expect(watchAlongPosition({
      status: 'playing',
      position: 5,
      updated_at: '2026-01-01T00:00:00Z',
      speed: 1,
    })).toBe(0)

    vi.restoreAllMocks()
  })

  it('reads no state at all as the start', () => {
    // A room with no player yet — the screens ask before there is anything to ask about.
    expect(watchAlongPosition(null)).toBe(0)
  })
})
