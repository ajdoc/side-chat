/**
 * A line-based three-way merge — the piece that stops a Side Space note from losing text when
 * two people type in it at once.
 *
 * The note is saved whole, so without this a slower save simply flattens a faster one and
 * somebody's paragraph vanishes. Given the body both editors started from (`base`) and the two
 * bodies that grew out of it, {@link merge3} rebuilds one body that keeps both sets of edits:
 * each side's untouched regions stay, each side's changed regions apply, and where the two
 * genuinely changed the *same* lines it keeps both versions one after the other rather than
 * picking a winner. Noisy in that rare case, but never destructive — and a shared note is a
 * place where two half-sentences beat one deleted paragraph. (Conflict markers would be worse:
 * this is prose people read, not source someone resolves.)
 *
 * Framework-free and pure, like {@link file://./whiteboardEngine.ts whiteboardEngine} — plain
 * strings in, one string out, so it can be reasoned about (and tested) on its own.
 */

/** Above this many cells the LCS table isn't worth building; see {@link diffChunks}. */
const MAX_LCS_CELLS = 4_000_000

/** A replacement of `base[start, end)` with `lines`, in one side's version of the text. */
interface Chunk {
  start: number
  end: number
  lines: string[]
}

function splitLines(text: string): string[] {
  return text.split('\n')
}

/**
 * The runs of `base` that `other` replaced, in order and non-overlapping.
 *
 * Common head and tail lines are peeled off first — the usual shape of an edit is a change in
 * the middle of an otherwise identical document, and peeling keeps the quadratic LCS table
 * small. If what's left is still too big to align line by line, the whole middle is reported
 * as one replacement: a coarser merge, never a wrong one.
 */
function diffChunks(base: string[], other: string[]): Chunk[] {
  let head = 0
  while (head < base.length && head < other.length && base[head] === other[head]) head++

  let tail = 0
  while (
    tail < base.length - head
    && tail < other.length - head
    && base[base.length - 1 - tail] === other[other.length - 1 - tail]
  ) tail++

  const a = base.slice(head, base.length - tail)
  const b = other.slice(head, other.length - tail)
  if (!a.length && !b.length) return []
  if (!a.length || !b.length || a.length * b.length > MAX_LCS_CELLS) {
    return [{ start: head, end: head + a.length, lines: b }]
  }

  // Longest common subsequence over the differing middle, as a DP table of lengths.
  const w = b.length + 1
  const lcs = new Int32Array((a.length + 1) * w)
  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      lcs[i * w + j] = a[i] === b[j]
        ? lcs[(i + 1) * w + j + 1]! + 1
        : Math.max(lcs[(i + 1) * w + j]!, lcs[i * w + j + 1]!)
    }
  }

  // Walk the table, gathering each run of non-matching lines into one chunk.
  const chunks: Chunk[] = []
  let i = 0
  let j = 0
  let pending: Chunk | null = null
  const flush = () => {
    if (pending) chunks.push(pending)
    pending = null
  }
  while (i < a.length || j < b.length) {
    if (i < a.length && j < b.length && a[i] === b[j]) {
      flush()
      i++
      j++
      continue
    }
    pending ??= { start: head + i, end: head + i, lines: [] }
    if (j < b.length && (i >= a.length || lcs[i * w + j + 1]! >= lcs[(i + 1) * w + j]!)) {
      pending.lines.push(b[j]!) // an inserted line
      j++
    } else {
      pending.end = head + i + 1 // a deleted (or replaced) line
      i++
    }
  }
  flush()

  return chunks
}

/** The text one side has for `base[start, end)`, given that side's chunks. */
function regionOf(base: string[], chunks: Chunk[], start: number, end: number): string[] {
  const out: string[] = []
  let i = start
  for (const c of chunks) {
    // `<` / `>`, not `<=` / `>=`: a pure insertion is an empty range, and one sitting exactly
    // on a region's edge belongs to it (the region walk absorbs it there and nowhere else).
    if (c.end < start || c.start > end) continue
    out.push(...base.slice(i, c.start))
    out.push(...c.lines)
    i = c.end
  }
  out.push(...base.slice(Math.max(i, start), end))
  return out
}

/**
 * Merge two independent edits of `base` into one body.
 *
 * Neither side's text is dropped: a region only one side touched takes that side's version,
 * and a region both touched keeps `mine` followed by the parts of `theirs` that aren't already
 * there. Identical edits collapse to one copy, so the common case — two people who both
 * received the same broadcast — doesn't duplicate anything.
 */
export function merge3(base: string, mine: string, theirs: string): string {
  if (mine === theirs) return mine
  if (base === mine) return theirs
  if (base === theirs) return mine

  const b = splitLines(base)
  const mineChunks = diffChunks(b, splitLines(mine))
  const theirChunks = diffChunks(b, splitLines(theirs))

  const out: string[] = []
  let cursor = 0 // next base line not yet emitted
  let m = 0
  let t = 0

  while (m < mineChunks.length || t < theirChunks.length) {
    const next = m < mineChunks.length && t < theirChunks.length
      ? Math.min(mineChunks[m]!.start, theirChunks[t]!.start)
      : (mineChunks[m]?.start ?? theirChunks[t]!.start)

    // Grow the region until it covers every chunk that overlaps it on either side, so two
    // edits touching the same lines are compared as a whole rather than interleaved.
    let start = next
    let end = next
    let touchedMine = false
    let touchedTheirs = false
    for (;;) {
      let grew = false
      while (m < mineChunks.length && mineChunks[m]!.start <= end) {
        start = Math.min(start, mineChunks[m]!.start)
        end = Math.max(end, mineChunks[m]!.end)
        touchedMine = true
        m++
        grew = true
      }
      while (t < theirChunks.length && theirChunks[t]!.start <= end) {
        start = Math.min(start, theirChunks[t]!.start)
        end = Math.max(end, theirChunks[t]!.end)
        touchedTheirs = true
        t++
        grew = true
      }
      if (!grew) break
    }

    out.push(...b.slice(cursor, start))

    const mineLines = regionOf(b, mineChunks, start, end)
    const theirLines = regionOf(b, theirChunks, start, end)
    if (!touchedTheirs) out.push(...mineLines)
    else if (!touchedMine) out.push(...theirLines)
    else if (mineLines.join('\n') === theirLines.join('\n')) out.push(...mineLines)
    else {
      // Both rewrote these lines. Keep mine, then whatever of theirs is genuinely new.
      const seen = new Set(mineLines)
      out.push(...mineLines, ...theirLines.filter(l => l.trim() !== '' && !seen.has(l)))
    }

    cursor = end
  }

  out.push(...b.slice(cursor))

  return out.join('\n')
}
