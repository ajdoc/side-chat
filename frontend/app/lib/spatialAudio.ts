/**
 * Where each voice comes from — the call as a room rather than a mono mix.
 *
 * ## What this is for
 *
 * A call plays every microphone at the same place: dead centre, on top of each other. That's
 * fine for two people and steadily worse after that, because separating two voices talking at
 * once is something ears do with *direction* and nothing else. Give each person a position and
 * the crosstalk problem largely dissolves — you can follow one of two simultaneous speakers
 * the way you can in a room, and "who just said that" stops needing the screen to answer.
 *
 * Two ways a position gets decided, one mechanism underneath:
 *
 * - **You place people.** In an ordinary voice channel or a DM there is no room to read a
 *   position out of, so you arrange the call yourself — drag someone to your left and they
 *   stay on your left, remembered per person, across calls. {@link arrangeInArc} lays a fresh
 *   room out automatically so it's useful before anybody has dragged anything.
 * - **The room places them.** In a Side Space people already *have* positions: you can walk up
 *   to someone. {@link placementFromOffset} turns the offset between two occupants into the
 *   same placement, so proximity audio stops being only a volume and becomes a direction —
 *   someone approaching from your left arrives on your left.
 *
 * ## The geometry
 *
 * Everything outside this file speaks in `{ angle, distance }`: `angle` in radians, 0 straight
 * ahead and growing clockwise (so π/2 is your right), `distance` 0..1 from "in your lap" to
 * "across the room". That's a listener-relative polar coordinate, which is both what the UI
 * draws (a dot on a circle with you at the middle) and what a Side Space produces naturally
 * (a bearing and a distance between two occupants). WebAudio's cartesian, y-up, -Z-forward
 * convention is a detail that lives in {@link applyPlacement} and nowhere else.
 *
 * ## Distance is ours, not the panner's
 *
 * The PannerNode has a whole distance model, and it is deliberately switched off
 * (`rolloffFactor: 0`). Loudness in this app is already decided — your per-peer volume slider,
 * and in a Side Space an audibility curve that knows about walls and sealed zones — and having
 * a second, invisible attenuation multiplied on top would make both wrong and neither
 * debuggable. So the panner does one job (direction) and gain does the other.
 */

/** A voice's position relative to you: a bearing, and how far off it is. */
export interface SpatialPlacement {
  /** Radians. 0 is straight ahead, π/2 your right, π behind you, -π/2 your left. */
  angle: number
  /** 0..1. Near to far — see {@link distanceGain} for what it costs in loudness. */
  distance: number
}

/** Where somebody sits before anyone has moved them: ahead, at a conversational distance. */
export const DEFAULT_PLACEMENT: SpatialPlacement = { angle: 0, distance: 0.5 }

/**
 * How wide the automatic arrangement spreads, in radians either side of centre.
 *
 * Not the full circle, and not by accident. Directly behind you is where HRTF panning is at its
 * least convincing (front/back confusion is a real limit of doing this over speakers or on
 * generic head-related filters), and a voice you perceive as behind you is faintly alarming in
 * a way nobody asked for. A 150° arc in front is the same shape as people sat round a table.
 */
const ARC = (Math.PI / 180) * 75

/** The radius, in WebAudio's arbitrary units, that `distance` 0..1 maps onto. */
const NEAR_UNITS = 0.6
const FAR_UNITS = 6

/**
 * How far away a Side Space occupant has to be to sit at the far edge of your personal room.
 *
 * Tiles, matched to the audibility falloff the stage already uses: by the time someone is this
 * far off they are nearly inaudible anyway, so the mapping saturates exactly where the volume
 * curve stops caring.
 */
const SPACE_FAR_TILES = 10

/** Can this browser place a voice at all? Everything below degrades to plain stereo without it. */
export function spatialSupported(): boolean {
  return typeof window !== 'undefined'
    && (typeof PannerNode !== 'undefined' || typeof StereoPannerNode !== 'undefined')
}

/**
 * Lay a call out across the arc in front of you: first voice to the left, last to the right.
 *
 * Sorted by id rather than by arrival, so the arrangement is *stable* — the same call laid out
 * the same way every time, and someone reconnecting after a dropout lands back where they were
 * rather than shuffling everybody else along. A room that rearranges itself while you're
 * listening to it is worse than one that never arranged itself at all.
 */
export function arrangeInArc(ids: number[]): Map<number, SpatialPlacement> {
  const placements = new Map<number, SpatialPlacement>()
  const sorted = [...ids].sort((a, b) => a - b)

  sorted.forEach((id, i) => {
    // One voice goes dead ahead rather than at the left edge of the arc.
    const t = sorted.length === 1 ? 0.5 : i / (sorted.length - 1)
    placements.set(id, { angle: -ARC + t * ARC * 2, distance: 0.45 })
  })

  return placements
}

/**
 * Turn "they are 3 tiles east and 1 north of me" into a placement.
 *
 * Screen space, so `dy` grows *downwards* — someone below you on the map is behind you in the
 * room, which is why the bearing is measured from -y. The stage's own facing isn't folded in:
 * the listener is treated as always facing up the screen, because that's the frame the player
 * is actually looking at, and rotating the sound field under someone who turned their sprite
 * around is disorienting rather than immersive.
 */
export function placementFromOffset(dx: number, dy: number, farTiles = SPACE_FAR_TILES): SpatialPlacement {
  const tiles = Math.hypot(dx, dy)
  // Standing on the same square is the one case with no direction to speak of — centre them
  // rather than letting floating-point noise flick a voice from ear to ear.
  if (tiles < 0.01) return { angle: 0, distance: 0 }

  return {
    angle: Math.atan2(dx, -dy),
    distance: Math.min(1, tiles / farTiles),
  }
}

/**
 * The loudness cost of distance, for the placements *you* make.
 *
 * A gentle curve, not an inverse square: this is a preference dial for laying out a
 * conversation, and someone you dragged to the far edge should sound further away, not
 * inaudible. In a Side Space this is bypassed entirely — the room's own audibility curve,
 * which knows about walls, is the authority on how loud a distant person is.
 */
export function distanceGain(distance: number): number {
  return 1 - 0.45 * Math.min(1, Math.max(0, distance))
}

/** One person's voice, positioned. Created per peer, torn down with them. */
export interface SpatialVoice {
  /** Move them. Ramped rather than jumped — a position that steps is a click. */
  setPlacement: (placement: SpatialPlacement) => void
  /** Their loudness: your volume × mute × whatever the room says. 0 is silence. */
  setGain: (gain: number) => void
  /** Unhook everything. Does not touch the stream or the <audio> element. */
  destroy: () => void
}

/** Ramp constant for both position and gain — long enough not to click, short enough to track. */
const RAMP_SECONDS = 0.06

function applyPlacement(
  ctx: AudioContext,
  node: PannerNode | StereoPannerNode,
  { angle, distance }: SpatialPlacement,
) {
  const at = ctx.currentTime

  if (!(node instanceof StereoPannerNode)) {
    const radius = NEAR_UNITS + (FAR_UNITS - NEAR_UNITS) * Math.min(1, Math.max(0, distance))
    // The listener faces -Z with +X to their right (WebAudio's convention), so an angle
    // measured clockwise from straight ahead is (sin, -cos) — the only place this file's
    // polar convention meets WebAudio's cartesian one.
    const x = Math.sin(angle) * radius
    const z = -Math.cos(angle) * radius

    // positionX/Y/Z are AudioParams and can be ramped; the deprecated setPosition() cannot,
    // and older Safari has only that. Both are here because a click on every movement is
    // exactly what a Side Space, which moves people sixty times a second, would produce.
    if (node.positionX) {
      node.positionX.linearRampToValueAtTime(x, at + RAMP_SECONDS)
      node.positionY.linearRampToValueAtTime(0, at + RAMP_SECONDS)
      node.positionZ.linearRampToValueAtTime(z, at + RAMP_SECONDS)
    } else {
      node.setPosition(x, 0, z)
    }

    return
  }

  // The fallback is a stereo pan and nothing more: no elevation, no front/back, no HRTF.
  // `sin(angle)` collapses front and behind onto the same pan, which is the honest thing a
  // left/right fader can express.
  node.pan.linearRampToValueAtTime(Math.max(-1, Math.min(1, Math.sin(angle))), at + RAMP_SECONDS)
}

/**
 * Build the positioned playback path for one peer's microphone.
 *
 * Returns null when it can't — no audio track yet, no WebAudio, a context that's been closed —
 * and every caller treats that as "play them the ordinary way". A call that is merely not
 * spatial is a working call.
 *
 * ## The muted element that must stay
 *
 * The <audio> element carrying this stream is *not* removed when this takes over; it's muted
 * and left in the document. That looks redundant and isn't: in Chromium a remote WebRTC
 * MediaStream feeding a MediaStreamAudioSourceNode goes silent unless the stream is also
 * attached to a live media element (a long-standing bug — the element is what keeps the
 * receiver's audio flowing). So the element stays, contributing nothing audible, as the pump.
 */
export function createSpatialVoice(
  ctx: AudioContext,
  stream: MediaStream,
  placement: SpatialPlacement,
): SpatialVoice | null {
  if (!stream.getAudioTracks().length || ctx.state === 'closed') return null

  try {
    const source = ctx.createMediaStreamSource(stream)
    const gain = ctx.createGain()

    let panner: PannerNode | StereoPannerNode
    if (typeof PannerNode !== 'undefined' && ctx.createPanner) {
      const p = ctx.createPanner()
      // HRTF rather than 'equalpower': the whole point is externalisation — a voice that
      // sounds like it is *in the room* rather than inside the left headphone — and that
      // needs the head-shadow filtering, not a volume difference between the two ears.
      p.panningModel = 'HRTF'
      p.distanceModel = 'inverse'
      p.refDistance = 1
      p.maxDistance = 100
      // Distance is applied as gain by the caller, deliberately — see the file header.
      p.rolloffFactor = 0
      panner = p
    } else {
      panner = ctx.createStereoPanner()
    }

    source.connect(panner).connect(gain).connect(ctx.destination)
    applyPlacement(ctx, panner, placement)

    return {
      setPlacement: p => applyPlacement(ctx, panner, p),
      setGain: (value) => {
        // Ramped for the same reason as position: in a Side Space this moves continuously,
        // and a gain that steps between frames is audible as a buzz on every footstep.
        gain.gain.linearRampToValueAtTime(Math.max(0, value), ctx.currentTime + RAMP_SECONDS)
      },
      destroy: () => {
        for (const node of [source, panner, gain]) {
          try {
            node.disconnect()
          } catch {
            // context already closed; nothing left to unhook
          }
        }
      },
    }
  } catch {
    return null
  }
}

/**
 * Put the listener at the origin, facing forward.
 *
 * Called once per context. Every placement is computed relative to you, so the listener never
 * moves — walking around a Side Space moves the *voices* instead. That's the same sound field
 * either way and it keeps one head of arithmetic out of the hot path.
 */
export function centreListener(ctx: AudioContext) {
  const l = ctx.listener
  try {
    if (l.positionX) {
      l.positionX.value = 0
      l.positionY.value = 0
      l.positionZ.value = 0
      l.forwardX.value = 0
      l.forwardY.value = 0
      l.forwardZ.value = -1
      l.upX.value = 0
      l.upY.value = 1
      l.upZ.value = 0
    } else {
      l.setPosition(0, 0, 0)
      l.setOrientation(0, 0, -1, 0, 1, 0)
    }
  } catch {
    // A listener we can't orient is one the browser has its own defaults for, which are these.
  }
}
