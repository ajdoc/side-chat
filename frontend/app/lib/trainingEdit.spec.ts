import { describe, expect, it } from 'vitest'
import type { TrainingContent } from '~/types'
import { sessionIdFor } from './training'
import {
  addDay, composeMaps, deleteWeek, editable, forkForWeek, identityMap, insertWeek, removeDay, removePhase,
  setPhaseEnd, setSessionForPhase, setSessionForWeek, splitPhase, unusedSessions,
} from './trainingEdit'

const s = (title: string) => ({ title, time: '', hard: false, intent: '', warmup: null, blocks: [{ id: 'a', name: 'x', dose: '', cue: '' }] })

function program(): TrainingContent {
  return editable({
    weeks: 6,
    days: [{ key: 'mon', label: 'Mon', tag: '', offset: 0 }, { key: 'sat', label: 'Sat', tag: '', offset: 5 }],
    phases: [{ name: 'A', from: 1, to: 3, line: '' }, { name: 'B', from: 4, to: 6, line: '' }],
    notes: { 2: 'two', 5: 'five' },
    warmups: {},
    plan: { mon: 'light', sat: ['c1', 'c2'] },
    overrides: [{ weeks: [1, 6], day: 'sat', session: 'test' }],
    sessions: { light: s('L'), c1: s('C1'), c2: s('C2'), test: s('T') },
    reference: [],
  })
}

describe('weeks', () => {
  it('deletes a week, shifting phases, notes and swaps after it', () => {
    const c = program()
    const map = deleteWeek(c, 2)
    expect(map).toEqual({ 1: 1, 2: null, 3: 2, 4: 3, 5: 4, 6: 5 })
    expect(c.weeks).toBe(5)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 2], [3, 5]])
    expect(c.notes).toEqual({ 4: 'five' })
    expect(c.overrides[0]!.weeks).toEqual([1, 5])
  })

  it('removes a phase left empty, and its plan column', () => {
    const c = program()
    c.phases = [{ name: 'A', from: 1, to: 5, line: '' }, { name: 'B', from: 6, to: 6, line: '' }]
    deleteWeek(c, 6)
    expect(c.phases).toHaveLength(1)
    expect(c.plan.sat).toBe('c1')
    expect(c.overrides[0]!.weeks).toEqual([1])
  })

  it('inserts a week into the phase it follows', () => {
    const c = program()
    const map = insertWeek(c, 3)
    expect(map[4]).toBe(5)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 4], [5, 7]])
    expect(c.notes).toEqual({ 2: 'two', 6: 'five' })
    expect(sessionIdFor(c, 4, 'sat')).toBe('c1')
    expect(c.overrides[0]!.weeks).toEqual([1, 7])
  })

  it('inserts at the start', () => {
    const c = program()
    insertWeek(c, 0)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 4], [5, 7]])
    expect(sessionIdFor(c, 2, 'sat')).toBe('test')
  })

  it('composes maps across several edits', () => {
    const c = program()
    let map = identityMap(c.weeks)
    map = composeMaps(map, deleteWeek(c, 1))
    map = composeMaps(map, insertWeek(c, 0))
    expect(map).toEqual({ 1: null, 2: 2, 3: 3, 4: 4, 5: 5, 6: 6 })
  })
})

describe('sessions per week', () => {
  it('forks one week so editing it leaves the others alone', () => {
    const c = program()
    const id = forkForWeek(c, 2, 'mon')!
    expect(id).not.toBe('light')
    c.sessions[id]!.title = 'Changed'
    expect(c.sessions[sessionIdFor(c, 2, 'mon')!]!.title).toBe('Changed')
    expect(c.sessions[sessionIdFor(c, 3, 'mon')!]!.title).toBe('L')
    expect(c.sessions[id]!.blocks[0]!.id).toBe('a')
    // Already its own: forking again returns it.
    expect(forkForWeek(c, 2, 'mon')).toBe(id)
  })

  it('drops a fork once nothing uses it', () => {
    const c = program()
    const id = forkForWeek(c, 2, 'mon')!
    setSessionForWeek(c, 2, 'mon', 'light')
    expect(c.sessions[id]).toBeUndefined()
    expect(c.overrides.filter(o => o.day === 'mon')).toEqual([])
  })

  it('makes a single week a rest day', () => {
    const c = program()
    setSessionForWeek(c, 3, 'mon', null)
    expect(sessionIdFor(c, 3, 'mon')).toBeNull()
    expect(sessionIdFor(c, 4, 'mon')).toBe('light')
  })

  it('sets a whole phase, clearing that phase\'s swaps', () => {
    const c = program()
    setSessionForPhase(c, 1, 'sat', 'c1')
    expect(c.plan.sat).toBe('c1')
    expect(c.overrides[0]!.weeks).toEqual([1])
    expect(unusedSessions(c)).toEqual(['c2'])
  })
})

describe('days and phases', () => {
  it('adds and removes days', () => {
    const c = program()
    expect(addDay(c, 2)).toBe('wed')
    expect(c.days.map(d => d.key)).toEqual(['mon', 'wed', 'sat'])
    removeDay(c, 'sat')
    expect(c.plan.sat).toBeUndefined()
    expect(c.overrides).toEqual([])
  })

  it('splits, merges and moves phase boundaries', () => {
    const c = program()
    splitPhase(c, 1)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 3], [4, 5], [6, 6]])
    expect(c.plan.sat).toEqual(['c1', 'c2', 'c2'])
    removePhase(c, 2)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 3], [4, 6]])
    setPhaseEnd(c, 0, 4)
    expect(c.phases.map(p => [p.from, p.to])).toEqual([[1, 4], [5, 6]])
  })
})
