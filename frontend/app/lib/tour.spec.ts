import { describe, expect, it } from 'vitest'
import { APP_SHELL_TOUR, SIDE_SPACE_TOUR, TOURS, VOICE_TOUR, inflate, placePopover, unionRect, visibleSteps, type Rect } from './tour'

const POPOVER = { width: 320, height: 176 }
const VIEWPORT = { width: 1280, height: 800 }

describe('the tour registry', () => {
  it('lists every tour under a unique id', () => {
    const ids = TOURS.map(t => t.id)
    expect(new Set(ids).size).toBe(ids.length)
    expect(ids).toContain(APP_SHELL_TOUR.id)
    expect(ids).toContain(VOICE_TOUR.id)
    expect(ids).toContain(SIDE_SPACE_TOUR.id)
  })

  it('gives every step a target, a title and a body', () => {
    for (const tour of TOURS) {
      expect(tour.steps.length).toBeGreaterThan(0)
      for (const step of tour.steps) {
        expect(step.target).toBeTruthy()
        expect(step.title).toBeTruthy()
        expect(step.body).toBeTruthy()
      }
    }
  })

  it('never reuses a target between tours — a hook belongs to one lesson', () => {
    const targets = TOURS.flatMap(t => t.steps.map(s => s.target))
    expect(new Set(targets).size).toBe(targets.length)
  })
})

describe('visibleSteps', () => {
  it('drops steps whose target is not on the screen', () => {
    const present = new Set(['sidebar', 'account'])
    const steps = visibleSteps(APP_SHELL_TOUR.steps, t => (present.has(t) ? {} : null))
    expect(steps.map(s => s.target)).toEqual(['sidebar', 'account'])
  })

  it('keeps the authored order', () => {
    const steps = visibleSteps(APP_SHELL_TOUR.steps, () => ({}))
    expect(steps).toHaveLength(APP_SHELL_TOUR.steps.length)
    expect(steps[0]!.target).toBe('sidebar')
  })

  it('leaves the voice tour with only its in-call steps once you have joined', () => {
    // voice-join and voice-roster live behind `v-if="!here"` — they are gone the moment the
    // tour is auto-offered, which is the whole reason steps resolve at start time.
    const inCall = new Set(['voice-stage', 'voice-controls', 'voice-bar'])
    const steps = visibleSteps(VOICE_TOUR.steps, t => (inCall.has(t) ? {} : null))
    expect(steps.map(s => s.target)).toEqual(['voice-stage', 'voice-controls', 'voice-bar'])
  })

  it('runs the Side Space tour on a small map with no minimap', () => {
    const steps = visibleSteps(SIDE_SPACE_TOUR.steps, t => (t === 'space-minimap' ? null : {}))
    expect(steps.map(s => s.target)).not.toContain('space-minimap')
    expect(steps.length).toBe(SIDE_SPACE_TOUR.steps.length - 1)
  })

  it('returns nothing when the subject is not on screen at all', () => {
    expect(visibleSteps(VOICE_TOUR.steps, () => null)).toEqual([])
  })
})

describe('inflate', () => {
  it('grows on every side', () => {
    expect(inflate({ x: 20, y: 30, width: 100, height: 40 }, 6))
      .toEqual({ x: 14, y: 24, width: 112, height: 52 })
  })

  it('never pushes the origin off the top-left corner', () => {
    const r = inflate({ x: 2, y: 0, width: 50, height: 50 }, 6)
    expect(r.x).toBe(0)
    expect(r.y).toBe(0)
  })
})

describe('placePopover', () => {
  const target: Rect = { x: 500, y: 380, width: 120, height: 40 }

  it('honours the preferred side when it fits', () => {
    const p = placePopover(target, POPOVER, VIEWPORT, 'right')
    expect(p.side).toBe('right')
    expect(p.x).toBe(target.x + target.width + 12)
  })

  it('centres a top/bottom bubble on the target', () => {
    const p = placePopover(target, POPOVER, VIEWPORT, 'bottom')
    expect(p.x).toBe(target.x + target.width / 2 - POPOVER.width / 2)
    expect(p.y).toBe(target.y + target.height + 12)
  })

  it('flips to the opposite side when the preferred one has no room', () => {
    // The sidebar: hard against the left edge, so "left" cannot fit.
    const sidebar: Rect = { x: 0, y: 0, width: 280, height: 800 }
    expect(placePopover(sidebar, POPOVER, VIEWPORT, 'left').side).toBe('right')
  })

  it('tries the remaining sides when neither the preference nor its opposite fits', () => {
    // Full-height target: top and bottom are both impossible, left has no room.
    const tall: Rect = { x: 0, y: 0, width: 200, height: 800 }
    expect(placePopover(tall, POPOVER, VIEWPORT, 'top').side).toBe('right')
  })

  it('keeps the bubble on screen when nothing fits', () => {
    const huge: Rect = { x: 0, y: 0, width: VIEWPORT.width, height: VIEWPORT.height }
    const p = placePopover(huge, POPOVER, VIEWPORT, 'bottom')
    expect(p.x).toBeGreaterThanOrEqual(0)
    expect(p.y).toBeGreaterThanOrEqual(0)
    expect(p.x + POPOVER.width).toBeLessThanOrEqual(VIEWPORT.width)
    expect(p.y + POPOVER.height).toBeLessThanOrEqual(VIEWPORT.height)
  })

  it('clamps a bubble that would hang off the right edge', () => {
    const edge: Rect = { x: 1200, y: 400, width: 60, height: 30 }
    const p = placePopover(edge, POPOVER, VIEWPORT, 'bottom')
    expect(p.x + POPOVER.width).toBeLessThanOrEqual(VIEWPORT.width)
  })
})

describe('unionRect', () => {
  it('wraps a run of sibling rows', () => {
    // Three channel rows stacked in the sidebar: same left edge, 34px apart.
    const rows: Rect[] = [
      { x: 8, y: 100, width: 240, height: 30 },
      { x: 8, y: 134, width: 240, height: 30 },
      { x: 8, y: 168, width: 240, height: 30 },
    ]
    expect(unionRect(rows)).toEqual({ x: 8, y: 100, width: 240, height: 98 })
  })

  it('takes the widest and the tallest extent, not the first', () => {
    const rects: Rect[] = [
      { x: 20, y: 10, width: 50, height: 10 },
      { x: 5, y: 40, width: 100, height: 10 },
    ]
    expect(unionRect(rects)).toEqual({ x: 5, y: 10, width: 100, height: 40 })
  })

  it('is a single rect when there is only one', () => {
    const only: Rect = { x: 1, y: 2, width: 3, height: 4 }
    expect(unionRect([only])).toEqual(only)
  })

  it('has nothing to wrap when the set is empty', () => {
    expect(unionRect([])).toBeNull()
  })
})

describe('the channel-types step', () => {
  const step = APP_SHELL_TOUR.steps.find(s => s.target === 'channel-row')!

  it('spotlights the whole run of rows, since the lesson is how they differ', () => {
    expect(step).toBeDefined()
    expect(step.spotlight).toBe('all')
  })

  it('names all four kinds of channel', () => {
    for (const kind of ['timeline', 'call', 'Side Space', 'app channel']) {
      expect(step.body).toContain(kind)
    }
  })
})

describe('a step long enough to fill the screen', () => {
  // The bubble is measured rather than assumed, so placement gets handed real heights —
  // including ones taller than the space on the chosen side. It must still land on screen.
  const target: Rect = { x: 8, y: 100, width: 240, height: 420 }
  const viewport = { width: 1280, height: 640 }

  it('keeps a tall bubble fully on screen', () => {
    const tall = { width: 320, height: 600 }
    const p = placePopover(target, tall, viewport, 'right')
    expect(p.y).toBeGreaterThanOrEqual(0)
    expect(p.y + tall.height).toBeLessThanOrEqual(viewport.height)
  })

  it('pins a bubble taller than the window to the top rather than centring it off-screen', () => {
    // Capped by the overlay before it gets here, but if anything ever slips through, the
    // Back/Next row must not end up above the fold.
    const oversized = { width: 320, height: 900 }
    const p = placePopover(target, oversized, viewport, 'right')
    expect(p.y).toBeGreaterThanOrEqual(0)
    expect(p.y).toBeLessThanOrEqual(12)
  })
})
