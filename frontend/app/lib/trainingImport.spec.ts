import { describe, expect, it } from 'vitest'
import { describeImportErrors, extractProgram, programFileName, TrainingImportError } from './trainingImport'

describe('extracting a program', () => {
  it('reads the data block out of an HTML page, ignoring other scripts', () => {
    const html = `<html><script>var x = {"weeks": 99}</script>
      <script type="application/json" id="training-program">{"weeks": 13}</script></html>`
    expect(extractProgram(html, 'arc.html')).toEqual({ weeks: 13 })
  })

  it('accepts attributes in any order and unquoted ids', () => {
    expect(extractProgram('<script id=training-program type="application/json">{"weeks":2}</script>')).toEqual({ weeks: 2 })
  })

  it('does not match a longer id', () => {
    expect(() => extractProgram('<script id="training-program-old">{}</script>', 'a.html')).toThrow(TrainingImportError)
  })

  it('reads a plain JSON file', () => {
    expect(extractProgram('  {"weeks": 4}', 'p.json')).toEqual({ weeks: 4 })
  })

  it('explains what is wrong', () => {
    expect(() => extractProgram('<html></html>', 'a.html')).toThrow(/no program data/)
    expect(() => extractProgram('{nope', 'a.json')).toThrow(/valid JSON/)
    expect(() => extractProgram('[1]', 'a.json')).toThrow(/single JSON object/)
  })
})

describe('server errors', () => {
  it('turns paths into readable places', () => {
    expect(describeImportErrors({ 'content.sessions.run.blocks.0.name': ['Required.'] }))
      .toEqual(['sessions › run › blocks › #1 › name: Required.'])
  })
})

it('names a download after the program', () => {
  expect(programFileName('Ultimate winter arc!')).toBe('ultimate-winter-arc.json')
})
