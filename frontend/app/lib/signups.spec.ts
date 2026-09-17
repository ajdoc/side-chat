import { describe, expect, it } from 'vitest'
import { describeRule, joinSlotText, nextMonthKey, splitSlotText } from './signups'

describe('slot text', () => {
  it('splits a trailing reason off the name', () => {
    expect(splitSlotText('Dap - meeting')).toEqual({ name: 'Dap', note: 'meeting' })
    expect(splitSlotText('Oey rest - hmf - watch')).toEqual({ name: 'Oey rest', note: 'hmf - watch' })
  })

  it('leaves hyphenated names alone', () => {
    expect(splitSlotText('Mary-Ann')).toEqual({ name: 'Mary-Ann', note: null })
  })

  it('round-trips', () => {
    expect(joinSlotText('Dap', 'meeting')).toBe('Dap - meeting')
    expect(joinSlotText('Dap', null)).toBe('Dap')
  })
})

describe('schedules', () => {
  it('describes a rule', () => {
    expect(describeRule([4, 2], null)).toBe('Every Tue & Thu')
    expect(describeRule([6], [4, 2])).toBe('2nd & 4th Sat of the month')
    expect(describeRule([6], [-1])).toBe('Last Sat of the month')
  })

  it('rolls a month over the year end', () => {
    expect(nextMonthKey('2026-09')).toBe('2026-10')
    expect(nextMonthKey('2026-12')).toBe('2027-01')
  })
})
