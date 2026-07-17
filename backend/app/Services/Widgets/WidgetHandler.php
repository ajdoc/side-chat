<?php

namespace App\Services\Widgets;

use App\Models\Widget;
use App\Models\User;
use App\Support\Commands\ParsedCommand;

/**
 * One kind of widget's brain: it owns the shape and rules of a widget's `state` and
 * nothing else. It mutates the passed-in {@see Widget} in memory and returns a
 * {@see WidgetOutcome} saying what should follow; persistence, broadcasting and card
 * creation are the {@see WidgetService}'s job, not the handler's.
 */
interface WidgetHandler
{
    /** The `type` this handles — matches Widget::$type and the command namespace's mapping. */
    public function type(): string;

    /** The state a freshly-created widget of this kind starts life with. */
    public function initialState(): array;

    /** Handle a chat command (`m!p …`, `k!add …`). */
    public function command(Widget $widget, User $user, ParsedCommand $command): WidgetOutcome;

    /**
     * Handle a UI action from the card (a button, a drag) — the non-typed counterpart of
     * a command.
     *
     * @param  array<string, mixed>  $payload
     */
    public function action(Widget $widget, User $user, string $action, array $payload): WidgetOutcome;
}
