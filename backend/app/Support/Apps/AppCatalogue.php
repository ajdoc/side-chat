<?php

namespace App\Support\Apps;

use App\Models\Widget;

/**
 * Which apps may *be* a channel.
 *
 * The client owns the full app registry (see `useDeskApps.ts`) — labels, icons, what can sit on
 * the Open Canvas. This is the server's much smaller half of it: the set of ids allowed in
 * `channel_apps.app_id`, so that "which apps exist" stays a client concern while "what may be
 * written to the database" stays a server one.
 *
 * Deliberately a closed list rather than a free string. An app id picks the component a channel
 * renders and, for the surface family, the endpoints it reads — an unvetted one would be a
 * channel nobody can open, created by anybody who can post JSON.
 */
final class AppCatalogue
{
    /**
     * Apps with their own per-channel storage, addressed off the channel's base path.
     *
     * These are the ones that make sense as a whole channel: things you go somewhere to work
     * in. `tracker` is the newest and the only one that was built for this slot rather than
     * adapted into it.
     */
    public const SURFACE_APPS = ['tracker', 'board', 'notes', 'calendar', 'docs', 'canvas'];

    /**
     * Widgets promoted to full channels. They store nothing new — a channel of this kind
     * renders the channel's own {@see Widget} of that type, the identical row its
     * timeline card and canvas card render.
     *
     * The games are absent on purpose: a game is something a room starts, plays and finishes,
     * and a permanent channel for one would be an empty table most of the time.
     */
    public const WIDGET_APPS = ['music', 'video', 'kanban', 'poll'];

    /** @return list<string> */
    public static function ids(): array
    {
        return [...self::SURFACE_APPS, ...self::WIDGET_APPS];
    }

    public static function has(string $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    /** True for the widget family — the ids that resolve to a Widget rather than to storage. */
    public static function isWidget(string $id): bool
    {
        return in_array($id, self::WIDGET_APPS, true);
    }
}
