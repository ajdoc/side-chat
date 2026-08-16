import type { VideoState } from '~/types'

/**
 * Where a watch-along should be *right now*, extrapolated from the server's snapshot.
 *
 * The video widget shares a transport rather than a stream: the server stores the position as of
 * `updated_at`, and every viewer works out the rest from its own clock. That only holds the room
 * together if all of them do the arithmetic *the same way* — two viewers with two versions of
 * "now" are two viewers who drift apart, which is exactly what a shared transport exists to
 * prevent.
 *
 * So it lives here rather than in whichever component happens to be playing. There are two such
 * players now — the widget's own, and the screens hanging on a Side Space map — and there was no
 * reason to think there would only ever be two.
 *
 * A paused room is simply at `position`: nothing has elapsed that anybody should catch up on.
 */
export function watchAlongPosition(state: Pick<VideoState, 'status' | 'position' | 'updated_at' | 'speed'> | null): number {
  if (!state) return 0

  const base = state.position ?? 0
  if (state.status !== 'playing') return base

  const elapsed = (Date.now() - Date.parse(state.updated_at)) / 1000

  // Never negative: a clock behind the server's would otherwise ask a player to seek before the
  // start of the file, which browsers answer by doing something arbitrary.
  return Math.max(0, base + elapsed * (state.speed ?? 1))
}
