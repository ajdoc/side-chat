import type { Facing, Occupant, SpaceMap } from '~/lib/spaceMapEngine'
import { facingOf, isWalkable, spawnPoint, step } from '~/lib/spaceMapEngine'

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

/** A position as it goes over the wire. Tiles, fractional, plus which way they're facing. */
interface MovePayload {
  id: number
  name: string
  avatar: string | null
  x: number
  y: number
  facing: Facing
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
  const token = useCookie<string | null>('auth_token')
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

    me.value = {
      id: user.value.id,
      name: user.value.name,
      avatar: user.value.avatar,
      x: at.x,
      y: at.y,
      facing: remembered?.facing ?? 'down',
    }
    placed = true
    whisperMove(true)
  }

  /** Someone else's remembered position, so the room is drawn right before they first move. */
  function seed(occupants: Array<{ id: number, name: string, avatar: string | null, x: number | null, y: number | null, facing: Facing | null }>) {
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
    e.preventDefault()
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
  }

  function moveSelf(m: SpaceMap, dt: number) {
    if (!me.value) return

    const dx = (held.has('arrowright') || held.has('d') ? 1 : 0) - (held.has('arrowleft') || held.has('a') ? 1 : 0)
    const dy = (held.has('arrowdown') || held.has('s') ? 1 : 0) - (held.has('arrowup') || held.has('w') ? 1 : 0)

    if (dx === 0 && dy === 0) {
      if (moving.value) moving.value = false

      return
    }

    if (!moving.value) moving.value = true

    // Normalised, so walking diagonally isn't 1.4× faster than walking straight.
    const len = Math.hypot(dx, dy)
    const distance = SPEED * dt

    const next = step(m, me.value, (dx / len) * distance, (dy / len) * distance)

    me.value = { ...me.value, ...next, facing: facingOf(dx, dy, me.value.facing) }

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
        at: Date.now(),
      },
    }
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

  return {
    me,
    others,
    moving,
    attached,
    place,
    seed,
    tick,
    isWalking,
    subscribe,
    unsubscribe,
    bindKeys,
    unbindKeys,
    releaseKeys,
  }
}
