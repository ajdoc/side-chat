/**
 * Resolving "how loud is this place", as plain functions over plain data.
 *
 * Pulled out of the composable so it can be tested without a Nuxt runtime — and because
 * this is the one rule in the app that exists in two codebases at once. The server runs
 * the same resolution before it sends a push (App\Services\Notifications\NotificationPolicy);
 * if the two ever disagree, muting a channel silences your phone while your laptop carries
 * on pinging, which is exactly the class of bug nobody reports and everybody resents.
 *
 * Change the order here and change it there.
 */

export type NotifyLevel = 'all' | 'mentions' | 'none'

/**
 * What a channel or a chat has to carry to be resolvable.
 *
 * Structural rather than the `Channel`/`Conversation` types, because a chat genuinely has
 * no `server_id` and no `parent_id` — and their absence is what identifies it as a chat,
 * which is the only thing the two kinds of room disagree about.
 */
export interface NotifyTarget {
  server_id?: number | null
  parent_id?: number | null
  notify_level?: NotifyLevel | null
  muted_until?: string | null
}

export interface NotifyDefaults {
  channel: NotifyLevel
  dm: NotifyLevel
}

/** The defaults a brand-new account starts on. See the users migration for the reasoning. */
export const DEFAULT_LEVELS: NotifyDefaults = { channel: 'mentions', dm: 'all' }

/** Is this place under a mute that hasn't lapsed yet? */
export function isMuted(target: NotifyTarget | null | undefined, now = Date.now()): boolean {
  if (!target?.muted_until) return false

  return new Date(target.muted_until).getTime() > now
}

/**
 * The effective level, most specific rule first.
 *
 * 1. This place's mute, if it's still running.
 * 2. This place's explicit level.
 * 3. The parent's mute, then the parent's level, when this is a discussion. Inheriting is
 *    what makes muting a channel quiet the conversations inside it without touching them.
 * 4. The account default for this kind of room.
 *
 * A null `notify_level` is "no opinion" and falls through — deliberately not the same as
 * 'all', because inheriting has to keep tracking the default as the default changes.
 */
export function resolveLevel(
  target: NotifyTarget | null | undefined,
  parent: NotifyTarget | null | undefined,
  defaults: NotifyDefaults = DEFAULT_LEVELS,
  now = Date.now(),
): NotifyLevel {
  if (!target) return 'none'

  for (const link of [target, parent]) {
    if (!link) continue
    if (isMuted(link, now)) return 'none'
    if (link.notify_level) return link.notify_level
  }

  // A DM was addressed to you by definition; a channel of two hundred people was not.
  return target.server_id ? defaults.channel : defaults.dm
}

/** Does a message of this kind clear the bar this place sets? */
export function admits(level: NotifyLevel, isMention: boolean): boolean {
  return level === 'all' || (level === 'mentions' && isMention)
}
