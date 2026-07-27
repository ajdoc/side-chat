/**
 * When a door is open.
 *
 * A door is the only piece of furniture whose solidity is a fact about *this moment* rather than
 * about the kind. The server keeps calling it solid, and rightly: everything it judges is the
 * room at rest, where nobody is standing anywhere. Only a browser knows where everyone is, so
 * only a browser opens doors.
 *
 * ## Why it opens for everybody, not for you
 *
 * The tempting rule is "open the door if *I* am near it". It's wrong, and the failure is nasty:
 * you'd watch somebody walk up to a door on the far side of the room and pass straight through a
 * door that, on your screen, never moved. Worse, the two of you would disagree about whether
 * their tile was walkable.
 *
 * So the rule is **open if anybody who may pass is near it** — and every client can evaluate
 * that, because every client already knows where everybody is (positions are whispered several
 * times a second) and who holds which key (the map carries the resolved key-holder list). Same
 * inputs, same function, same answer on every screen. It is the pets' trick exactly: simulate
 * locally from shared state rather than send a second stream of truth.
 *
 * ## Locks
 *
 * A lock doesn't hold a door shut against the world — it holds it shut against *people without
 * keys*. So a locked door still opens, just not for everyone, and somebody with no key sees it
 * open for the person ahead of them and close again behind. Which is what a locked door does.
 */

import type { Facing, Occupant, SpaceMap } from './spaceMapEngine'
import type { SpaceObject } from './spaceDecor'
import { DECOR, decorSize } from './spaceDecor'

/**
 * How close somebody has to be, in tiles, measured from the nearest tile of the door.
 *
 * A shade over one, so a door opens as you arrive at it rather than as you touch it — walking
 * into a door that opens on contact feels like walking into a door. Small enough that standing
 * beside a doorway chatting doesn't hold it open.
 */
export const DOOR_REACH = 1.4

/** Which doors a locked-door list says are locked, and who holds a key to each. */
export type LockMap = Map<string, Set<number>>

/** Turn the map's `locks` payload into something the frame loop can ask cheaply. */
export function lockMap(locks: SpaceMap['locks']): LockMap {
  const map: LockMap = new Map()

  for (const lock of locks ?? []) {
    map.set(lock.object_id, new Set(lock.allowed ?? []))
  }

  return map
}

/** May this person walk through this door? Unlocked doors are open to everybody. */
export function mayPass(locks: LockMap, doorId: string, userId: number | null | undefined): boolean {
  const keys = locks.get(doorId)

  return !keys || (userId != null && keys.has(userId))
}

/** How far a point is from the nearest tile of a door's footprint. */
function distanceTo(door: SpaceObject, at: { x: number, y: number }): number {
  const kind = DECOR[door.kind]
  if (!kind) return Number.POSITIVE_INFINITY

  const { w, h } = decorSize(door, kind)
  // Clamped to the rectangle: the distance to a 2-tile gate is the distance to whichever half of
  // it you're standing at, not to the origin corner it happens to be anchored on.
  const dx = Math.max(door.x - at.x, 0, at.x - (door.x + w - 1))
  const dy = Math.max(door.y - at.y, 0, at.y - (door.y + h - 1))

  return Math.hypot(dx, dy)
}

/**
 * Set `open` on every door in the room, from where everybody is standing.
 *
 * Mutates the objects rather than returning a set, because the answer's only consumer is
 * `decorBlocks`, which is called from the walk loop, the pet loop and the renderer many times per
 * frame and reads the object it already has in its hand. Threading a set through all of those —
 * and through `isWalkable` and `step`, which the editor and the spawn search also call — would
 * push a runtime concern into every static question about the room.
 *
 * Call it once a frame, *before* movement. Doors that nobody is near close again, so a door left
 * open by somebody who has walked away shuts behind them without anything having to notice.
 */
export function syncDoors(
  map: SpaceMap | null,
  occupants: Array<Pick<Occupant, 'id' | 'x' | 'y'>>,
  locks: LockMap,
): void {
  if (!map) return

  for (const object of map.objects ?? []) {
    const kind = DECOR[object.kind]
    if (!kind?.door) continue

    object.open = occupants.some(
      person => mayPass(locks, object.id, person.id) && distanceTo(object, person) <= DOOR_REACH,
    )
  }
}

/**
 * The door somebody is standing at, if any — what the room shows a padlock over.
 *
 * Only used for the prompt: a door needs no pressing, so this exists to *explain* one that isn't
 * opening rather than to offer a way through it.
 */
export function doorInFront(
  map: SpaceMap | null,
  at: { x: number, y: number, facing: Facing },
): SpaceObject | null {
  for (const object of map?.objects ?? []) {
    const kind = DECOR[object.kind]
    if (kind?.door && distanceTo(object, at) <= DOOR_REACH) return object
  }

  return null
}
