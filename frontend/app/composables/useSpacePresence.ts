import type { Facing, Occupant, SpaceMap } from '~/lib/spaceMapEngine'
import type { AvatarLook } from '~/lib/spaceAvatar'
import type { PetKind } from '~/lib/spacePets'
import { MAIN_MAP, facingOf, isWalkable, spawnPoint, step, zoneAt } from '~/lib/spaceMapEngine'
import { normaliseLook } from '~/lib/spaceAvatar'
import { proximityMoved } from '~/composables/useSpaceProximity'

/** How often a position may go out — matches the whiteboard's live layer and the co-op games. */
const WHISPER_EVERY = 80
/**
 * How often a position goes out *even when you haven't moved*.
 *
 * Without this, standing still makes you invisible. Positions are only interesting when they
 * change, so the obvious design whispers on movement alone — but everybody else's picture of
 * you is built purely from those whispers, so somebody who arrives after your last step never
 * learns you exist, never works out that you're within earshot, and never dials you. Which is
 * to say: two people standing near each other in silence, each unaware the other is there.
 *
 * So a stationary person keeps saying where they are, slowly. It's a few bytes a second, and
 * it's what makes the room's membership self-correcting rather than dependent on everyone
 * having been present for everyone else's arrival.
 */
const IDLE_WHISPER_EVERY = 1500
/** Tiles per second at a walk. Fast enough to cross a room, slow enough to steer. */
const SPEED = 5
/** Drop somebody we haven't heard from in this long: a dead tab that presence hasn't reaped. */
const STALE_AFTER = 15_000
/** How often the remembered position is written to the database. Deliberately rare. */
const PERSIST_EVERY = 5000
/** Below this, a remote avatar has arrived and should stop its walk cycle. */
const MOVING_EPSILON = 0.02
/**
 * How far behind you a pet trots, in tiles, and how much faster than you it can move.
 *
 * The gap is why it reads as *following* rather than as being dragged: it only moves at all
 * once you're more than a tile away, so standing still leaves it standing next to you, and
 * walking off pulls it after you a beat later. Slightly faster than a person so it catches up
 * on the straights instead of falling further behind every corner.
 */
const PET_GAP = 1
const PET_SPEED = SPEED * 1.15
/** Past this it stops trying to walk and simply reappears at your heel — see petStep. */
const PET_LOST = 5

/**
 * Holding hands: how close it holds you, how far you may drift, and when it lets go.
 *
 * The whole thing is a *pull towards each other* rather than one person driving the other,
 * which is what makes it work over a wire where every client owns exactly one avatar. Both ends
 * run {@link holdHands} against the same two positions: whoever walks away is the one closing
 * the gap for the other, and the other is dragged because their own client noticed the stretch.
 * Nobody's position is ever written by anybody else, so there's no authority to argue over and
 * nothing to reconcile when a whisper is dropped.
 *
 * `REACH` is the slack — under it you're standing together and nothing moves, which is what
 * stops two people jittering against each other while stood still. `BREAK` is the point where
 * being pulled stops being plausible: a wall between you, somebody warped to spawn, a room
 * reloaded. Letting go there is better than towing a person through a pond.
 */
const HAND_GAP = 0.9
const HAND_REACH = 1.25
const HAND_BREAK = 6
/**
 * Following a leader: how close it trails, and how far is far enough to give up walking.
 *
 * Looser than a hand on purpose. A hand is two people and can hold them a tile apart; a summon
 * is a crowd walking at one person, and at `HAND_GAP` a dozen followers converge on the same
 * square and spend the walk shoving each other through the leader. So the gap is wide enough for
 * a group to stand *around* somebody, and it grows with the size of the crowd — see
 * {@link followGap}.
 *
 * There's no equivalent of `HAND_BREAK` here, and that difference is the whole point of the
 * feature: a hand is a link either end may stretch until it snaps, but a summon that quietly
 * gave up the moment a wall came between you would fail precisely when it was most needed —
 * somebody in the next room is the person you most wanted to bring. So past {@link FOLLOW_LOST}
 * a follower warps instead, exactly as a lost pet reappears at its owner's heel. It ends when
 * the leader says so, or when the follower takes their own keyboard back.
 */
const FOLLOW_GAP = 1.4
const FOLLOW_LOST = 9
/**
 * How much faster than a walk a follower may move.
 *
 * Not a flourish — without it the feature doesn't work. A follower moving at exactly
 * {@link SPEED} can never close a gap on a leader who is walking: the best it can manage is to
 * hold whatever distance it started with, so summoning somebody across the room and then setting
 * off means they trail you for ever at the range they began at. Pets have the same margin for
 * the same reason (see {@link PET_SPEED}), and it's what lets a follower make up ground on the
 * straights instead of losing a little at every corner.
 */
const FOLLOW_SPEED = SPEED * 1.35
/** An unanswered offer to hold hands expires, so nobody is left with a prompt from a ghost. */
const OFFER_TTL = 12_000
/** How close you have to get before "go to them" counts as having got there. */
const APPROACH_WITHIN = 1.3

/**
 * A position as it goes over the wire. Tiles, fractional, plus which way they're facing — and
 * what they look like, which rides along for the same reason the name does: somebody who has
 * just arrived has to be able to draw you from your very first whisper, without a lookup.
 */
interface MovePayload {
  id: number
  name: string
  avatar: string | null
  x: number
  y: number
  facing: Facing
  look?: AvatarLook
  pet?: PetKind | null
  /** The furniture they're sitting on, if any — see Occupant.seatedOn. */
  seat?: string | null
  /** The line over their head, if any — see Occupant.shout. */
  shout?: string | null
  /** Whose hand they're holding, if anyone — see Occupant.handWith. */
  hand?: number | null
  /** When they stepped onto a stage, if they're on one — see Occupant.stageAt. */
  stage?: number | null
  /**
   * Which of the channel's maps they are standing on.
   *
   * A Side Space is a building — an overworld and the interiors behind its doors — and all of
   * it shares one call and one presence channel, which is exactly what makes walking through a
   * door instant rather than a reconnect. The cost of that is this field: everybody's whispers
   * reach everybody, so being in the same *call* no longer means being in the same *place*, and
   * this is the only thing that distinguishes the two.
   *
   * Filtering happens once, on arrival in {@link onMove}, rather than at each of the dozen
   * places that ask about the people in the room. Somebody in another room simply never enters
   * the roster — so drawing, proximity audio, stage broadcasts, hands and pets all exclude them
   * without any of them having heard of interiors.
   *
   * Absent means the way in: a tab running an older build is in the only room it knows about.
   */
  map?: string | null
}

/**
 * Reaching for somebody, taking the offered hand, or letting go.
 *
 * A separate whisper from the position, because it's a *transition* rather than a state: an
 * offer happens once and is either taken or isn't, and folding it into the sixty-times-a-second
 * stream would mean the same offer arriving twelve times a second until it was answered. What
 * comes *of* it — that the two of you are now holding hands — does ride on the position, in
 * `hand`, because that is a state and everybody drawing the room needs it.
 */
interface HandPayload {
  kind: 'offer' | 'accept' | 'let-go'
  from: number
  fromName: string
  to: number
}

/** Somebody pulled a face. Nothing but who and what — see lib/spaceEmotes.ts. */
interface EmotePayload {
  id: number
  glyph: string
}

type RemoteOccupant = Occupant & { tx: number, ty: number, at: number }

/**
 * Somebody appeared in the room, or vanished from it — for whoever happens to be drawing it.
 *
 * Deliberately *not* the same question as earshot. useSpaceProximity already notices people
 * coming and going within hearing and fires the room's configured fanfare at them; this is the
 * plainer fact underneath that: a person who was not in this room now is, or was and no longer
 * is. It's what a puff of light at their feet should be tied to, because walking away from
 * somebody is not the same event as leaving.
 *
 * Carries everything the effect needs to draw itself *after the person is gone* — where they
 * stood, which way they faced, what they looked like — since by the time a departure is known
 * they're already out of `others`.
 */
export interface RoomEvent {
  kind: 'arrive' | 'depart'
  id: number
  name: string
  x: number
  y: number
  facing: Facing
  look: AvatarLook
  /** Your own arrival. Worth a beat of its own: it's the moment you land in the room. */
  self: boolean
}

/**
 * Who's listening. Module-scoped alongside the rest of the room state, but *unlike* the rest it
 * is emptied when the last listener goes: an effect is something you watch, so nobody watching
 * means nothing to fire.
 */
const roomListeners = new Set<(event: RoomEvent) => void>()

function emitRoom(event: RoomEvent) {
  for (const listen of roomListeners) listen(event)
}

/**
 * Everybody we already knew about, so an arrival can be told from a *roster*.
 *
 * Without this every occupant of the room would appear to arrive the instant you walked in —
 * six people standing about would greet you with six puffs of light, none of which happened.
 * Seeded by {@link seed} from the roster the API hands over on entry, and added to as people
 * genuinely turn up.
 */
const knownFaces = new Set<number>()

/*
 * --- room state, deliberately outside the composable ---
 *
 * Standing in a room outlives *looking* at it. You can walk into a Side Space, click away to
 * read another channel, and come back — the call keeps running the whole time (that's the
 * point; it's the same thing a voice channel does), so your position and everybody else's have
 * to keep running too.
 *
 * Held at module scope for exactly the reason useVoice holds its peer connections there: this
 * is state belonging to the *session*, not to whichever component happens to be rendering it.
 * When the stage unmounted and took these refs with it, the avatar came back as null and the
 * room was unwalkable — the sprite was gone because the state was.
 */
const me = shallowRef<Occupant | null>(null)
/**
 * Everybody else, keyed by user id. Rendered from `x`/`y`; `tx`/`ty` are where they're heading.
 *
 * `shallowRef`, and that's load-bearing. Positions are rewritten for every occupant on every
 * animation frame; with a deep ref that would be a hundred reactive writes a frame, each one
 * waking whatever is watching the roster. Nothing *should* re-render when somebody takes a
 * step — the room is a canvas, and it reads these values directly in its draw loop. So
 * per-frame movement mutates in place and notifies nobody, and only the things that genuinely
 * change the roster (somebody arriving, leaving, being pruned) replace the object and trigger.
 */
const others = shallowRef<Record<number, RemoteOccupant>>({})
/** Whether you're walking right now — drives the sprite's walk cycle. */
const moving = shallowRef(false)

/** Which keys are down. Held rather than handled per-event so movement is continuous. */
const held = new Set<string>()

/**
 * Where a pointer has asked us to walk, in tiles, or null for "nowhere".
 *
 * The room was keyboard-only, which on a phone browser means it cannot be played at all — there
 * are no arrow keys, and the native app's on-screen pad isn't there either. So there's a second
 * way in: point at the floor and walk towards it. One target rather than a direction, because
 * that covers both gestures with the same state — a *held* pointer rewrites the target as it
 * drags (so it steers, like a thumbstick), and a *tap* leaves the last target standing so you
 * keep walking to where you tapped.
 *
 * Held at module scope alongside {@link me} and for the same reason: it's part of standing in the
 * room, not part of the canvas that happens to be showing it.
 */
let steer: { x: number, y: number } | null = null

/**
 * Somebody you're walking over to, or null.
 *
 * Not the same as a steer, and that's the point: a person moves. A steer is a spot on the floor
 * and would leave you marching to where they were standing when you tapped them, arriving at an
 * empty tile a beat after they wandered off. So this is held as an *id*, and {@link moveSelf}
 * rewrites the steer from wherever they are each frame — which is what "go to them" means and
 * what a tap on a person should obviously do.
 *
 * Cleared by arriving, by touching a movement key, by tapping the floor, and by them leaving.
 */
let approaching: number | null = null

/**
 * An offer to hold hands that's waiting on you, or null.
 *
 * A `ref`, unlike the movement state next to it, because this one genuinely is something the UI
 * renders — a prompt with a name in it and two buttons. Held at module scope with everything
 * else about standing in a room, so an offer made while you're reading another channel is still
 * there when you look back at the room rather than having been torn down with the canvas.
 */
const handOffer = shallowRef<{ id: number, name: string, at: number } | null>(null)

/**
 * Whoever has summoned you, or null — see {@link followLeader}.
 *
 * A `ref` for the same reason the hand offer beside it is one: the UI has to say so. Being
 * towed round a room without being told who by, or without a way to see that it's happening, is
 * the difference between a feature and a haunting — consent isn't asked for here, so the *least*
 * this owes a follower is that it's never a mystery.
 *
 * Held at module scope with the rest of standing-in-a-room, and deliberately not persisted
 * anywhere: a summon is a state of the moment. Walking out, reloading, or the leader closing
 * their laptop all end it, and none of those should need a server to remember they happened.
 */
const following = shallowRef<{ id: number, name: string } | null>(null)

/**
 * A summon that hasn't been able to land yet.
 *
 * The snap in {@link onSummoned} needs two things the moment the summon arrives — the leader's
 * whispered position and a loaded map — and a summon can beat either of them: somebody who has
 * just walked in has a map still fetching, and a leader who has been standing still is at most
 * IDLE_WHISPER_EVERY away from having said where they are. Rather than drop the summon or warp
 * to a guess, the snap is left owed and taken by the first frame that can pay it.
 */
let snapPending = false

/**
 * How close counts as arrived, in tiles, and how little progress in one frame counts as stuck.
 *
 * Arrival has to be forgiving: {@link SPEED} tiles a second covers ~0.08 of a tile per frame, so
 * a tighter radius would have you oscillating across the target for ever. Stuck matters because
 * a target can be somewhere you cannot reach — behind a wall, inside the pond — and without this
 * you'd walk into that wall until you thought to tap somewhere else.
 */
const ARRIVE_WITHIN = 0.12
const STUCK_BELOW = 0.004

/** The room we're currently attached to, or null. Guards against double-subscribing. */
let attachedTo: number | null = null
let channel: any = null
/**
 * The channel's own private channel, held only for `.SideSpaceSummoned`.
 *
 * Separate from `channel` above because it *is* separate: everything else in here rides the
 * call's presence channel as a whisper, and a summon is the one thing that has to come from the
 * server. See subscribe().
 */
let summonChannel: any = null
let keysBound = false
let lastWhisperAt = 0
let lastPersistAt = 0
let pruneTimer: ReturnType<typeof setInterval> | undefined
let idleTimer: ReturnType<typeof setInterval> | undefined
let persistOnUnload: (() => void) | undefined
/**
 * Stops the roster watch below, and the detached scope it lives in.
 *
 * Detached deliberately: `subscribe()` is called from a mounted stage, so a plain `watch` would
 * be adopted by that component's scope and torn down the moment you clicked to another channel
 * — while you are still standing in the room, which is precisely when nobody is watching the
 * roster. Same reasoning as the timers beside it.
 */
const roomScope = effectScope(true)
let stopMemberWatch: (() => void) | undefined
/** True once we've been placed, so a late map load doesn't teleport somebody mid-stride. */
let placed = false

/**
 * Where everybody is standing, and moving your own avatar.
 *
 * ## Why this never touches the server
 *
 * Positions ride as **whispers on the call's own presence channel** — `voice.{channelId}`, the
 * same one the WebRTC handshake uses. Not a new stream, and not an HTTP endpoint:
 *
 *   - Membership of that channel is exactly what "is in the room" means, so the authorisation
 *     question is already answered. Somebody reading the timeline from outside has no business
 *     being told where people are walking about.
 *   - A closed laptop stops sending a position the instant its socket drops, and presence's
 *     `leaving` cleans up after it. There is no row to go stale, no sweep to write.
 *   - It's a dozen bytes several times a second per person. Through HTTP → queue → broadcast
 *     that would be absurd; between subscribers it's free. The whiteboard's cursors made the
 *     same call for the same reason.
 *
 * The one thing that *is* persisted is where you stopped, on a long throttle — so that
 * reloading puts you back where you were rather than at the front door. That's a different
 * question, asked once a session, and it's the only one worth a database write.
 *
 * ## Attached vs. bound
 *
 * Two separate lifetimes, and conflating them was a bug. **Attaching** (the whisper listener,
 * the idle heartbeat) lasts as long as you're *in the room*, which outlives the stage — walk
 * in, click to another channel, and you're still standing there, still audible, so you had
 * better still be visible. **Binding keys** lasts only as long as the room is *on screen*,
 * because arrow keys should move your avatar when you're looking at the map and scroll the
 * page when you aren't.
 *
 * ## Smoothness
 *
 * Whispers arrive ~12 times a second; the room is drawn 60. So a remote position is a *target*
 * that {@link interpolate} eases towards each frame, rather than something assigned. Without
 * that, everybody else moves in visible steps while only you move smoothly.
 */
export function useSpacePresence(channelId: number, map: Ref<SpaceMap | null>) {
  const api = useApi()
  const config = useRuntimeConfig()
  const echo: any = useNuxtApp().$echo
  const token = useAuthToken()
  const { user } = useAuth()

  /** Are we already standing in this particular room? */
  const attached = computed(() => attachedTo === channelId)

  /**
   * Which of the channel's maps we are standing on — the way in, unless a door has moved us.
   *
   * Read from the map itself rather than tracked separately, because the map *is* the answer:
   * the composable that fetches it swaps in a whole new document when you walk through a door,
   * so there is no moment where the grid under your feet and this disagree.
   */
  function mapSlug() {
    return map.value?.slug ?? MAIN_MAP
  }

  /*
   * Walking through a door empties the room of everybody else, at once.
   *
   * Their whispers will sort this out on their own — each one carries a slug and {@link onMove}
   * drops anybody whose slug isn't ours — but "on their own" means up to a second and a half for
   * somebody standing still, and a second and a half of the lobby's crowd standing about inside
   * the cinema is the kind of thing that makes a door feel broken rather than slow.
   *
   * Ours alone is untouched: `me` walked through the door on purpose.
   */
  watch(() => map.value?.slug, (now, before) => {
    if (now === before || before === undefined) return

    others.value = {}
    proximityMoved()
  })

  // --- placement ---

  /**
   * Put yourself in the room: where you were last time if it's still somewhere you can stand,
   * otherwise the entrance.
   *
   * The walkability check matters because the map may have been rebuilt while you were away —
   * a remembered position is only a hint, and the room as it is now has the final say.
   */
  function place(remembered: { x: number, y: number, facing: Facing | null } | null) {
    const m = map.value
    if (!m || !user.value || placed) return

    const at = remembered && isWalkable(m, remembered.x, remembered.y)
      ? { x: remembered.x, y: remembered.y }
      : spawnPoint(m)

    const facing = remembered?.facing ?? 'down'

    me.value = {
      id: user.value.id,
      name: user.value.name,
      avatar: user.value.avatar,
      x: at.x,
      y: at.y,
      facing,
      look: normaliseLook(user.value.space_avatar),
      pet: user.value.space_pet ?? null,
      shout: user.value.space_shout ?? null,
      // Standing beside you rather than on you, so it's visible the moment you walk in.
      petAt: { x: at.x - 0.6, y: at.y + 0.3, facing },
    }
    placed = true
    whisperMove(true)

    // Your own arrival. Everyone else in the room gets one of these from your first whisper,
    // and it would be odd for the person it happened to to be the only one who missed it.
    emitRoom({
      kind: 'arrive',
      id: user.value.id,
      name: user.value.name,
      x: at.x,
      y: at.y,
      facing,
      look: me.value.look,
      self: true,
    })
  }

  // --- sitting ---

  /**
   * Where you were standing when you sat down.
   *
   * Getting up has to put you somewhere you can actually be, and the seat itself usually isn't:
   * a couch is solid, so the tile you're sitting on is one you could never have walked onto.
   * Remembering the tile you came from means standing up returns you to the floor you were on a
   * moment ago, which is both correct and what it looks like should happen.
   */
  let stoodAt: { x: number, y: number } | null = null

  /**
   * Sit down on a piece of furniture.
   *
   * Bypasses collision on purpose — that's the point of a seat. Everything that keeps this from
   * being a way to stand inside a wall is upstream, in the catalogue: only a piece marked as a
   * seat ever gets here, and where on it you land is {@link seatOn}'s decision, not a
   * coordinate anybody sent.
   */
  function sit(objectId: string, at: { x: number, y: number, facing: Facing }) {
    if (!me.value || me.value.seatedOn) return

    stoodAt = { x: me.value.x, y: me.value.y }
    // Sitting down ends whatever walk was in progress. Without this, a tap-to-walk target that
    // was still live would drag you straight back off the couch.
    steer = null
    held.clear()
    moving.value = false

    me.value = { ...me.value, x: at.x, y: at.y, facing: at.facing, seatedOn: objectId }
    whisperMove(true)
    persist()
  }

  /**
   * Get up. Back to the tile you sat down from, or the nearest floor if the room has been
   * rebuilt around you in the meantime.
   */
  function stand() {
    const m = map.value
    if (!me.value?.seatedOn || !m) return

    const back = stoodAt && isWalkable(m, stoodAt.x, stoodAt.y) ? stoodAt : nearestFloor(m, me.value)
    stoodAt = null

    me.value = { ...me.value, x: back.x, y: back.y, seatedOn: null }
    whisperMove(true)
    persist()
  }

  const seated = computed(() => !!me.value?.seatedOn)

  /**
   * The closest tile you could stand on, searched outwards in rings.
   *
   * Only ever reached when the seat you're on has stopped having floor beside it — somebody
   * rebuilt the room while you sat in it. Falls back to the entrance, which is where the room
   * puts anybody it can't otherwise place.
   */
  function nearestFloor(m: SpaceMap, from: { x: number, y: number }): { x: number, y: number } {
    const cx = Math.round(from.x)
    const cy = Math.round(from.y)

    for (let r = 1; r <= 6; r++) {
      for (let dy = -r; dy <= r; dy++) {
        for (let dx = -r; dx <= r; dx++) {
          if (Math.max(Math.abs(dx), Math.abs(dy)) !== r) continue
          if (isWalkable(m, cx + dx, cy + dy)) return { x: cx + dx, y: cy + dy }
        }
      }
    }

    return spawnPoint(m)
  }

  /**
   * Teleport, and tell the room at once.
   *
   * For the moments the game moves you rather than your legs — an Among Us meeting whisks
   * everyone back to one spot. Snaps rather than walks (no easing), and forces the whisper so
   * the jump lands on everyone else's screen immediately rather than on your next step.
   */
  function warp(x: number, y: number) {
    if (!me.value) return
    // Being whisked across the room is being got up: a game that moves everybody to one spot
    // must not leave somebody still nominally sitting on a couch two rooms away.
    stoodAt = null
    me.value = { ...me.value, x, y, seatedOn: null }
    whisperMove(true)
  }

  /** Someone else's remembered position, so the room is drawn right before they first move. */
  function seed(occupants: Array<{
    id: number
    name: string
    avatar: string | null
    x: number | null
    y: number | null
    facing: Facing | null
    look?: AvatarLook | null
    pet?: PetKind | null
    shout?: string | null
    /** Which room of the building they were last standing in. Null reads as the way in. */
    space_map?: string | null
  }>) {
    if (!map.value) return

    const next = { ...others.value }

    for (const o of occupants) {
      // Known whether or not we can place them: somebody on the roster with no remembered
      // position is still somebody who was already here, and their first whisper mustn't be
      // mistaken for them walking in.
      knownFaces.add(o.id)

      if (o.id === user.value?.id || o.x === null || o.y === null) continue

      // Remembered somewhere else in the building. Their coordinates are real but they are for
      // another grid, so drawing them here would put somebody from the cinema in the lobby —
      // and a *remembered* position is the one case the whisper filter can't catch, because it
      // arrives before anybody has whispered anything.
      if ((o.space_map ?? MAIN_MAP) !== mapSlug()) continue

      next[o.id] = {
        id: o.id,
        name: o.name,
        avatar: o.avatar,
        x: o.x,
        y: o.y,
        tx: o.x,
        ty: o.y,
        facing: o.facing ?? 'down',
        look: normaliseLook(o.look),
        pet: o.pet ?? null,
        petAt: { x: o.x - 0.6, y: o.y + 0.3, facing: o.facing ?? 'down' },
        shout: o.shout ?? null,
        at: Date.now(),
      }
    }

    others.value = next
  }

  // --- moving ---

  function onKeyDown(e: KeyboardEvent) {
    if (!isMoveKey(e.key)) return
    // Typing in the composer must not walk you into a wall.
    if (isTyping(e.target)) return

    held.add(e.key.toLowerCase())
    // Reaching for the keys cancels wherever a tap was taking you: two things steering one
    // avatar is how you end up fighting your own room.
    steer = null
    e.preventDefault()
  }

  /**
   * Walk towards a point in the room. The pointer's way in — see {@link steer}.
   *
   * Called on press and again for every drag, so holding and dragging is continuous steering,
   * while pressing and letting go is "go there".
   */
  function walkTo(x: number, y: number) {
    if (!me.value) return
    // Pointing at the floor is choosing the floor over whoever you were walking towards.
    approaching = null
    steer = { x, y }
  }

  /** Stop wherever we are, abandoning a pointer target. For the buttons that take movement away. */
  function stopWalking() {
    steer = null
    approaching = null
  }

  function onKeyUp(e: KeyboardEvent) {
    held.delete(e.key.toLowerCase())
  }

  /** Arrows and WASD both, because half the room will reach for each. */
  function isMoveKey(key: string): boolean {
    return ['arrowup', 'arrowdown', 'arrowleft', 'arrowright', 'w', 'a', 's', 'd'].includes(key.toLowerCase())
  }

  function isTyping(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null

    return !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)
  }

  /** Let go of everything — on blur, and whenever the room leaves the screen. */
  function releaseKeys() {
    held.clear()
    steer = null
    moving.value = false
  }

  /**
   * Advance one frame. Called by the stage's render loop with the seconds since the last one,
   * so movement speed is the same on a 60Hz laptop and a 144Hz monitor.
   */
  function tick(dt: number) {
    const m = map.value
    if (!m) return

    moveSelf(m, dt)
    // After your own step and before the pets': being towed by a hand is movement of the same
    // kind walking is, and the pet behind you should trail the place you ended up.
    holdHands(m, dt)
    // After the hand, and for the same reason it runs after your own step: being summoned is
    // movement of the same kind walking is, and both want the position you actually ended at.
    followLeader(m, dt)
    interpolate(dt)
    movePets(m, dt)
    expireOffer()
  }

  /**
   * Keep up with whoever's hand you're holding.
   *
   * Runs on *your* avatar only — the partner's is theirs to move, and they're running the
   * mirror image of this against you. Which means the pair converges from both ends: walk off
   * and your own client leaves them behind for a fraction of a second, until their client
   * notices the stretch and closes it. That fraction is what makes it read as being *pulled*
   * rather than as two sprites glued together.
   *
   * Uses the same `step` the walk loop does, so a hand is no way through a wall. If the room
   * won't let you follow — a door shut between you, a couch in the way — the gap opens until it
   * passes {@link HAND_BREAK} and the link simply ends, which is the honest outcome and needs no
   * agreement from the other end: they'll watch it break for the same reason a moment later.
   */
  function holdHands(m: SpaceMap, dt: number) {
    const self = me.value
    if (!self?.handWith) return

    const partner = others.value[self.handWith]

    // They walked out, or their tab died and the sweep took them. Nothing left to hold.
    if (!partner) return letGo()

    const dx = partner.x - self.x
    const dy = partner.y - self.y
    const d = Math.hypot(dx, dy)

    if (d > HAND_BREAK) return letGo()
    if (d <= HAND_REACH) return

    // Getting dragged off a seat is standing up, exactly as walking off one is.
    if (self.seatedOn) stand()

    const travel = Math.min(SPEED * dt, d - HAND_GAP)
    const next = step(m, me.value!, (dx / d) * travel, (dy / d) * travel)

    me.value = { ...me.value!, ...next, facing: facingOf(next.x - self.x, next.y - self.y, self.facing) }
    whisperMove()
  }

  /**
   * How far back to trail, given how many others are trailing the same person.
   *
   * A crowd needs a wider ring than a pair, or the people at the front stop the people behind
   * from ever closing the gap and the whole group grinds against itself. Grows with the square
   * root of the crowd because what has to fit is an *area* of standing room, not a queue — and
   * it's computed from the same roster on every client, so nobody's ring disagrees.
   */
  function followGap(leaderId: number) {
    const crowd = Object.values(others.value).filter(o => o.id !== leaderId).length + 1

    return FOLLOW_GAP * Math.sqrt(crowd)
  }

  /**
   * Walk after whoever summoned you.
   *
   * The mirror image of {@link petStep}, run against a person instead of a creature, and it is
   * *your* client doing the walking — which is the same rule the rest of this file obeys and the
   * reason a summon needs no new authority over anybody's coordinates. The server said who may
   * start this; from then on it is you following a position they were already whispering.
   *
   * Unlike a hand, it doesn't break on distance. A summon exists to gather people who are *not*
   * already next to you, so a wall between you is the normal case rather than the failure case:
   * past {@link FOLLOW_LOST} the walk is abandoned and you simply appear beside them, the same
   * concession `petStep` makes and for the same reason — the alternative is a navmesh, and the
   * honest answer for somebody stuck on a couch two rooms away is that they caught up.
   */
  function followLeader(m: SpaceMap, dt: number) {
    const self = me.value
    const lead = following.value
    if (!self || !lead) return

    const leader = others.value[lead.id]

    // They walked out, or their tab died and the sweep took them. Nobody left to follow, and
    // nothing to tell them — a leader who has gone has already stopped leading.
    if (!leader) return unfollow()

    // A summon that arrived before the map or before the leader's first whisper. See snapPending.
    if (snapPending && snapToLeader()) return

    const dx = leader.x - self.x
    const dy = leader.y - self.y
    const d = Math.hypot(dx, dy)

    if (d > FOLLOW_LOST) {
      // Beside them rather than on them: warp takes a tile, and a dozen people warping onto one
      // would arrive as a single stack. `nearestFloor` spirals out from the target, so a crowd
      // lands in a ring around the leader instead.
      const spot = nearestFloor(m, leader)

      return warp(spot.x, spot.y)
    }

    if (d <= followGap(lead.id)) return

    // Being pulled off a seat is standing up, exactly as walking off one is.
    if (self.seatedOn) stand()

    const travel = Math.min(FOLLOW_SPEED * dt, d - FOLLOW_GAP)
    const next = step(m, self, (dx / d) * travel, (dy / d) * travel)

    me.value = { ...me.value!, ...next, facing: facingOf(next.x - self.x, next.y - self.y, self.facing) }
    whisperMove()
  }

  /** An offer nobody answered. Dropped quietly — there's nothing to tell either party. */
  function expireOffer() {
    if (handOffer.value && Date.now() - handOffer.value.at > OFFER_TTL) handOffer.value = null
  }

  /**
   * Trot every pet after its owner.
   *
   * Entirely local. Nothing about a pet goes over the wire but *which* creature it is, riding
   * along with its owner's position — everybody runs this same function against the same
   * positions and arrives at the same place, so a second stream of coordinates per person would
   * buy nothing but traffic.
   */
  function movePets(m: SpaceMap, dt: number) {
    if (me.value?.pet && me.value.petAt) petStep(m, me.value.petAt, me.value, dt)

    for (const o of Object.values(others.value)) {
      if (o.pet && o.petAt) petStep(m, o.petAt, o, dt)
    }
  }

  /**
   * One pet, one frame.
   *
   * Walks with the same collision the people do — a pet strolling through the wall you just
   * walked round would be the sort of small wrongness that's hard to stop noticing — but gives
   * up rather than pathfinding: past {@link PET_LOST} tiles it simply reappears at your heel.
   * Anything cleverer is a navmesh, and the honest answer for a creature that has just spent
   * ten seconds stuck on a couch is that it caught up while you weren't looking.
   */
  function petStep(m: SpaceMap, pet: { x: number, y: number, facing: Facing }, owner: { x: number, y: number, facing: Facing }, dt: number) {
    const dx = owner.x - pet.x
    const dy = owner.y - pet.y
    const d = Math.hypot(dx, dy)

    if (d > PET_LOST) {
      pet.x = owner.x
      pet.y = owner.y

      return
    }

    if (d <= PET_GAP) return

    // Never overshoot into its owner: at most the distance that closes the gap.
    const travel = Math.min(PET_SPEED * dt, d - PET_GAP)
    const next = step(m, pet, (dx / d) * travel, (dy / d) * travel)

    // A pet whose owner has walked behind furniture can end up pressed against it. Sliding is
    // handled by `step`; this catches the case where both axes are blocked, and waits.
    pet.facing = facingOf(next.x - pet.x, next.y - pet.y, pet.facing)
    pet.x = next.x
    pet.y = next.y
  }

  function moveSelf(m: SpaceMap, dt: number) {
    if (!me.value) return

    let dx = (held.has('arrowright') || held.has('d') ? 1 : 0) - (held.has('arrowleft') || held.has('a') ? 1 : 0)
    let dy = (held.has('arrowdown') || held.has('s') ? 1 : 0) - (held.has('arrowup') || held.has('w') ? 1 : 0)
    let pointed = false

    // Reaching for the keyboard is taking the wheel back — you've stopped following them.
    if ((dx !== 0 || dy !== 0) && approaching !== null) {
      approaching = null
      steer = null
    }

    // Walking over to somebody. Recomputed here, every frame, from where they *now* are: the
    // target is a person, and a person you tapped ten strides ago has moved. Stopping short by
    // HAND_GAP is what stops "go to them" from ending with you standing inside them.
    if (approaching !== null) {
      const them = others.value[approaching]

      if (!them) approaching = null
      else {
        const gap = Math.hypot(them.x - me.value.x, them.y - me.value.y)

        if (gap <= APPROACH_WITHIN) {
          // Arrived: stop, and turn to face them. Anything else leaves you standing beside
          // somebody with your back to them, which is not what tapping them asked for.
          approaching = null
          steer = null
          me.value = { ...me.value, facing: facingOf(them.x - me.value.x, them.y - me.value.y, me.value.facing) }
          whisperMove(true)
        }
        else {
          steer = { x: them.x - ((them.x - me.value.x) / gap) * HAND_GAP, y: them.y - ((them.y - me.value.y) / gap) * HAND_GAP }
        }
      }
    }

    /*
     * Sitting is a state you leave by trying to move, which is the rule every game with a chair
     * in it uses and the one nobody has to be taught. Standing up happens *this* frame and the
     * walking starts on the next one, so getting up is a beat of its own rather than a person
     * teleporting off the couch mid-stride.
     */
    if (me.value.seatedOn) {
      if (dx !== 0 || dy !== 0 || steer) stand()

      return
    }

    // No key down, but a pointer has asked for somewhere: head for it. The direction is recomputed
    // every frame rather than once on the tap, so walking round a couch still ends up where you
    // pointed instead of alongside it.
    if (dx === 0 && dy === 0 && steer) {
      const gap = Math.hypot(steer.x - me.value.x, steer.y - me.value.y)

      if (gap <= ARRIVE_WITHIN) steer = null
      else {
        dx = steer.x - me.value.x
        dy = steer.y - me.value.y
        pointed = true
      }
    }

    if (dx === 0 && dy === 0) {
      if (moving.value) {
        moving.value = false
        // Say where you actually stopped. The last whisper went out up to WHISPER_EVERY ago and
        // is therefore short of the truth by a fraction of a tile — which matters at exactly the
        // moment somebody stops walking towards you and expects to be heard.
        whisperMove(true)
      }

      return
    }

    if (!moving.value) moving.value = true

    // Normalised, so walking diagonally isn't 1.4× faster than walking straight.
    const len = Math.hypot(dx, dy)
    const distance = SPEED * dt

    const next = step(m, me.value, (dx / len) * distance, (dy / len) * distance)

    // Walking into something with a pointer target set: give the target up rather than lean on the
    // wall for ever. Keys don't need this — you can see you're stuck and let go.
    if (steer && Math.hypot(next.x - me.value.x, next.y - me.value.y) < STUCK_BELOW) {
      steer = null
    }

    /*
     * Which way to face. `facingOf` lets vertical win any tie, which is right for the keyboard
     * (a diagonal is two whole keys) but wrong for a pointer, where the direction is a fraction:
     * walking almost due east with a hair of south in it would have you facing south the whole
     * way. So a pointed direction is reduced to its dominant axis first.
     */
    const [fx, fy] = pointed && Math.abs(dx) > Math.abs(dy) ? [dx, 0] : pointed ? [0, dy] : [dx, dy]

    me.value = { ...me.value, ...next, facing: facingOf(fx, fy, me.value.facing) }

    whisperMove()
    persist()
  }

  /**
   * Ease everybody else towards the last position they whispered.
   *
   * Exponential rather than linear, and framerate-corrected: covering the same *fraction* of
   * the remaining gap per second regardless of frame length is what stops a slow frame from
   * making everyone lurch.
   */
  function interpolate(dt: number) {
    const k = 1 - 0.0001 ** dt

    for (const o of Object.values(others.value)) {
      o.x += (o.tx - o.x) * k
      o.y += (o.ty - o.y) * k
    }
  }

  // --- the wire ---

  /**
   * Notice that you've walked onto a stage, or off one, and stamp the moment.
   *
   * Runs from {@link whisperMove}, which is to say after every single thing that moves you —
   * walking, being towed by a hand, sitting, standing, being warped by a game. That's the whole
   * gesture: stepping across the line is going live, and stepping back off is leaving. Nothing
   * to click, and nobody to ask (see `liveSpeakers` for why the cap needs no server).
   *
   * The timestamp is set *once*, on entry, and left alone while you stand there — it's your
   * place in the queue, so re-stamping it every frame would send you to the back of it forever.
   *
   * Returns whether anything changed, because going live is worth breaking the whisper throttle
   * for: the audience finding out an eighth of a second late is the first word of the talk.
   */
  function syncStage(): boolean {
    const m = map.value
    if (!m || !me.value) return false

    const onStage = zoneAt(m, me.value.x, me.value.y)?.kind === 'stage'
    if (onStage === !!me.value.stageAt) return false

    me.value = { ...me.value, stageAt: onStage ? Date.now() : null }

    return true
  }

  function whisperMove(force = false) {
    if (!channel || !me.value) return

    const stageChanged = syncStage()
    const now = Date.now()
    if (!force && !stageChanged && now - lastWhisperAt < WHISPER_EVERY) return
    lastWhisperAt = now

    channel.whisper('sp-move', {
      id: me.value.id,
      name: me.value.name,
      avatar: me.value.avatar,
      // Rounded to a tenth of a tile: nobody can see finer than that, and it keeps the
      // payload small enough to be beneath notice.
      x: Math.round(me.value.x * 10) / 10,
      y: Math.round(me.value.y * 10) / 10,
      facing: me.value.facing,
      look: me.value.look,
      pet: me.value.pet ?? null,
      seat: me.value.seatedOn ?? null,
      shout: me.value.shout ?? null,
      hand: me.value.handWith ?? null,
      stage: me.value.stageAt ?? null,
      map: mapSlug(),
    } satisfies MovePayload)
  }

  function onMove(payload: MovePayload) {
    if (payload.id === user.value?.id) return

    /*
     * Somebody in another room of the same building.
     *
     * They are on this presence channel and this call, so their whispers arrive — but they are
     * not *here*, and drawing them would put an avatar from the cinema's screen one in the
     * middle of the lobby. Dropped rather than ignored, because they may have walked out of
     * this room a moment ago and still be on the roster: leaving them there would freeze them
     * mid-step at the doorway until the stale sweep eventually took them.
     */
    if ((payload.map ?? MAIN_MAP) !== mapSlug()) {
      if (others.value[payload.id]) {
        const { [payload.id]: gone, ...rest } = others.value
        others.value = rest
        proximityMoved()
      }

      return
    }

    const existing = others.value[payload.id]

    // Somebody already here just moved: retarget in place, no reactive churn — this is the
    // path that runs a dozen times a second per person.
    if (existing) {
      existing.tx = payload.x
      existing.ty = payload.y
      existing.facing = payload.facing
      existing.at = Date.now()

      // Somebody who has just changed their hair mid-room shouldn't have to walk out and back
      // in for anyone to see it. Mutated in place, like the position, so it costs no re-render.
      if (payload.look) existing.look = payload.look
      existing.pet = payload.pet ?? null
      existing.seatedOn = payload.seat ?? null
      // Same in-place treatment as the look: somebody who has just changed what they're
      // shouting shouldn't have to walk out and back in for anyone to read it.
      existing.shout = payload.shout ?? null
      existing.handWith = payload.hand ?? null
      // In place like the rest: who is live is recomputed from these values on the proximity
      // clock, so it needs no re-render of its own to take effect.
      existing.stageAt = payload.stage ?? null
      if (existing.pet && !existing.petAt) {
        existing.petAt = { x: payload.x, y: payload.y, facing: payload.facing }
      }

      // Somebody moved, so who can hear whom may have changed. Told rather than polled: it makes
      // proximity as prompt as the positions themselves, and independent of whether this tab is
      // being drawn or its timers are being throttled. Cheap — it throttles itself.
      proximityMoved()

      return
    }

    // A newcomer changes the roster, so it's worth telling everyone about. They appear exactly
    // where they say they are rather than gliding in from wherever the origin happens to be.
    const look = normaliseLook(payload.look)

    others.value = {
      ...others.value,
      [payload.id]: {
        id: payload.id,
        name: payload.name,
        avatar: payload.avatar,
        x: payload.x,
        y: payload.y,
        tx: payload.x,
        ty: payload.y,
        facing: payload.facing,
        look,
        pet: payload.pet ?? null,
        petAt: { x: payload.x - 0.6, y: payload.y + 0.3, facing: payload.facing },
        seatedOn: payload.seat ?? null,
        shout: payload.shout ?? null,
        handWith: payload.hand ?? null,
        stageAt: payload.stage ?? null,
        at: Date.now(),
      },
    }

    /*
     * Somebody walked in.
     *
     * Fired *here*, on their first whisper, rather than on the presence event that strictly
     * announces them — because presence says only that a person exists, and an effect needs
     * somewhere to happen. A whisper is the first moment we know both, and it's at most a
     * twelfth of a second later.
     *
     * `knownFaces` is what keeps this honest: everyone on the roster you walked in on is
     * already in it, so their first whisper is a hello rather than an arrival.
     */
    if (!knownFaces.has(payload.id)) {
      knownFaces.add(payload.id)
      emitRoom({
        kind: 'arrive',
        id: payload.id,
        name: payload.name,
        x: payload.x,
        y: payload.y,
        facing: payload.facing,
        look,
        self: false,
      })
    }

    // Somebody we'd never heard from before. They don't know where we are either — their roster
    // gave them wherever we last *stopped*, which may be a room and a session out of date — so
    // answer at once rather than leaving them to wait out the idle heartbeat. Two people walking
    // towards each other should not have to stand still for a second and a half to discover it.
    //
    // Forced, but it can't storm: everybody already known is skipped above, so a newcomer draws
    // exactly one reply per occupant and the replies themselves are answers, not new arrivals.
    whisperMove(true)

    // And decide at once whether we can hear them. A stranger who appears already standing next
    // to you — walked in, or reloaded — should be dialled now, not on their next step.
    proximityMoved()
  }

  /** Is this person mid-stride? Drives their walk cycle; `me` answers from the keyboard. */
  function isWalking(o: RemoteOccupant): boolean {
    return Math.abs(o.tx - o.x) > MOVING_EPSILON || Math.abs(o.ty - o.y) > MOVING_EPSILON
  }

  /**
   * Write where you're standing, rarely.
   *
   * Fire-and-forget: a lost position costs you nothing but reappearing a few tiles off next
   * time, which is not worth a retry, a spinner, or an error anybody reads.
   */
  function persist() {
    const now = Date.now()
    if (now - lastPersistAt < PERSIST_EVERY || !me.value) return
    lastPersistAt = now

    api(`/api/channels/${channelId}/space/position`, {
      method: 'POST',
      // The room as well as the tile: (12, 4) means two different places in two different
      // rooms of the same building, so coordinates alone would put people back through a wall.
      body: { x: Math.round(me.value.x), y: Math.round(me.value.y), facing: me.value.facing, space_map: mapSlug() },
    }).catch(() => {})
  }

  /**
   * Take away the avatar of anybody who is no longer on the presence channel.
   *
   * Leaving a room is silent from this composable's point of view: positions are whispers, and
   * somebody who walks out simply stops whispering. That left them standing in the room until
   * {@link prune} noticed they'd gone quiet — fifteen seconds of a person who isn't there, which
   * reads as a bug because it is one. Presence knows the moment the socket goes (it's how the
   * call tears their peer down), so this follows the roster useVoice keeps.
   *
   * Every way out is the same event here: hanging up, closing the tab, being disconnected by a
   * moderator, or walking into a different Side Space.
   */
  function watchMembers() {
    if (stopMemberWatch) return

    const { memberIds } = useVoice()

    stopMemberWatch = roomScope.run(() => watch(memberIds, (ids) => {
      const here = new Set(ids)

      // Forget the ones who left, so that coming back counts as arriving again. Done for
      // *everyone* off the roster rather than only those we could draw, or somebody who left
      // before their first whisper would never be able to arrive.
      for (const id of knownFaces) if (!here.has(id)) knownFaces.delete(id)

      keepOnly(o => here.has(o.id))
    }))
  }

  /** Drop anybody who has gone quiet — a tab that died without presence noticing yet. */
  function prune() {
    const cutoff = Date.now() - STALE_AFTER

    keepOnly(o => o.at >= cutoff)
  }

  /**
   * Take away everybody who fails the test, and see them out with a puff of light.
   *
   * The one place an occupant is removed, which is why it's shared by the two paths that do it:
   * presence noticing a socket go, and the sweep catching a tab that died without it. Both are
   * "they're not here any more", and both should look the same to somebody watching the room.
   *
   * Same shallow-ref discipline as before: only write when somebody actually went, or a sweep
   * every four seconds would undo the point of the shallow ref.
   */
  function keepOnly(test: (o: RemoteOccupant) => boolean) {
    const staying: Record<number, RemoteOccupant> = {}
    const going: RemoteOccupant[] = []

    for (const o of Object.values(others.value)) {
      if (test(o)) staying[o.id] = o
      else going.push(o)
    }

    if (!going.length) return

    others.value = staying

    for (const o of going) {
      knownFaces.delete(o.id)
      emitRoom({
        kind: 'depart',
        id: o.id,
        name: o.name,
        // Where they actually were, not where they were heading: they never got there.
        x: o.x,
        y: o.y,
        facing: o.facing,
        look: normaliseLook(o.look),
        self: false,
      })
    }
  }

  // --- attaching (lasts as long as you're in the room) ---

  function subscribe() {
    if (!echo || attachedTo === channelId) return

    // Attached to a *different* room — you walked from one Side Space into another. The call
    // moved itself (useVoice leaves the old channel on connect), but this state wouldn't, and
    // the leftovers would be the previous room's occupants standing about in the new one's
    // geometry. Clear the old room out before taking up the new one.
    if (attachedTo !== null) unsubscribe()

    attachedTo = channelId

    // The call already joined this presence channel (see useVoice.connect); asking Echo for it
    // again returns that same subscription rather than opening a second one. Which is the
    // point — movement and the WebRTC handshake are two conversations on one channel.
    channel = echo.join(`voice.${channelId}`)
      .listenForWhisper('sp-move', onMove)
      // The two things people do *to each other* in a room, on the same channel and for the
      // same reason: membership of it is exactly what "is in this room" means, so neither needs
      // an authorisation rule of its own.
      .listenForWhisper('sp-hand', onHand)
      .listenForWhisper('sp-emote', onEmote)

    /*
     * Being summoned, which is the one thing here that arrives from the *server*.
     *
     * A second subscription, on `channel.{id}` rather than the call's presence channel, and the
     * asymmetry is the point: everything above is a whisper, believed because a whisper about
     * your own avatar can only be about your own avatar. A summon is a claim about *somebody
     * else's*, so it may not be a whisper — a forged one would drag the room around, and the
     * only thing standing between "staff may do this" and "anyone may" is that it goes through
     * an endpoint that checks. See App\Http\Requests\SideSpace\SummonSpaceRequest.
     *
     * Not `echo.leave()`d on the way out: useMessages and useSpaceMap share this channel and own
     * tearing it down, so unsubscribe below drops only this listener.
     */
    summonChannel = echo.private(`channel.${channelId}`)
    summonChannel.listen('.SideSpaceSummoned', onSummoned)

    // Presence is what actually says somebody left; the sweep below is only for a tab that died
    // without the socket noticing.
    watchMembers()

    /*
     * Everybody already on the presence channel was here before we were.
     *
     * `seed` marks the roster the *entry* path hands over, but there is a second way in with no
     * roster at all: a reload, where useVoice restores the call from the server and the stage
     * merely reattaches. Without this, the first whisper from each of the six people standing
     * about would read as six people walking in — a welcome party for a room you never left.
     */
    for (const id of useVoice().memberIds.value) knownFaces.add(id)

    pruneTimer = setInterval(prune, 4000)
    // The idle heartbeat lives on its own clock rather than in the render loop, because the
    // render loop stops when the stage unmounts and you are still standing in the room. This
    // is what keeps you visible to everybody else while you're off reading another channel.
    idleTimer = setInterval(() => {
      if (me.value && Date.now() - lastWhisperAt >= IDLE_WHISPER_EVERY) whisperMove(true)
    }, 500)

    // A closed tab never gets to run an await, so the last position rides the one request the
    // browser promises to finish — the same keepalive trick useVoice leaves the call with.
    persistOnUnload = () => {
      if (!me.value) return

      fetch(`${config.public.apiBase}/api/channels/${channelId}/space/position`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token.value}`,
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          x: Math.round(me.value.x),
          y: Math.round(me.value.y),
          facing: me.value.facing,
          space_map: mapSlug(),
        }),
        keepalive: true,
      }).catch(() => {})
    }
    window.addEventListener('pagehide', persistOnUnload)
  }

  /** Walk out: tear the lot down and forget where everybody was. */
  function unsubscribe() {
    // Only our own whisper handler. useVoice owns this channel and will `leave()` it when the
    // call ends — pulling it out from under the handshake here would kill the audio.
    channel?.stopListeningForWhisper?.('sp-move')
    channel?.stopListeningForWhisper?.('sp-hand')
    channel?.stopListeningForWhisper?.('sp-emote')
    channel = null
    // Not echo.leave() — useMessages and useSpaceMap share this one and own tearing it down.
    summonChannel?.stopListening?.('.SideSpaceSummoned')
    summonChannel = null
    attachedTo = null

    stopMemberWatch?.()
    stopMemberWatch = undefined

    clearInterval(pruneTimer)
    clearInterval(idleTimer)
    pruneTimer = undefined
    idleTimer = undefined

    if (persistOnUnload) window.removeEventListener('pagehide', persistOnUnload)
    persistOnUnload = undefined

    unbindKeys()

    // Write the final position now, while we still can do it properly.
    lastPersistAt = 0
    persist()

    held.clear()
    // Nobody's hand is held on the way out, and nothing is whispered about it: the room you're
    // letting go of is a room you have already stopped whispering into.
    approaching = null
    handOffer.value = null
    // A summon belongs to the room you were summoned in. Walking out of it ends it — which is
    // also the follower's way out of one, and the reason this needs no expiry of its own.
    following.value = null
    snapPending = false
    // Cleared directly rather than through `keepOnly`: walking out of a room is not everybody
    // in it vanishing, and seeing it off with twelve puffs of light would be a firework display
    // for an empty stage. Nobody is drawing this room a frame from now anyway.
    others.value = {}
    knownFaces.clear()
    me.value = null
    moving.value = false
    placed = false
  }

  // --- binding (lasts only while the room is on screen) ---

  function bindKeys() {
    if (keysBound) return
    keysBound = true

    window.addEventListener('keydown', onKeyDown)
    window.addEventListener('keyup', onKeyUp)
    window.addEventListener('blur', releaseKeys)
  }

  function unbindKeys() {
    if (!keysBound) return
    keysBound = false

    window.removeEventListener('keydown', onKeyDown)
    window.removeEventListener('keyup', onKeyUp)
    window.removeEventListener('blur', releaseKeys)
    releaseKeys()
  }

  /**
   * You changed how you look while standing in the room.
   *
   * Applied here rather than waited for on the next whisper, because the person most bothered
   * by their new hair is the one who chose it — and the forced whisper that follows means
   * everybody else sees it within the same frame or two rather than on your next step.
   */
  function restyle(look: AvatarLook, pet: PetKind | null) {
    if (!me.value) return

    me.value = {
      ...me.value,
      look,
      pet,
      petAt: me.value.petAt ?? { x: me.value.x - 0.6, y: me.value.y + 0.3, facing: me.value.facing },
    }

    whisperMove(true)
  }

  /**
   * You changed what you're shouting — or stopped.
   *
   * Separate from {@link restyle} rather than folded into it because it's a different gesture
   * with a different lifetime: a look is a costume you put on in a dialog, and a shout is a
   * thing you say and then take back, sometimes twice in a minute. Both end with a forced
   * whisper for the same reason — the room should read it now, not on your next step.
   */
  function reshout(shout: string | null) {
    if (!me.value) return

    me.value = { ...me.value, shout }
    whisperMove(true)
  }

  // --- other people ---

  /**
   * Walk over to somebody, and end up facing them.
   *
   * The pointer equivalent of "go to them": you tapped a person rather than the floor, and the
   * only sensible reading of that is that you want to be standing in front of them. Follows
   * them if they move — see {@link approaching} — and is abandoned by any of the ordinary ways
   * you take control back (a key, a tap on the floor, them leaving).
   */
  function approach(id: number) {
    if (!me.value || id === me.value.id || !others.value[id]) return

    if (me.value.seatedOn) stand()
    approaching = id
  }

  /**
   * The person on the other end of somebody's hand, or null.
   *
   * Only answers when **both** ends claim the link, which is what makes a dropped whisper draw
   * nothing rather than draw an arm reaching to somebody who has let go. It also means the
   * stage can ask this of anybody — you or a stranger — and get the same answer everyone else's
   * screen is getting, because both halves are on the wire.
   */
  function handPartnerOf(o: Occupant): Occupant | null {
    if (!o.handWith) return null

    const partner = o.handWith === me.value?.id ? me.value : others.value[o.handWith]

    return partner && partner.handWith === o.id ? partner : null
  }

  /** Whether you're currently walking over to somebody, and to whom. Drives the "stop" affordance. */
  function approachingId(): number | null {
    return approaching
  }

  /**
   * Ask to hold somebody's hand.
   *
   * An offer rather than an act, and that isn't politeness for its own sake: holding hands
   * *moves* the other person — it tows them round the room — and nothing in here should let one
   * client pull another about without being asked. So the offer goes out, their end decides,
   * and only their `accept` puts the link on either avatar.
   */
  function offerHand(id: number) {
    if (!channel || !me.value || id === me.value.id) return

    // Already holding somebody's hand. One at a time, which is what hands are.
    if (me.value.handWith) letGo()

    channel.whisper('sp-hand', { kind: 'offer', from: me.value.id, fromName: me.value.name, to: id } satisfies HandPayload)
  }

  /** Take the offered hand. Both ends set their own half of the link; see Occupant.handWith. */
  function acceptHand() {
    const offer = handOffer.value
    if (!channel || !me.value || !offer) return

    handOffer.value = null

    // The other end may have walked out in the seconds the offer sat there.
    if (!others.value[offer.id]) return

    me.value = { ...me.value, handWith: offer.id }
    channel.whisper('sp-hand', { kind: 'accept', from: me.value.id, fromName: me.value.name, to: offer.id } satisfies HandPayload)
    whisperMove(true)
  }

  /** Turn the offer down. Nothing is sent: a refusal nobody is told about is a refusal that costs nothing. */
  function declineHand() {
    handOffer.value = null
  }

  /**
   * Let go.
   *
   * Both halves are dropped: ours locally, theirs by the whisper. If the whisper is lost their
   * client will notice the gap opening past {@link HAND_BREAK} soon enough — which is why the
   * break rule exists at all, and why this needs no acknowledgement.
   */
  function letGo() {
    const partner = me.value?.handWith
    if (!me.value || !partner) return

    me.value = { ...me.value, handWith: null }
    channel?.whisper('sp-hand', { kind: 'let-go', from: me.value.id, fromName: me.value.name, to: partner } satisfies HandPayload)
    whisperMove(true)
  }

  function onHand(payload: HandPayload) {
    if (!me.value || payload.to !== me.value.id) return

    if (payload.kind === 'offer') {
      // An offer from the person whose hand you're already holding is noise, not an offer.
      if (me.value.handWith === payload.from) return

      handOffer.value = { id: payload.from, name: payload.fromName, at: Date.now() }

      return
    }

    if (payload.kind === 'accept') {
      handOffer.value = null
      me.value = { ...me.value, handWith: payload.from }
      whisperMove(true)

      return
    }

    // They let go. Only drop the link if it's *theirs* — a stale "let go" from somebody whose
    // hand you took up again since shouldn't undo the newer one.
    if (me.value.handWith === payload.from) {
      me.value = { ...me.value, handWith: null }
      whisperMove(true)
    }
  }

  /**
   * Make people follow you. Staff only — the server decides that, not this.
   *
   * `ids` omitted means everybody in the room, which is what the button in the header sends and
   * very nearly always what's wanted: the phrase is "everyone over here". Passing ids is for
   * fetching one person out of a corner.
   *
   * Notice what doesn't happen: nothing is set locally. The leader's own client learns the
   * summon landed the same way every follower does, by hearing the broadcast come back — which
   * means there is exactly one path into this state, and no way for a leader's screen to think
   * it's leading a room the server never told.
   */
  async function summon(ids: number[] | null = null) {
    await api(`/api/channels/${channelId}/space/summon`, {
      method: 'POST',
      body: { user_ids: ids, following: true },
    })
  }

  /** Let the room go. Same call, other way round — see {@link summon}. */
  async function release(ids: number[] | null = null) {
    await api(`/api/channels/${channelId}/space/summon`, {
      method: 'POST',
      body: { user_ids: ids, following: false },
    })
  }

  /**
   * Stop following, locally.
   *
   * Nothing is sent, and nothing needs to be: following is a thing *your* client does to your
   * own avatar, so ending it is entirely a local matter. The leader finds out the way they'd
   * find out anything about you — you stop arriving.
   */
  function unfollow() {
    following.value = null
    snapPending = false
  }

  /**
   * Arrive beside the leader at once.
   *
   * This is what makes a summon *a summon* rather than an invitation to walk. It runs the moment
   * the broadcast lands, not from the frame loop, and that placement is the whole point: the
   * render loop stops when the stage unmounts, so somebody who is standing in the room while
   * reading another channel has nothing walking them. A snap needs no loop — it is one position
   * and one whisper — so it reaches the people the walk cannot.
   *
   * Not onto the leader's own tile: {@link nearestFloor} spirals outwards, so a room summoned at
   * once arrives as a ring around them rather than as a single stack of avatars.
   *
   * Returns whether it landed. It can fail twice over — no map yet, or a leader who hasn't
   * whispered since we arrived — and both are moments rather than states, which is what
   * {@link snapPending} is for.
   */
  function snapToLeader(): boolean {
    const m = map.value
    const lead = following.value
    const self = me.value
    if (!m || !lead || !self) return false

    const leader = others.value[lead.id]
    if (!leader) return false

    snapPending = false

    // Already standing with them. Yanking somebody a tile sideways to a spot they were as good
    // as in reads as the room glitching, not as being called over.
    if (Math.hypot(leader.x - self.x, leader.y - self.y) <= followGap(lead.id)) return true

    const spot = nearestFloor(m, leader)

    warp(spot.x, spot.y)

    return true
  }

  function onSummoned(payload: {
    leader_id: number
    leader_name: string
    user_ids: number[] | null
    following: boolean
  }) {
    if (!me.value) return

    // Addressed to everybody, or to you by name. A null list is the room.
    if (payload.user_ids !== null && !payload.user_ids.includes(me.value.id)) return

    // The leader hears their own summon come back, as everyone on the channel does. Following
    // yourself is a loop that would stand you still and look like a bug.
    if (payload.leader_id === me.value.id) return

    if (!payload.following) {
      // A release from somebody who isn't the one holding you is stale — a second member of
      // staff calling off a summon of their own that ended a moment ago.
      if (following.value?.id === payload.leader_id) unfollow()

      return
    }

    // Being towed by two people at once is being torn in half. The newest summon wins, which is
    // also the one whose leader is most likely still looking at the room.
    following.value = { id: payload.leader_id, name: payload.leader_name }

    // A hand and a summon pull the same avatar towards two different people. Whoever has you
    // has you: the hand goes, and the person who was holding it is told properly.
    if (me.value.handWith) letGo()

    /*
     * Arrive now, and walk from here on.
     *
     * A summon is answered rather than accepted, so it should read as being *called over* — you
     * are there, and then you keep up. Walking the whole way was the earlier behaviour and it
     * failed twice: at any real distance it's a long trudge across a room you didn't choose to
     * cross, and for anybody whose stage isn't currently drawn there is no frame loop to walk
     * them at all, so a summon simply never landed.
     *
     * The follow that comes after is what makes this different from a plain teleport: you stay
     * with them as they move, which is the thing that was actually asked for.
     */
    snapPending = true
    snapToLeader()
  }

  /**
   * Pull a face.
   *
   * Fire-and-forget, and shown on your own screen first rather than waiting to hear your own
   * whisper come back — which it never would, since a whisper doesn't echo to its sender. The
   * *reason* this exists as its own whisper rather than a field on the position is in
   * {@link file://../lib/spaceEmotes.ts}: an emote is an event, and a field would have it
   * arriving twelve times a second for as long as it lasted.
   */
  function emote(glyph: string) {
    if (!me.value) return

    useSpaceChatBubbles().noteEmote(me.value.id, glyph)
    channel?.whisper('sp-emote', { id: me.value.id, glyph } satisfies EmotePayload)
  }

  function onEmote(payload: EmotePayload) {
    if (payload.id === user.value?.id) return

    useSpaceChatBubbles().noteEmote(payload.id, payload.glyph)
  }

  /**
   * Watch people come and go. Returns its own undo — call it when you stop drawing the room.
   *
   * A listener rather than a queue of events to poll, because the thing that notices an arrival
   * (a whisper, a presence event) and the thing that draws one (the stage's frame loop) run on
   * completely different clocks, and a queue between them would need somebody to remember to
   * empty it even when nothing is on screen.
   */
  function onRoomEvent(listener: (event: RoomEvent) => void): () => void {
    roomListeners.add(listener)

    return () => roomListeners.delete(listener)
  }

  return {
    me,
    others,
    moving,
    attached,
    place,
    restyle,
    reshout,
    warp,
    seed,
    tick,
    walkTo,
    stopWalking,
    sit,
    stand,
    seated,
    isWalking,
    approach,
    approachingId,
    handPartnerOf,
    handOffer,
    offerHand,
    acceptHand,
    declineHand,
    letGo,
    following,
    summon,
    release,
    unfollow,
    emote,
    subscribe,
    unsubscribe,
    onRoomEvent,
    bindKeys,
    unbindKeys,
    releaseKeys,
  }
}
