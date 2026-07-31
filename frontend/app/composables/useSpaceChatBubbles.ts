import { useLocalStorage } from '@vueuse/core'

/**
 * How long a said line hangs over somebody's head, and the window it grows across.
 *
 * A bubble is read at a glance from across a room, so it can't be tied to reading *speed* the
 * way a toast is — but a three-word line and a full sentence genuinely don't take the same time
 * to take in. So it's a floor plus a little per character, capped: past the cap you're no longer
 * reading a bubble, you're reading the chat, and the chat is right there.
 */
const SAY_MIN = 4500
const SAY_PER_CHAR = 90
const SAY_MAX = 12_000

/**
 * How long a typing bubble survives its last whisper.
 *
 * Matched to useTyping's own TTL, and for the same reason: nobody sends a "stopped" when they
 * close the tab mid-word, so the bubble has to expire on its own. Slightly longer than the
 * 2s re-announce interval so a bubble never blinks between two whispers.
 */
const TYPING_TTL = 4000

/**
 * The most of a message a bubble ever holds.
 *
 * A ceiling on the *text*, not on the drawing: the room wraps and ellipsises what it's given
 * (see drawSpeech), and this keeps a pasted essay from being measured word by word 60 times a
 * second to arrive at the same three lines.
 */
export const BUBBLE_MAX_CHARS = 140

interface Bubble {
  kind: 'typing' | 'said'
  /** Empty for `typing`, which draws dots rather than text. */
  text: string
  /** When it expires — see bubbleFor, which is the only thing that checks. */
  until: number
}

/*
 * Module scope, like the room state in useSpacePresence and for the same reason: a bubble
 * belongs to the *room*, not to whichever component is drawing it. The stage reads these in its
 * draw loop, the people rail reads the mutes, and ChannelView is what feeds them — three
 * components, one set of facts.
 */
const bubbles = ref<Record<number, Bubble>>({})

/**
 * Whether you want to see bubbles at all.
 *
 * Yours alone and remembered: some people read a walkable room as a room and want the chatter in
 * it, and some want the chat in the chat. Nobody else is told either way.
 */
const enabled = useLocalStorage('side-space:bubbles', true)

/**
 * People whose bubbles you've turned off, by user id.
 *
 * Deliberately the same shape as the per-person volume mute next to it in the people rail: it
 * changes only what *you* see, the person is never told, and everybody else still sees them
 * perfectly well. Stored as an array because that's what localStorage can hold; the lookups
 * below go through a Set-free `includes` since the list is a handful of ids at most.
 */
const mutedIds = useLocalStorage<number[]>('side-space:bubbles-muted', [])

function sayDuration(text: string) {
  return Math.min(SAY_MAX, SAY_MIN + text.length * SAY_PER_CHAR)
}

/**
 * Chat, over people's heads, in a walkable room.
 *
 * Two things are shown: that somebody is typing (a bubble of dots, raised by the same whispers
 * that drive "Alice is typing…" under the composer) and what they then said, for a few seconds.
 * Neither is a new stream — {@link useTyping} and {@link useMessages} are already listening on
 * the channel, and ChannelView hands what they hear to this. Subscribing a second time from the
 * stage would have meant two sets of whisper handlers on one Echo channel, where tearing either
 * one down takes the other with it.
 *
 * Nothing here is persisted or sent. A bubble is a fact about the last few seconds, and the
 * message it came from is in the timeline underneath, which is the thing that keeps.
 */
export function useSpaceChatBubbles() {
  /** They're typing — re-raised on every whisper, so it lasts as long as they keep going. */
  function noteTyping(id: number) {
    const now = Date.now()
    const existing = bubbles.value[id]

    // A line they've just said outranks the fact they're typing the next one: it has something
    // to read in it, and it's about to expire on its own anyway.
    if (existing?.kind === 'said' && existing.until > now) return

    bubbles.value = { ...bubbles.value, [id]: { kind: 'typing', text: '', until: now + TYPING_TTL } }
  }

  /** They stopped without sending — the composer cleared, or the tab went away. */
  function forgetTyping(id: number) {
    if (bubbles.value[id]?.kind !== 'typing') return

    const { [id]: _gone, ...rest } = bubbles.value
    bubbles.value = rest
  }

  /** They said something. Replaces their typing bubble, which is what it was leading to. */
  function noteSaid(id: number, text: string) {
    const line = text.trim()
    if (!line) return

    const now = Date.now()
    bubbles.value = {
      ...bubbles.value,
      [id]: { kind: 'said', text: line.slice(0, BUBBLE_MAX_CHARS), until: now + sayDuration(line) },
    }
  }

  /**
   * What to draw over this person right now, or null.
   *
   * Expiry is checked here rather than swept on a timer: the room redraws 60 times a second and
   * asks this for every occupant on every frame, so a bubble that has run out is already
   * guaranteed to be noticed the instant it does. The map is only pruned when something else
   * writes to it, which keeps a per-frame read free of reactive writes — see `others` in
   * useSpacePresence for the same rule.
   */
  function bubbleFor(id: number): Bubble | null {
    if (!enabled.value || mutedIds.value.includes(id)) return null

    const b = bubbles.value[id]
    if (!b || b.until <= Date.now()) return null

    return b
  }

  function isMuted(id: number) {
    return mutedIds.value.includes(id)
  }

  function toggleMuted(id: number) {
    mutedIds.value = isMuted(id)
      ? mutedIds.value.filter(x => x !== id)
      : [...mutedIds.value, id]
  }

  /** Walking out of the room. Nothing here outlives the visit. */
  function clearBubbles() {
    bubbles.value = {}
  }

  return { enabled, noteTyping, forgetTyping, noteSaid, bubbleFor, isMuted, toggleMuted, clearBubbles }
}
