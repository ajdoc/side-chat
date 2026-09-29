import { describe, expect, it } from 'vitest'
import type { TrainingContent } from '~/types'
import { videoHref, warmupItem, currentDay, currentWeek, dateFor, dayProgress, phaseIndex, sessionIdFor, tickKey, weekSessionsDone } from './training'

const session = (n: number) => ({ title: 't', time: '', hard: false, intent: '', warmup: null, blocks: Array.from({ length: n }, (_, i) => ({ id: String(i), name: 'b', dose: '', cue: '' })) })

const content: TrainingContent = {
  weeks: 13,
  days: [
    { key: 'mon', label: 'Mon', tag: '', offset: 0 },
    { key: 'tue', label: 'Tue', tag: '', offset: 1 },
    { key: 'sat', label: 'Sat', tag: '', offset: 5 },
  ],
  phases: [
    { name: 'A', from: 1, to: 4, line: '' },
    { name: 'B', from: 5, to: 9, line: '' },
    { name: 'C', from: 10, to: 13, line: '' },
  ],
  notes: {},
  warmups: {},
  plan: { mon: 'light', tue: ['t1', 't2', 't3'], sat: ['s1', 's2', 's3'] },
  overrides: [{ weeks: [1, 13], day: 'sat', session: 'test' }],
  sessions: { light: session(2), t1: session(3), t2: session(3), t3: session(3), s1: session(1), s2: session(1), s3: session(1), test: session(2) },
  reference: [],
}

describe('resolving sessions', () => {
  it('uses one session for every week, or one per phase', () => {
    expect(sessionIdFor(content, 7, 'mon')).toBe('light')
    expect(phaseIndex(content, 4)).toBe(0)
    expect(phaseIndex(content, 5)).toBe(1)
    expect(sessionIdFor(content, 4, 'tue')).toBe('t1')
    expect(sessionIdFor(content, 10, 'tue')).toBe('t3')
  })

  it('lets an override win for its weeks only', () => {
    expect(sessionIdFor(content, 1, 'sat')).toBe('test')
    expect(sessionIdFor(content, 13, 'sat')).toBe('test')
    expect(sessionIdFor(content, 12, 'sat')).toBe('s3')
  })
})

describe('progress', () => {
  it('counts a day done only when every block is ticked', () => {
    const ticks = new Set([tickKey(2, 'mon', '0'), tickKey(2, 'mon', '1'), tickKey(2, 'tue', '0')])
    expect(dayProgress(content, ticks, 2, 'tue')).toEqual({ done: 1, total: 3 })
    expect(weekSessionsDone(content, ticks, 2)).toBe(1)
  })
})

describe('dates', () => {
  it('finds the week and day for today', () => {
    expect(currentWeek('2026-09-28', 13, new Date(2026, 8, 29))).toBe(1)
    expect(currentWeek('2026-09-28', 13, new Date(2026, 9, 5))).toBe(2)
    expect(currentWeek('2026-09-28', 13, new Date(2026, 7, 1))).toBe(1)
    expect(currentWeek('2026-09-28', 13, new Date(2027, 0, 30))).toBe(13)
    expect(currentDay(content, new Date(2026, 9, 1))).toBe('tue') // Thursday → last day on or before
    expect(currentDay(content, new Date(2026, 9, 4))).toBe('sat') // Sunday
  })

  it('dates a day from the start Monday', () => {
    expect(dateFor('2026-09-28', 2, 5).toDateString()).toBe(new Date(2026, 9, 10).toDateString())
  })
})

describe('video links', () => {
  it('searches YouTube, or uses a safe direct url', () => {
    expect(videoHref({ label: 'x', search: 'a skip drill' })).toBe('https://www.youtube.com/results?search_query=a%20skip%20drill')
    expect(videoHref({ label: 'x', url: 'https://youtu.be/abc' })).toBe('https://youtu.be/abc')
    expect(videoHref({ label: 'x', url: 'javascript:alert(1)' })).toBeNull()
  })

  it('reads warm-up steps written either way', () => {
    expect(warmupItem('Laps')).toEqual({ text: 'Laps', videos: [] })
    expect(warmupItem({ text: 'Skips', videos: [{ label: 'A', search: 'a' }] }).videos).toHaveLength(1)
  })
})

it('writes week lists as ranges', async () => {
  const { weekRanges } = await import('./training')
  expect(weekRanges([10, 1, 2, 3, 5, 9])).toBe('1–3, 5, 9–10')
})
