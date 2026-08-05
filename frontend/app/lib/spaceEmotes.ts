/**
 * Emotes: a face you pull, over your head, for a couple of seconds.
 *
 * The smallest thing a person in a room can say, and deliberately the *only* thing in the app
 * that says it two ways at once. One glyph over an avatar's head is fed from two places:
 *
 *   - you picked one from the emote bar in a Side Space, which whispers it to the room;
 *   - you reacted to somebody's message, and the reaction you just added pops over *your* head
 *     as well as onto the message. Reacting is already an emote — it was only ever drawn in one
 *     of the two places you were looking.
 *
 * Which is why nothing here is persisted or has an id. A reaction is the thing that keeps; this
 * is the couple of seconds during which everybody in the room saw you make it. See
 * {@link file://../composables/useSpaceChatBubbles.ts}, which draws it, and the `sp-emote`
 * whisper in {@link file://../composables/useSpacePresence.ts}, which carries it.
 */

export interface Emote {
  /** What goes over your head. One grapheme — the bubble draws it as a single glyph. */
  glyph: string
  /** What the button says it does. */
  label: string
}

/**
 * The bar.
 *
 * Twelve, and no more: an emote is picked at a glance while you're standing in front of
 * somebody, and a grid you have to *read* is a grid you'd have been quicker typing into the
 * chat. Anything outside this set is still reachable — react to a message with it and it pops
 * over your head exactly the same way.
 */
export const EMOTES: Emote[] = [
  { glyph: '👋', label: 'Wave' },
  { glyph: '😂', label: 'Laugh' },
  { glyph: '❤️', label: 'Love it' },
  { glyph: '👍', label: 'Yes' },
  { glyph: '👎', label: 'No' },
  { glyph: '🎉', label: 'Party' },
  { glyph: '😮', label: 'Shocked' },
  { glyph: '😢', label: 'Sad' },
  { glyph: '🤔', label: 'Thinking' },
  { glyph: '👏', label: 'Applause' },
  { glyph: '🔥', label: 'Fire' },
  { glyph: '💤', label: 'Asleep' },
]

/**
 * How long one hangs about.
 *
 * Shorter than a said line, because there is nothing to read: an emote is taken in the instant
 * it appears, and the rest of its life is only there so somebody who glanced away doesn't miss
 * it entirely.
 */
export const EMOTE_MS = 2600

/**
 * The size an emote is rasterised at before the room scales it down.
 *
 * The reason a bubble emoji looked soft: colour emoji are *bitmap* glyphs, and `fillText` at a
 * font size the font has no strike for gets the nearest one resampled — usually upward, from a
 * cache entry the browser picked, and at a fractional size that changes every frame while the
 * pop animates. Nothing about the canvas' devicePixelRatio transform helps, because the
 * blurring happens inside the text rasteriser.
 *
 * So it's drawn once, big, into an offscreen canvas, and the room `drawImage`s that. Scaling a
 * 128px glyph *down* to 30 is the case bilinear filtering is good at, and the source is
 * rasterised exactly once per emoji however many people are pulling the same face.
 */
const EMOJI_PX = 128

/** glyph → the offscreen canvas holding it. Unbounded, but bounded in practice by the keyboard. */
const emojiCache = new Map<string, HTMLCanvasElement>()

/**
 * One emoji, rendered large on a transparent square, ready to be drawn scaled down.
 *
 * Square and centred, so callers can treat it as a sprite with a known anchor rather than
 * having to measure text. Returns null on the server, where there is no canvas and nothing is
 * being drawn anyway.
 */
export function emojiSprite(glyph: string): HTMLCanvasElement | null {
  if (!import.meta.client) return null

  const held = emojiCache.get(glyph)
  if (held) return held

  const canvas = document.createElement('canvas')
  canvas.width = EMOJI_PX
  canvas.height = EMOJI_PX

  const ctx = canvas.getContext('2d')
  if (!ctx) return null

  ctx.font = `${Math.round(EMOJI_PX * 0.8)}px "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", system-ui, sans-serif`
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText(glyph, EMOJI_PX / 2, EMOJI_PX / 2 + EMOJI_PX * 0.04)

  emojiCache.set(glyph, canvas)

  return canvas
}

/**
 * Is this message *only* an emote?
 *
 * What decides whether a line is drawn as a glyph over somebody's head or as a speech bubble,
 * and whether the timeline shows it big. Deliberately strict: a few emoji and nothing else. A
 * sentence with a thumbs-up in it is a sentence, and blowing it up to four times the size
 * because of its punctuation would be the sort of cleverness that's wrong twice a day.
 *
 * Written against Unicode properties rather than a range list — `Extended_Pictographic` is
 * exactly "is this an emoji", and the two joiners are what hold multi-part ones (👩‍👩‍👦, flags,
 * skin tones) together.
 */
const EMOTE_ONLY = /^(?:\p{Extended_Pictographic}|\p{Regional_Indicator}|\p{Emoji_Modifier}|\p{Emoji_Component}|[‍️\s])+$/u
/** A flag is two regional indicators and no pictograph at all, so it needs naming separately. */
const HAS_PICTURE = /\p{Extended_Pictographic}|\p{Regional_Indicator}/u

export function emoteOnly(body: string): string | null {
  const line = body.trim()
  if (!line || line.length > 24 || !EMOTE_ONLY.test(line)) return null

  // A line of whitespace, digits and joiners with no actual picture in it is not an emote —
  // `Emoji_Component` includes the ASCII digits (they're half of 1️⃣), so "123" gets this far.
  return HAS_PICTURE.test(line) ? line : null
}

/**
 * The most of a reaction we'll try to draw.
 *
 * Reactions are free text on the wire — a custom emoji shortcode, a flag made of two code
 * points, or in principle a paragraph. The bubble draws one glyph, so anything longer than a
 * couple of code points is not an emote and simply doesn't pop.
 */
export function asEmote(emoji: string): string | null {
  const glyph = emoji.trim()
  if (!glyph) return null

  return [...glyph].length <= 4 ? glyph : null
}
