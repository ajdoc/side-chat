<?php

namespace App\Services\Widgets;

/**
 * What a handler wants done after processing a command or action, in terms the
 * {@see WidgetService} knows how to carry out — without the handler ever touching the
 * database or the broadcaster.
 *
 * - `changed`   → the widget's state was mutated; persist it and push `WidgetUpdated` so
 *                 every open card re-renders in place.
 * - `resurface` → drop a fresh card at the bottom of the timeline (first use of a widget,
 *                 or an explicit `m!queue` / `k!list`).
 * - `reply`     → a one-off note shown only to the person who ran the command (help text,
 *                 "no player is running", "card #3 not found"). Never persisted, never
 *                 seen by anyone else — the ephemeral reply Discord slash-commands give.
 */
final readonly class WidgetOutcome
{
    private function __construct(
        public bool $changed,
        public bool $resurface,
        public ?string $reply,
    ) {}

    /** State changed; update every open card in place, no new card. (e.g. `m!pause`, `k!done`) */
    public static function updated(): self
    {
        return new self(changed: true, resurface: false, reply: null);
    }

    /** State changed and it's worth a fresh card at the bottom. (e.g. `m!p`, `k!add`) */
    public static function card(): self
    {
        return new self(changed: true, resurface: true, reply: null);
    }

    /** Nothing changed; just re-surface the current card. (e.g. `m!queue`, `k!list`) */
    public static function show(): self
    {
        return new self(changed: false, resurface: true, reply: null);
    }

    /** Say something to the actor alone; leave the widget as it was. (help, errors) */
    public static function reply(string $text): self
    {
        return new self(changed: false, resurface: false, reply: $text);
    }

    /** Nothing happened — don't touch state, don't broadcast, don't post. */
    public static function noop(): self
    {
        return new self(changed: false, resurface: false, reply: null);
    }
}
