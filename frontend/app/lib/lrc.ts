/**
 * LRC parsing for the karaoke view.
 *
 * LRC is the lyrics format LRCLIB serves: one line per row, prefixed with one or more
 * `[mm:ss.xx]` stamps marking when it's sung. A row may carry several stamps when the same
 * words recur (a chorus), and files often open with `[ar:…]`/`[ti:…]` metadata rows that
 * look like stamps but aren't.
 *
 * The parser's job is to flatten all that into a plain list sorted by time, so the pane can
 * binary-search "which line is the room on?" against the widget's shared clock.
 */

export interface LyricLine {
  /** Seconds from the start of the track. */
  time: number
  /** The words. Empty for the instrumental gaps LRC marks with a stamp and nothing else. */
  text: string
}

/** `[mm:ss.xx]`, `[mm:ss.xxx]` or `[mm:ss]` — the fractional part is optional. */
const STAMP = /\[(\d{1,3}):(\d{1,2})(?:[.:](\d{1,3}))?\]/g

/**
 * `[offset:+250]` — a whole-file timing correction in milliseconds, which the transcriber
 * sets when their stamps run ahead of or behind the recording.
 *
 * By the prevailing convention a *positive* offset means the lyrics should appear *earlier*,
 * so it's subtracted from every stamp.
 */
const OFFSET = /^\s*\[offset:\s*([+-]?\d+)\s*\]/im

/**
 * Parse an LRC document into time-sorted lines.
 *
 * Returns an empty array for anything unparseable, which the caller reads as "not synced"
 * and falls back to plain text for.
 */
export function parseLrc(lrc: string): LyricLine[] {
  const lines: LyricLine[] = []
  const offset = Number(lrc.match(OFFSET)?.[1] ?? 0) / 1000

  for (const raw of lrc.split(/\r?\n/)) {
    STAMP.lastIndex = 0
    const stamps: number[] = []
    let match: RegExpExecArray | null
    let end = 0

    // Stamps are only stamps while they're still leading the row — once real words start,
    // a later `[…]` is lyric text (an ad-lib, a section marker), not a new timestamp.
    while ((match = STAMP.exec(raw)) !== null) {
      if (match.index !== end) break
      end = STAMP.lastIndex
      const [, min, sec, frac] = match
      // A 2-digit fraction is centiseconds, a 3-digit one milliseconds — pad, don't guess.
      const fraction = frac ? Number(frac.padEnd(3, '0')) / 1000 : 0
      stamps.push(Number(min) * 60 + Number(sec) + fraction)
    }

    if (!stamps.length) continue
    const text = raw.slice(end).trim()
    // Clamp at zero: an offset can push the opening lines negative, and a lyric that starts
    // "before" the track would never be reachable by the highlight.
    for (const time of stamps) lines.push({ time: Math.max(0, time - offset), text })
  }

  return lines.sort((a, b) => a.time - b.time)
}

/**
 * Index of the line being sung at `position` seconds, or -1 before the first one.
 *
 * Called on every tick for every viewer, so it binary-searches rather than scanning: the
 * answer is the last line whose stamp has passed.
 */
export function activeLineIndex(lines: LyricLine[], position: number): number {
  let lo = 0
  let hi = lines.length - 1
  let found = -1

  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    if (lines[mid]!.time <= position) {
      found = mid
      lo = mid + 1
    } else {
      hi = mid - 1
    }
  }

  return found
}

/** Turn unsynced plain lyrics into the same shape, with no timings to highlight against. */
export function plainToLines(plain: string): LyricLine[] {
  return plain.split(/\r?\n/).map(text => ({ time: -1, text: text.trim() }))
}
