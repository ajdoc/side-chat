import { describe, expect, it } from 'vitest'
import { reactive, ref } from 'vue'
import { compactSticker, emptySticker, plainCopy, simplifyPoints, stickerLayers, stickerSize } from './stickers'

/**
 * The pure half of sticker layers — the shape conversion, which is where a silent
 * regression would cost people their drawings.
 */
describe('sticker layers', () => {
  it('reads a pre-layers sticker as one layer', () => {
    const old: any = { shape: 'square', fill: '#fff', fillOpacity: 1, stroke: '#000', paths: [{ points: [[0, 0], [1, 1]], color: '#000', width: 3 }] }
    const layers = stickerLayers(old)
    expect(layers).toHaveLength(1)
    expect(layers[0]!.paths).toHaveLength(1)
    expect(layers[0]!.visible).toBe(true)
  })

  it('prefers layers when both are present', () => {
    const both: any = {
      shape: 'square', fill: '#fff', fillOpacity: 1, stroke: '#000',
      paths: [{ points: [[0, 0]], color: '#000', width: 3 }],
      layers: [{ name: 'A', visible: true, paths: [] }, { name: 'B', visible: false, paths: [] }],
    }
    expect(stickerLayers(both).map(l => l.name)).toEqual(['A', 'B'])
  })

  it('gives an empty sticker exactly one drawable layer', () => {
    const layers = stickerLayers(emptySticker())
    expect(layers).toHaveLength(1)
    expect(layers[0]!.visible).toBe(true)
    expect(layers[0]!.paths).toEqual([])
  })

  it('treats an empty layers array as the implicit single layer', () => {
    const empty: any = { shape: 'none', fill: '#fff', fillOpacity: 1, stroke: '#000', layers: [] }
    expect(stickerLayers(empty)).toHaveLength(1)
  })
})

/**
 * The copy helper, tested against a *reactive proxy* rather than a plain object.
 *
 * That distinction is the whole test: `structuredClone` passes on a plain object and throws
 * "could not be cloned" on a proxy, which is exactly how this shipped broken — every undo and
 * every attempt to edit an existing sticker crashed, while a plain-object test would have
 * stayed green.
 */
describe('plainCopy', () => {
  it('copies a reactive layer array without throwing', () => {
    const layers = reactive([
      { name: 'Ink', visible: true, paths: [{ points: [[0, 0], [5, 5]], color: '#000', width: 3 }] },
      { name: 'Colour', visible: false, paths: [] },
    ])

    // structuredClone would throw DataCloneError here.
    const copy = plainCopy(layers)

    expect(copy).toEqual([
      { name: 'Ink', visible: true, paths: [{ points: [[0, 0], [5, 5]], color: '#000', width: 3 }] },
      { name: 'Colour', visible: false, paths: [] },
    ])
  })

  it('detaches the copy, so mutating the original leaves it alone', () => {
    const layers = reactive([{ name: 'Ink', visible: true, paths: [] as any[] }])
    const copy = plainCopy(layers)

    layers[0]!.paths.push({ points: [[1, 1]], color: '#f00', width: 2 })
    layers[0]!.name = 'Renamed'

    // An undo stack that shared structure with the live drawing would restore nothing.
    expect(copy[0]!.paths).toHaveLength(0)
    expect(copy[0]!.name).toBe('Ink')
  })

  it('copies a sticker reached through a ref, which is how the editor holds one', () => {
    const held = ref(emptySticker())
    expect(() => plainCopy(held.value)).not.toThrow()
    expect(plainCopy(held.value).layers).toHaveLength(1)
  })
})

/**
 * Simplification — the half of the payload fix that shrinks what's *stored*, as opposed to what
 * crosses the socket.
 */
describe('simplifyPoints', () => {
  it('reduces a straight line to its endpoints', () => {
    const line: Array<[number, number]> = Array.from({ length: 50 }, (_, i) => [i * 2, i * 2])
    expect(simplifyPoints(line)).toEqual([[0, 0], [98, 98]])
  })

  it('keeps the corner of an L', () => {
    const bend: Array<[number, number]> = [[0, 0], [25, 0], [50, 0], [50, 25], [50, 50]]
    const out = simplifyPoints(bend)
    expect(out).toContainEqual([50, 0])
    expect(out[0]).toEqual([0, 0])
    expect(out.at(-1)).toEqual([50, 50])
  })

  it('leaves a two-point stroke alone', () => {
    expect(simplifyPoints([[1, 1], [2, 2]])).toEqual([[1, 1], [2, 2]])
  })

  it('survives a stroke that doubles back on itself', () => {
    // Degenerate segment: first and last are the same point, so there is no perpendicular.
    const loop: Array<[number, number]> = [[10, 10], [20, 30], [10, 10]]
    expect(() => simplifyPoints(loop)).not.toThrow()
    expect(simplifyPoints(loop)).toContainEqual([20, 30])
  })
})

describe('compactSticker', () => {
  it('shrinks a hand-drawn sticker substantially', () => {
    // What a pointer actually emits: a sample every few ms along a gentle curve.
    const dense: Array<[number, number]> = Array.from({ length: 400 }, (_, i) => [
      i / 4,
      50 + Math.sin(i / 60) * 8,
    ])
    const sticker = {
      ...emptySticker(),
      layers: [{ name: 'Ink', visible: true, paths: [{ points: dense, color: '#000', width: 3 }] }],
    }

    const compacted = compactSticker(sticker)
    expect(stickerSize(compacted)).toBeLessThan(stickerSize(sticker) / 4)
    // Still a curve, not a straight line.
    expect(compacted.layers![0]!.paths[0]!.points.length).toBeGreaterThan(2)
  })

  it('drops strokes that simplify away to nothing and the legacy paths field', () => {
    const sticker: any = {
      ...emptySticker(),
      paths: [{ points: [[0, 0]], color: '#000', width: 1 }],
      layers: [{ name: 'L', visible: true, paths: [{ points: [[5, 5]], color: '#000', width: 1 }] }],
    }
    const out = compactSticker(sticker)
    expect(out.layers![0]!.paths).toHaveLength(0)
    expect(out.paths).toBeUndefined()
  })

  it('rounds coordinates to one decimal', () => {
    const sticker = {
      ...emptySticker(),
      layers: [{
        name: 'L', visible: true,
        paths: [{ points: [[1.23456, 9.87654], [40.11111, 60.99999]] as Array<[number, number]>, color: '#000', width: 1 }],
      }],
    }
    expect(compactSticker(sticker).layers![0]!.paths[0]!.points).toEqual([[1.2, 9.9], [40.1, 61]])
  })
})
