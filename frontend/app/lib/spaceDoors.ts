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
 *
 * ## Passes run out on their own
 *
 * Saying a door's password doesn't earn a key, it buys a few seconds. The map carries the moment
 * each pass ends rather than a name on the key-holder list, so nothing has to be broadcast when
 * one lapses: the door simply stops opening for that person on every screen at once, because
 * every screen is comparing the same deadline against its own clock in the same frame loop.
 *
 * Which is the same trick as the rest of this file — one shared fact, evaluated identically
 * everywhere, rather than a second stream of truth about who is currently allowed where.
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

/**
 * Which doors a locked-door list says are locked, who holds a key to each, and whether the door
 * will also open for somebody who knows the password.
 *
 * The password flag is a *fact about the door*, never the phrase — that never leaves the server.
 * It's here because a padlock has two different meanings to somebody without a key ("ask the
 * owner" and "type the words"), and a door that can't tell them apart is a door people give up
 * at.
 */
export type DoorLock = {
  keys: Set<number>
  password: boolean
  /** Who is through on a password, and the epoch millisecond their pass stops working. */
  passes: Map<number, number>
}
export type LockMap = Map<string, DoorLock>

/** Turn the map's `locks` payload into something the frame loop can ask cheaply. */
export function lockMap(locks: SpaceMap['locks']): LockMap {
  const map: LockMap = new Map()

  for (const lock of locks ?? []) {
    map.set(lock.object_id, {
      keys: new Set(lock.allowed ?? []),
      password: !!lock.has_password,
      passes: new Map((lock.passes ?? []).map(pass => [pass.id, pass.until])),
    })
  }

  return map
}

/**
 * May this person walk through this door *now*? Unlocked doors are open to everybody.
 *
 * The clock is read here rather than passed in, because this is asked from the frame loop about
 * everybody in the room and the answer is only ever wanted for the current instant. Callers that
 * need it to change when a pass expires — the prompt over your head, chiefly — have to be ticked;
 * see `passesExpireAt`.
 */
export function mayPass(locks: LockMap, doorId: string, userId: number | null | undefined): boolean {
  const lock = locks.get(doorId)
  if (!lock) return true
  if (userId == null) return false

  return lock.keys.has(userId) || (lock.passes.get(userId) ?? 0) > Date.now()
}

/**
 * When the next outstanding pass anywhere in the room runs out, or null if none is.
 *
 * Exists because a lapsing pass is the one thing about a door that changes with no event behind
 * it: no map arrives, no key is whispered, the clock simply passes a number. The frame loop
 * notices by asking every frame, but Vue's computeds don't — so the stage watches this to know
 * when it has to look again, instead of re-evaluating the whole locked-door prompt sixty times a
 * second on the off-chance.
 */
export function passesExpireAt(locks: LockMap): number | null {
  let soonest: number | null = null

  for (const lock of locks.values()) {
    for (const until of lock.passes.values()) {
      if (until > Date.now() && (soonest === null || until < soonest)) soonest = until
    }
  }

  return soonest
}

/**
 * Is this a door that would open for them if they said the right thing?
 *
 * Only ever true for a door they can't already pass — somebody with a key is never asked for a
 * password, and offering them the box would suggest the door was shut when it isn't.
 */
export function canTryPassword(locks: LockMap, doorId: string, userId: number | null | undefined): boolean {
  return !!locks.get(doorId)?.password && !mayPass(locks, doorId, userId)
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
