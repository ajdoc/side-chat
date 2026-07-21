<?php

namespace App\Services\Widgets;

use App\Models\User;

/**
 * A handler whose state isn't the same for everyone looking at it.
 *
 * Widget state is normally passed through verbatim (see {@see \App\Http\Resources\WidgetResource}),
 * which is fine when every field is public — a queue, a board, a tally. A game with a
 * secret can't work that way: Skribbl's word must reach the drawer and nobody else, and
 * "the client hides it" is not hiding it. Implementing this lets a handler strip the parts
 * a given viewer isn't entitled to *before* the state leaves the server.
 */
interface RedactsState
{
    /**
     * The state as this viewer is allowed to see it. Must not mutate the passed-in array.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function forViewer(array $state, ?User $viewer): array;
}
