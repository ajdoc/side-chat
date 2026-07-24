/**
 * Split an over-long body into the run of messages the API will actually accept.
 *
 * A message body is capped at 2000 characters server-side (see StoreMessageRequest), and until
 * now a pasted essay simply bounced off validation with nothing to show for it. Rather than
 * refuse the paste, the send path posts it as consecutive messages — the same thing a person
 * does by hand, minus the hand.
 *
 * The cut is made where a reader would make it: at a paragraph break if there's one in reach,
 * else a line, else the end of a sentence, else a word — a mid-word cut only when a stretch of
 * text offers nothing better. A code block cut through is closed at the foot of one part and
 * reopened at the head of the next, so neither half renders as prose with stray backticks.
 *
 * Framework-free and pure, like {@link file://./mergeText.ts mergeText} — a string in, strings
 * out, nothing to mock.
 */

/** The server's per-message cap. Keep in step with `max:2000` on the message `body` rules. */
export const MESSAGE_LIMIT = 2000

/**
 * Characters held back from each cut part for the fence that may have to close it: a newline
 * and three markers. Reserved unconditionally — whether the cut lands inside a code block isn't
 * known until after the cut is chosen, and four characters is a cheap way not to care.
 */
const FENCE_RESERVE = 4

/** ``` or ~~~ opening or closing a block, with the info string markdown allows beside it. */
const FENCE_LINE = /^ {0,3}(```|~~~)([^`~]*)$/

/**
 * Where to cut, best first. `keep` is how much of the separator stays with the part being cut
 * off — a sentence keeps its full stop, a line break belongs to neither side.
 */
const SEPARATORS = [
  { find: '\n\n', keep: 0 },
  { find: '\n', keep: 0 },
  { find: '. ', keep: 1 },
  { find: ', ', keep: 1 },
  { find: ' ', keep: 0 },
]

/**
 * How full a part must be for a separator to be worth using. Without this a lone paragraph
 * break near the top would strand 40 characters in a message of its own; below the mark we
 * fall through to a finer separator, which will be nearer the end.
 */
const MIN_FILL = 0.4

/**
 * The body as one or more parts, each within `limit` characters.
 *
 * A body that already fits comes back as a single part — including an empty one, which is what
 * a GIF-only or attachment-only send passes.
 */
export function chunkMessage(body: string, limit = MESSAGE_LIMIT): string[] {
  const text = body ?? ''
  if (text.length <= limit) return [text]

  const parts: string[] = []
  let rest = text
  let fence = '' // the opening line ("```ts") of a block the previous cut landed inside, or ''

  while (rest.length) {
    const reopen = fence ? `${fence}\n` : ''

    // Everything left fits: this is the last part, and it needs no closing fence of its own.
    if (reopen.length + rest.length <= limit) {
      parts.push(reopen + rest)
      break
    }

    // At least one character, so a pathologically small limit can't spin here forever.
    const room = Math.max(1, limit - reopen.length - FENCE_RESERVE)
    const { end, next } = breakPoint(rest, room)
    const slice = rest.slice(0, end)
    rest = rest.slice(next)

    fence = fenceAfter(slice, fence)
    parts.push(reopen + slice + (fence ? `\n${fence.slice(0, 3)}` : ''))
  }

  return parts
}

/**
 * The cut for a part of at most `room` characters: `end` ends the part, `next` starts the
 * remainder. They differ by whatever separator was consumed — the space between two words is
 * carried by neither message.
 */
function breakPoint(text: string, room: number): { end: number, next: number } {
  for (const { find, keep } of SEPARATORS) {
    // `room + 1 - keep`: a separator sitting exactly on the boundary is still usable, since
    // only the part of it we keep counts against the room.
    const at = text.slice(0, room + 1 - keep).lastIndexOf(find)
    if (at > 0 && at >= room * MIN_FILL) return { end: at + keep, next: at + find.length }
  }
  // Nothing to break on — a URL, or a wall of CJK. Step back off the tail of a surrogate pair
  // so a hard cut through an emoji doesn't leave half of one at each end.
  const hard = /[\uD800-\uDBFF]/.test(text[room - 1] ?? '') ? room - 1 : room
  return { end: hard, next: hard }
}

/**
 * The fence left open at the end of `slice`, given the one open before it: the opening line to
 * repeat at the head of the next part, or '' when the slice ends outside a code block.
 */
function fenceAfter(slice: string, before: string): string {
  let open = before
  for (const line of slice.split('\n')) {
    const match = FENCE_LINE.exec(line)
    if (match) open = open ? '' : match[1]! + match[2]!.trim()
  }
  return open
}
