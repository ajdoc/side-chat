/**
 * Arriving and leaving, drawn in the room.
 *
 * ## Why this isn't the effect the rest of the app has
 *
 * A voice channel already has entrance effects — fireworks, confetti, sparkles — fired by
 * useVoice and painted over the whole window by the `VoiceEffects` overlay. In a Side Space that
 * overlay is the wrong instrument for two reasons, and they're both about *place*:
 *
 *   1. A room has somewhere for an effect to happen. An arrival is something that occurred at a
 *      tile, six paces to your left, and a firework across the entire window says only that it
 *      occurred at all.
 *   2. A room is busy. People wander in and out constantly, and a full-window effect per person
 *      is a strobe. Something a tile and a half wide is legible at a glance and ignorable at a
 *      glance, which is what you want from something that fires this often.
 *
 * So the room draws its own, in its own idiom: a ring of light on the floor and a scatter of
 * motes, at the tile it happened on. The configured fanfare still fires for people coming into
 * *earshot* — see useSpaceProximity — which is a different and rarer event.
 *
 * ## The two shapes
 *
 * Arriving reads upward and outward: the ring springs open, the motes rise and fade. Leaving is
 * the same thing run backwards and downwards — the ring closes in, the motes fall, and the person
 * themself lingers for a moment as a fading sprite, because a body that simply blinked out would
 * read as a dropped frame rather than as somebody walking off.
 *
 * Everything is derived from one number: `t`, the effect's age from 0 to 1. No per-particle state,
 * no allocation per frame, and the scatter is hashed from the particle's index so it's the same
 * scatter every frame — the same trick the ground detail uses to keep from shimmering.
 */

import type { AvatarLook } from './spaceAvatar'
import type { Facing } from './spaceMapEngine'
import { drawTrainer, spriteHue } from './spaceAvatar'
import { tileNoise } from './pixelSprite'

/** How long one lives, in milliseconds. Long enough to notice, short enough not to queue up. */
export const EFFECT_MS = 750

export type RoomEffectKind = 'arrive' | 'depart'

/** One effect, mid-flight. Everything needed to draw it after the person has gone. */
export interface RoomEffectInstance {
  kind: RoomEffectKind
  id: number
  /** Whoever it was, for the caption. */
  name: string
  x: number
  y: number
  facing: Facing
  look: AvatarLook
  /** Your own arrival is drawn a shade stronger — it's the one you're meant to feel. */
  self: boolean
  /** `performance.now()` when it started. */
  born: number
}

/** How many motes fly out. Eight reads as a burst; sixteen reads as noise at this size. */
const MOTES = 8

/**
 * Paint one effect.
 *
 * `px`/`py` is the person's feet in canvas pixels — the same anchor a sprite gets, so the ring
 * lands on the floor they were standing on rather than around their head. `t` is 0…1.
 */
export function drawRoomEffect(
  ctx: CanvasRenderingContext2D,
  effect: RoomEffectInstance,
  px: number,
  py: number,
  size: number,
  t: number,
  strength = 1,
): void {
  const arriving = effect.kind === 'arrive'
  // Arriving runs forwards, leaving runs backwards: one progress value, read from either end,
  // which is what makes the two effects unmistakably the same effect.
  const p = arriving ? t : 1 - t
  // `strength` is earshot: the room already draws a distant person faint, and an arrival that
  // ignored that would be brighter than the figure it happens to. Never zero — something at the
  // far end of the room is worth a glimmer, or the room looks empty while people move through it.
  const fade = (1 - t) * strength

  ctx.save()

  // The person, for a departure. Drawn first so the motes fly over them, and lifted slightly as
  // they fade so the exit reads as dissolving upwards rather than sinking through the floor.
  if (!arriving) {
    ctx.globalAlpha = fade * 0.9
    drawTrainer(ctx, { facing: effect.facing }, px, py - size * 0.25 * t, size, {
      look: effect.look,
      hue: spriteHue(effect.id),
      self: effect.self,
      walking: false,
      phase: 0,
    })
  }

  // The ring on the floor. Flattened to an ellipse because the room is drawn from slightly
  // above — a circle here would read as a bubble standing on its edge.
  const radius = size * (0.2 + p * 0.55)

  ctx.globalAlpha = fade * (effect.self ? 0.95 : 0.75)
  ctx.beginPath()
  ctx.ellipse(px, py + size * 0.3, radius, radius * 0.42, 0, 0, Math.PI * 2)
  ctx.strokeStyle = arriving ? 'rgb(129 140 248)' : 'rgb(148 163 184)'
  ctx.lineWidth = Math.max(1.5, size * 0.07 * fade)
  ctx.stroke()

  // A second ring a beat behind the first, so the burst has depth rather than being one hoop.
  if (p > 0.25) {
    ctx.globalAlpha = fade * 0.35
    ctx.beginPath()
    ctx.ellipse(px, py + size * 0.3, radius * 0.6, radius * 0.25, 0, 0, Math.PI * 2)
    ctx.stroke()
  }

  // The motes: out and up on arrival, in and down on departure. Square, not round, because
  // everything else in this room is drawn on a pixel grid and a circle would look imported.
  const mote = Math.max(2, size * 0.09)

  for (let i = 0; i < MOTES; i++) {
    // Hashed from the index alone, so a given effect's scatter is identical every frame.
    const angle = (i / MOTES) * Math.PI * 2 + tileNoise(i, effect.id) * 0.6
    const reach = size * (0.5 + tileNoise(effect.id, i) * 0.5)
    const spread = arriving
      // Eased out: fast at the moment of arrival, drifting by the end.
      ? (1 - (1 - t) * (1 - t)) * reach
      // Eased in: gathering slowly, then snapping shut.
      : (1 - t) * (1 - t) * reach

    const rise = arriving ? -t * size * 0.5 : (1 - t) * size * 0.35

    ctx.globalAlpha = fade * 0.9
    ctx.fillStyle = arriving ? 'rgb(199 210 254)' : 'rgb(203 213 225)'
    ctx.fillRect(
      px + Math.cos(angle) * spread - mote / 2,
      py + size * 0.3 + Math.sin(angle) * spread * 0.45 + rise - mote / 2,
      mote,
      mote,
    )
  }

  ctx.restore()
}

/**
 * The name of whoever it was, under the effect.
 *
 * Only for other people: you know your own name, and "You arrived" is a caption on a thing you
 * just did. Drawn on the same dark plate a person's name gets, for the same reason — a room's
 * floor is patterned, and plain text on it is unreadable about half the time.
 */
export function drawRoomEffectLabel(
  ctx: CanvasRenderingContext2D,
  effect: RoomEffectInstance,
  px: number,
  py: number,
  size: number,
  t: number,
  strength = 1,
): void {
  if (effect.self) return

  const label = `${effect.name ?? ''}${effect.kind === 'arrive' ? ' arrived' : ' left'}`.trim()
  if (!label) return

  ctx.save()
  // Fades out over the back half, so an arrival's caption isn't still sitting there while the
  // person it names walks away.
  ctx.globalAlpha = Math.min(1, (1 - t) * 2) * strength
  ctx.font = `500 ${Math.max(9, size * 0.26)}px system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'

  const width = ctx.measureText(label).width
  const pad = size * 0.14
  const y = py - size * (0.95 + t * 0.3)

  ctx.fillStyle = 'rgb(0 0 0 / 0.5)'
  ctx.beginPath()
  ctx.roundRect(px - width / 2 - pad, y - size * 0.2, width + pad * 2, size * 0.4, 4)
  ctx.fill()

  ctx.fillStyle = '#ffffff'
  ctx.fillText(label, px, y)
  ctx.restore()
}
