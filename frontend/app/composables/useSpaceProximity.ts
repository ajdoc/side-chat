import type { Ref } from 'vue'
import type { Occupant, SpaceMap } from '~/lib/spaceMapEngine'
import { audibility, inConnectRange } from '~/lib/spaceMapEngine'

/**
 * The shortest gap between two evaluations, in milliseconds — and, in the absence of anything
 * else asking, how often one happens.
 *
 * A person walks at {@link SPEED} tiles a second, so this is a fifteenth of a tile of movement:
 * fast enough that walking up to somebody and being heard is one action rather than two, and fast
 * enough that the volume ramp on somebody walking away is a fade rather than a staircase. Also
 * cheap — the work is a distance per occupant of one room, not per user of the app.
 */
const EVALUATE_EVERY = 60

/** Everything the decision needs, handed over by the stage as accessors. */
interface Ctx {
  map: Ref<SpaceMap | null>
  /** Where we're standing. */
  me: () => Occupant | null
  /** Everybody else, by id, with `tx`/`ty` as their whispered position. */
  others: () => Record<number, Occupant & { tx: number, ty: number }>
  /** Everyone on the presence channel, dialled or not — including people we've yet to place. */
  knownMembers: () => Array<{ id: number }>
  setPeerProximity: (id: number, gain: number, offset?: { x: number, y: number }) => void
  setPeerInRange: (id: number, inRange: boolean) => void
  fireEffect: (kind: 'join' | 'leave', id: number, name: string) => void
  /** A game meeting: the whole room hears the whole room, whatever the distance. */
  meeting: () => boolean
  /** A game is on, and arrival chimes would be a tell rather than an affordance. */
  quiet: () => boolean
}

/** Who we can hear right now. The roster and the call dock render from this. */
const audibleIds = ref<number[]>([])
/** Who we could hear last time, so arrivals and departures can be noticed rather than polled. */
const wasAudible = new Set<number>()

let ctx: Ctx | null = null
let timer: ReturnType<typeof setInterval> | undefined
let lastEvaluatedAt = 0

/**
 * Tell the call how near everybody is — the step that makes the room audible.
 *
 * Runs over `knownMembers()` (everyone on the presence channel) rather than `peers` (everyone
 * we have a connection to), and that distinction is the feature: somebody we have *no*
 * connection to is precisely who we might need to dial, and asking `peers` would mean never
 * noticing them walk up.
 */
function evaluate() {
  if (!ctx) return

  lastEvaluatedAt = Date.now()

  const m = ctx.map.value
  const self = ctx.me()
  if (!m || !self) return

  const others = ctx.others()
  const audible: number[] = []
  // In a meeting the room argues face to face: everyone hears everyone, so distance stops
  // deciding volume and every connection is held open. The rest of this is unchanged, which
  // is why it's a flag on the gain rather than a mode switch on the call.
  const meeting = ctx.meeting()
  const quiet = ctx.quiet()

  for (const member of ctx.knownMembers()) {
    const them = others[member.id]
    // On the channel but never yet heard from — no position, so nothing to measure. They'll
    // be picked up on their first whisper, which is at most a twelfth of a second away.
    if (!them) continue

    // The whispered position, not the eased one. See the note above.
    const at = { x: them.tx, y: them.ty }

    const gain = meeting ? 1 : audibility(m, self, at)
    // Direction as well as loudness, so spatial audio can put a voice where its owner is
    // standing. Measured from the whispered positions for the same reason the gain is: both
    // ends computing from the truth is what keeps the two sides' answers in step.
    ctx.setPeerProximity(member.id, gain, { x: at.x - self.x, y: at.y - self.y })
    ctx.setPeerInRange(member.id, meeting ? true : inConnectRange(m, self, at))

    if (gain > 0) {
      audible.push(member.id)

      // An arrival worth a fanfare is one you can actually hear — not one somewhere in the
      // building. This is why useVoice suppresses its own join effects in proximity mode.
      //
      // Silenced entirely during a game: a meeting flips the whole room to full volume at
      // once, which otherwise reads as everyone arriving together and greets you with a chime
      // per person (and a matching burst when the meeting ends). The bookkeeping below still
      // runs, so nothing bursts when the game is over either.
      if (!wasAudible.has(member.id) && !quiet) ctx.fireEffect('join', member.id, them.name)
    }
    else if (wasAudible.has(member.id) && !quiet) {
      ctx.fireEffect('leave', member.id, them.name)
    }
  }

  wasAudible.clear()
  for (const id of audible) wasAudible.add(id)

  // Only when the set actually changes — this runs many times a second.
  if (audible.length !== audibleIds.value.length || audible.some(id => !audibleIds.value.includes(id))) {
    audibleIds.value = audible
  }
}

/**
 * Somebody's position just arrived. Work it out now rather than at the next tick.
 *
 * The interval above is a heartbeat, not the mechanism — and it is a heartbeat a browser is
 * allowed to slow down: a hidden tab throttles timers to once a second, and one hidden long
 * enough with no audio playing can be throttled far harder than that. Which is precisely the
 * case that matters, because a hidden tab with nothing audible is a person nobody has walked up
 * to *yet*.
 *
 * A whisper, on the other hand, is a socket message: it wakes the tab to deliver it, and this
 * runs on the back of that delivery. So proximity is re-decided as fast as positions arrive,
 * whatever the timers are doing. Throttled to the same interval so a busy room doesn't evaluate
 * once per whisper per person.
 */
export function proximityMoved() {
  if (!ctx) return
  if (Date.now() - lastEvaluatedAt < EVALUATE_EVERY) return

  evaluate()
}

/**
 * Who is near enough to hear, and how loudly — evaluated on its own clock.
 *
 * ## Why this isn't in the render loop any more
 *
 * It was, and that was the bug behind every complaint about the room being slow to notice
 * people. `requestAnimationFrame` doesn't run when there's nothing to draw:
 *
 *   - **Backgrounded tab.** Chrome pauses rAF outright in a hidden tab. The call keeps running,
 *     the whispers keep arriving, and proximity simply stops being computed — so whoever you
 *     could hear when you switched tabs is who you can still hear when you come back, however
 *     far they've walked in between, and nobody who walked up to you meanwhile was ever dialled.
 *   - **The stage unmounted.** Standing in a room outlives looking at it: click away to read
 *     another channel and the call continues by design. But the frame loop is the component's,
 *     and it goes with it — freezing the room's audio in whatever shape it had at that moment.
 *
 * Both are the same shape of failure, and the fix is the same: proximity belongs to the
 * *session*, like the peer connections and the positions it reads, so it lives at module scope
 * on an interval, backed up by {@link proximityMoved} for the cases a browser is allowed to slow
 * that interval down. An unmounted stage doesn't touch either of them.
 *
 * The stage still owns *drawing* distance — the fade on a far-off sprite is recomputed per frame
 * straight from {@link audibility}, because that genuinely is only interesting when visible.
 *
 * ## Positions, not sprites
 *
 * Remote occupants carry two positions: `x`/`y`, eased towards the target each frame so they
 * glide instead of teleporting, and `tx`/`ty`, the last place they actually said they were.
 * Decisions here read the *target*. The eased position is a rendering convenience that lags by
 * design (and doesn't move at all while the tab is hidden); the whispered one is the truth, and
 * both ends of a pair computing from the truth is what keeps the two sides' answers in step.
 */
export function useSpaceProximity() {
  /**
   * Start deciding who can hear whom, and keep deciding until we leave the room.
   *
   * Takes its inputs as functions rather than calling the composables itself: this runs from a
   * click handler and from an interval, neither of which is a component setup, and the stage
   * already holds every piece.
   */
  function startProximity(next: Ctx) {
    ctx = next

    // Once immediately — walking in shouldn't wait out an interval before anybody is audible.
    evaluate()

    if (timer) return
    timer = setInterval(evaluate, EVALUATE_EVERY)
  }

  function stopProximity() {
    if (timer) clearInterval(timer)
    timer = undefined
    ctx = null
    wasAudible.clear()
    audibleIds.value = []
  }

  return { audibleIds, startProximity, stopProximity }
}
