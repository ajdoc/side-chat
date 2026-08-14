import { describe, expect, it } from 'vitest'
import { reactive, ref } from 'vue'
import { emptySticker, plainCopy, stickerLayers } from './stickers'

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
