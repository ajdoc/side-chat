import type { SideDeskAppId } from '~/types'

/**
 * Which surface a Side Desk app's storage actually hangs off.
 *
 * A desk can sit on a channel (`/api/channels/12`) or on a side chat (`/api/side-chats/3`), and
 * the apps do not all answer that the same way:
 *
 * - **Per-surface apps** — the board, the note, the canvas, the calendar, the doc shelf — store
 *   rows against whichever surface they're on. A side chat's whiteboard is its own whiteboard.
 * - **Channel-scoped apps** — the Tracker, the Polls wall, the Sticker Wall, and every widget —
 *   store rows against a *channel* and have no side-chat endpoints at all. On a side chat they
 *   resolve to its parent channel, which is the rule the widgets have always followed ("a side
 *   chat's Kanban tab is its parent channel's board") and the honest one: a side chat is a room
 *   inside a channel, and the channel's tracker is what its people are working from.
 *
 * Before this was written down, those three tabs pointed at `/api/side-chats/3/tracker/...` on a
 * side chat — a 404, rendered as an app that silently never loads.
 */

/** The apps whose rows belong to a channel even when the desk is on a side chat. */
export const CHANNEL_SCOPED_APPS: SideDeskAppId[] = ['tracker', 'polls', 'stickers']

/**
 * Is this base path a channel's?
 *
 * The question the *comments* layer asks: `channels/{channel}/apps/{type}/{id}/...` is the only
 * address app comments, tags, reactions and discussions have, so a panel on a side-chat-owned
 * item has nowhere to send its requests and must draw nothing instead.
 */
export function isChannelPath(basePath: string): boolean {
  return /\/api\/channels\/\d+$/.test(basePath)
}

export function channelPath(channelId: number): string {
  return `/api/channels/${channelId}`
}

export function channelStream(channelId: number): string {
  return `channel.${channelId}`
}

/**
 * The base path and stream one app should use on this desk.
 *
 * @param channelId the desk's channel — a side chat passes its *parent* channel's id, the same
 *                  id its widgets already resolve against.
 */
export function scopeFor(
  app: SideDeskAppId,
  basePath: string,
  streamName: string,
  channelId: number,
): { basePath: string, streamName: string } {
  return CHANNEL_SCOPED_APPS.includes(app)
    ? { basePath: channelPath(channelId), streamName: channelStream(channelId) }
    : { basePath, streamName }
}
