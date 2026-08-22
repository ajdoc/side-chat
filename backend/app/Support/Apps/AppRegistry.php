<?php

namespace App\Support\Apps;

use App\Models\InstalledApp;

/**
 * Every app the server knows about, and where each one is allowed to appear.
 *
 * ## Why this replaced two lists
 *
 * There were three copies of "which apps exist": `App\Support\DeskApps` (what a Side Desk strip
 * may store), `AppCatalogue` (what a channel may be), and the client's `DESK_APPS`. The first
 * two answered *different* questions over *overlapping* sets, which reads as harmless right up
 * to the point where somebody adds an app to one and not the other — as happened twice while
 * the Tracker was being built. Nothing fails loudly when a list is missed: the app simply can't
 * be added to a desk, or its channels render nowhere.
 *
 * So the sets are gone and the *flags* are here instead, one row per app, mirroring the shape
 * the client registry already had. Adding an app is one row.
 *
 * The client still owns what an app *looks* like — label, icon, card size. This owns only what
 * may be written to the database.
 *
 * ## Built-in versus installed
 *
 * The rows below ship with the app. Third-party apps live in the `installed_apps` table and are
 * merged in by {@see channelIds}, which is what makes the catalogue dynamic — a constant can't
 * grow at runtime. Everything reading this class gets both without knowing the difference.
 */
final class AppRegistry
{
    /**
     * @var array<string, array{family: 'surface'|'widget', desk: bool, channel: bool}>
     *
     * - `family` — `surface` apps own storage hanging off the surface; `widget` apps render the
     *   channel's existing Widget of that type and store nothing new.
     * - `desk` — may it be a tab on a Side Desk strip?
     * - `channel` — may it be an entire app channel?
     */
    private const BUILT_IN = [
        // The Open Canvas is never *stored* in a desk strip — the client pins it and it can't be
        // removed — but it can be a channel of its own.
        'canvas' => ['family' => 'surface', 'desk' => false, 'channel' => true],
        'board' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        'notes' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        'docs' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        'calendar' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        'tracker' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        // Polls as a whole app — a wall of them with results, reactions and comments. Distinct
        // from the `poll` *widget* below, which is the single poll card a `p!` command drops in
        // the timeline. Two different things that both deserve to exist.
        'polls' => ['family' => 'surface', 'desk' => true, 'channel' => true],
        'stickers' => ['family' => 'surface', 'desk' => true, 'channel' => true],

        /*
         * The MOBA — see MOBA.md.
         *
         * `desk` is false, and that is the interesting half. Every other surface app is
         * something you glance at beside a conversation; a MOBA is thirty minutes of undivided
         * attention. As a tab on a Side Desk strip it would be a match you cannot see, running
         * beside the notes you switched to.
         *
         * It is also the first app whose storage is not scoped to the channel: a match belongs
         * to the ten people in it and to their ratings, not to wherever it was launched from.
         * The channel is a lobby.
         */
        'moba' => ['family' => 'surface', 'desk' => false, 'channel' => true],

        // Widgets promoted to apps.
        'music' => ['family' => 'widget', 'desk' => true, 'channel' => true],
        'video' => ['family' => 'widget', 'desk' => true, 'channel' => true],
        'kanban' => ['family' => 'widget', 'desk' => true, 'channel' => true],
        /*
         * The `p!` poll card.
         *
         * `channel` is false because `polls` above *is* the poll channel, and two entries
         * called Poll and Polls was the confusion worth removing. They are one poll now — the
         * widget is a pointer at an AppPoll. See PollWidget.
         *
         * `desk` stays true, though it is no longer *offered* in the client's picker (see the
         * `deprecated` flag there). Turning it off here would 422 the next save of any desk
         * that already has the Poll tab stored, which is a live surface breaking to tidy a
         * list. Accepting a stored id costs nothing: the tab renders the widget perfectly well.
         */
        'poll' => ['family' => 'widget', 'desk' => true, 'channel' => false],
        // The games are desk tabs but never channels: a game is something a room starts, plays
        // and finishes, and a permanent channel for one would be an empty table most of the time.
        'shooter' => ['family' => 'widget', 'desk' => true, 'channel' => false],
        'racing' => ['family' => 'widget', 'desk' => true, 'channel' => false],
        'skribbl' => ['family' => 'widget', 'desk' => true, 'channel' => false],
        'poker' => ['family' => 'widget', 'desk' => true, 'channel' => false],
    ];

    /** Ids a Side Desk strip may store. */
    public static function deskIds(): array
    {
        return array_keys(array_filter(self::BUILT_IN, fn ($a) => $a['desk']));
    }

    /**
     * Ids a channel may be — built-ins plus whatever third-party apps are installed.
     *
     * Queried rather than cached in a static: an app installed in one request has to be
     * creatable in the next, and this is called once per channel creation, not per row.
     */
    public static function channelIds(): array
    {
        $built = array_keys(array_filter(self::BUILT_IN, fn ($a) => $a['channel']));

        return [...$built, ...InstalledApp::enabledSlugs()];
    }

    public static function isWidget(string $id): bool
    {
        return (self::BUILT_IN[$id]['family'] ?? null) === 'widget';
    }

    /** True for an app that isn't built in — one that came from the installed catalogue. */
    public static function isExternal(string $id): bool
    {
        return ! array_key_exists($id, self::BUILT_IN);
    }

    /**
     * The built-ins, as the client's catalogue endpoint reports them.
     *
     * Only the flags — the client already knows every built-in's label and icon, and shipping
     * those from here would be two sources for one fact.
     *
     * @return array<int, array{id: string, family: string, desk: bool, channel: bool}>
     */
    public static function builtIns(): array
    {
        return array_map(
            fn (string $id) => ['id' => $id, ...self::BUILT_IN[$id]],
            array_keys(self::BUILT_IN),
        );
    }
}
