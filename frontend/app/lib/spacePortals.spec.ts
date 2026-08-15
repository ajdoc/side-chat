import { describe, expect, it } from 'vitest'
import type { SpaceMap, SpacePortal } from './spaceMapEngine'
import { activationOf, doorwayInto, portalAt, standableIn } from './spaceMapEngine'

/**
 * Doorways: which one you are standing in, how it is taken, and where it puts you down.
 *
 * The rule worth pinning here is the *return* — leaving an interior should put you back in the
 * doorway you came in by, and it does that by looking the doorway up at the moment of travel
 * rather than by remembering a coordinate. An earlier version stored the tile, which went stale
 * the first time anybody moved the door and pointed people at the wrong part of the overworld.
 */

/** A room with a floor, a wall down one side, and whatever doorways a test needs. */
function mapWith(portals: SpacePortal[], slug = 'main'): SpaceMap {
  return {
    id: 1,
    channel_id: 1,
    slug,
    name: 'Test',
    width: 8,
    height: 6,
    // Row 0 is solid; everything below it is floor. Gives every test a tile nobody can stand on.
    tiles: [
      '########',
      '#......#',
      '#......#',
      '#......#',
      '#......#',
      '########',
    ],
    zones: [],
    objects: [],
    spawn: { x: 4, y: 4 },
    portals,
  }
}

function doorway(over: Partial<SpacePortal> = {}): SpacePortal {
  return {
    id: 'd1',
    name: 'Doorway',
    x: 2,
    y: 2,
    w: 2,
    h: 1,
    to: { kind: 'point', x: 5, y: 3 },
    ...over,
  }
}

describe('standing in a doorway', () => {
  it('finds the one under a position, rounding as walking does', () => {
    const map = mapWith([doorway()])

    // Mid-step between tiles still counts: a person's position is a float because they slide,
    // and a doorway you only registered on exact integers is one you could walk straight across.
    expect(portalAt(map, 2.4, 2.1)?.id).toBe('d1')
    expect(portalAt(map, 3, 2)?.id).toBe('d1')
    expect(portalAt(map, 4, 2)).toBeNull()
  })

  it('reads a doorway with no activation as one you walk into', () => {
    // The default matters: every doorway built before activation existed has no such field, and
    // walking into them is what they did.
    expect(activationOf(doorway())).toBe('walk')
    expect(activationOf(doorway({ activation: null }))).toBe('walk')
    expect(activationOf(doorway({ activation: 'press' }))).toBe('press')
  })
})

describe('coming back out of the door you went in by', () => {
  it('finds the doorway leading into a given room', () => {
    const overworld = mapWith([
      doorway({ id: 'to-cellar', to: { kind: 'map', map: 'cellar' } }),
      doorway({ id: 'to-attic', x: 5, y: 4, w: 1, h: 1, to: { kind: 'map', map: 'attic' } }),
    ])

    expect(doorwayInto(overworld, 'attic')?.id).toBe('to-attic')
    expect(doorwayInto(overworld, 'cellar')?.id).toBe('to-cellar')
    expect(doorwayInto(overworld, 'nowhere')).toBeNull()
  })

  it('ignores doorways that lead somewhere other than a map', () => {
    // A same-map jump and a trip to another Side Space both carry a destination, and neither is
    // a way back into an interior. Matching on `to.map` alone would confuse the last two.
    const map = mapWith([
      doorway({ id: 'jump', to: { kind: 'point', x: 5, y: 3 } }),
      doorway({ id: 'away', x: 5, y: 2, w: 1, h: 1, to: { kind: 'room', channel_id: 9 } }),
    ])

    expect(doorwayInto(map, 'attic')).toBeNull()
  })

  it('takes the first of two doors into the same room, stably', () => {
    // A cinema with two entrances to one screen is a real thing to build. Which one you come back
    // out of is arbitrary between them — but it must be the *same* one every time.
    const map = mapWith([
      doorway({ id: 'front', to: { kind: 'map', map: 'screen-one' } }),
      doorway({ id: 'side', x: 5, y: 4, w: 1, h: 1, to: { kind: 'map', map: 'screen-one' } }),
    ])

    expect(doorwayInto(map, 'screen-one')?.id).toBe('front')
    expect(doorwayInto(map, 'screen-one')?.id).toBe('front')
  })

  it('offers a tile inside the doorway that somebody can actually stand on', () => {
    const map = mapWith([])

    expect(standableIn(map, { x: 2, y: 2, w: 2, h: 1 })).toEqual({ x: 2, y: 2 })

    // A doorway drawn half into the wall still yields the standable half, rather than putting
    // somebody in the masonry.
    expect(standableIn(map, { x: 2, y: 0, w: 1, h: 2 })).toEqual({ x: 2, y: 1 })

    // All wall: no answer, and the caller falls back to the room's own entrance.
    expect(standableIn(map, { x: 0, y: 0, w: 3, h: 1 })).toBeNull()
  })

  it('will not put somebody inside the furniture', () => {
    // Solid furniture is as impassable as a wall, so a doorway with a couch across it has to
    // yield the tile beside the couch — the same rule `isWalkable` applies everywhere else.
    const map = mapWith([])
    map.objects = [{ id: 'o1', kind: 'couch', x: 2, y: 2 }]

    expect(standableIn(map, { x: 2, y: 2, w: 3, h: 1 })).toEqual({ x: 4, y: 2 })
  })
})
