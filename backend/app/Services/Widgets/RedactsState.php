<?php

namespace App\Services\Widgets;

use App\Http\Resources\WidgetResource;
use App\Models\User;
use App\Models\Widget;

/**
 * A handler whose state isn't the same for everyone looking at it.
 *
 * Widget state is normally passed through verbatim (see {@see WidgetResource}),
 * which is fine when every field is public — a queue, a board, a tally. Two kinds of state
 * can't work that way:
 *
 *  - a *secret*: Skribbl's word must reach the drawer and nobody else, and "the client hides
 *    it" is not hiding it;
 *  - a *server-side reference*: the video widget stores an uploaded clip's disk path, which
 *    no browser may see, and swaps it for a short-lived signed URL on the way out.
 *
 * Implementing this lets a handler rewrite the state a given viewer receives *before* it
 * leaves the server. The widget comes along because a handler may need its identity to build
 * that replacement (a signed URL names the widget it belongs to) — the state alone doesn't
 * carry it.
 */
interface RedactsState
{
    /**
     * The state as this viewer is allowed to see it. Must not mutate the passed-in array.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forViewer(Widget $widget, array $state, ?User $viewer): array;
}
