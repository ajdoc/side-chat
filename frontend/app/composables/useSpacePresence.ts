import type { Facing, Occupant, SpaceMap } from '~/lib/spaceMapEngine'
import type { AvatarLook } from '~/lib/spaceAvatar'
import type { PetKind } from '~/lib/spacePets'
import { facingOf, isWalkable, spawnPoint, step } from '~/lib/spaceMapEngine'
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
}

type RemoteOccupant = Occupant & { tx: number, ty: number, at: number }

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
let keysBound = false
let lastWhisperAt = 0
let lastPersistAt = 0
let pruneTimer: ReturnType<typeof setInterval> | undefined
let idleTimer: ReturnType<typeof setInterval> | undefined
let persistOnUnload: (() => void) | undefined
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
      // Standing beside you rather than on you, so it's visible the moment you walk in.
      petAt: { x: at.x - 0.6, y: at.y + 0.3, facing },
    }
    placed = true
    whisperMove(true)
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
    me.value = { ...me.value, x, y }
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
  }>) {
    if (!map.value) return

    const next = { ...others.value }

    for (const o of occupants) {
      if (o.id === user.value?.id || o.x === null || o.y === null) continue

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
    steer = { x, y }
  }

  /** Stop wherever we are, abandoning a pointer target. For the buttons that take movement away. */
  function stopWalking() {
    steer = null
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
    interpolate(dt)
    movePets(m, dt)
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

  function whisperMove(force = false) {
    if (!channel || !me.value) return

    const now = Date.now()
    if (!force && now - lastWhisperAt < WHISPER_EVERY) return
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
    } satisfies MovePayload)
  }

  function onMove(payload: MovePayload) {
    if (payload.id === user.value?.id) return

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
        look: normaliseLook(payload.look),
        pet: payload.pet ?? null,
        petAt: { x: payload.x - 0.6, y: payload.y + 0.3, facing: payload.facing },
        at: Date.now(),
      },
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
      body: { x: Math.round(me.value.x), y: Math.round(me.value.y), facing: me.value.facing },
    }).catch(() => {})
  }

  /** Drop anybody who has gone quiet — a tab that died without presence noticing yet. */
  function prune() {
    const cutoff = Date.now() - STALE_AFTER
    const alive = Object.fromEntries(
      Object.entries(others.value).filter(([, o]) => o.at >= cutoff),
    )

    // Only if somebody actually went: reassigning every four seconds regardless would undo
    // the point of the shallow ref.
    if (Object.keys(alive).length !== Object.keys(others.value).length) others.value = alive
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
    channel = echo.join(`voice.${channelId}`).listenForWhisper('sp-move', onMove)

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
    channel = null
    attachedTo = null

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
    others.value = {}
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

  return {
    me,
    others,
    moving,
    attached,
    place,
    restyle,
    warp,
    seed,
    tick,
    walkTo,
    stopWalking,
    isWalking,
    subscribe,
    unsubscribe,
    bindKeys,
    unbindKeys,
    releaseKeys,
  }
}
