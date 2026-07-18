<?php

namespace App\Services\Widgets;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Events\WidgetUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Widget;
use App\Support\Commands\CommandParser;
use App\Support\Commands\ParsedCommand;

/**
 * The one place widgets meet the database, the broadcaster and the timeline.
 *
 * Handlers ({@see WidgetHandler}) are pure state machines that don't know those things
 * exist; this turns a {@see WidgetOutcome} they return into the real effects — save the
 * state, push `WidgetUpdated` so open cards re-render, and either drop a fresh card in the
 * timeline or send the actor a private note. Every widget command and every card action
 * flows through here, which is what keeps "what does `changed` mean" answered in exactly
 * one file.
 */
final class WidgetService
{
    /** @var array<string, WidgetHandler> type => handler */
    private array $handlers;

    public function __construct(MusicWidget $music, KanbanWidget $kanban, ShooterWidget $shooter, RacingWidget $racing)
    {
        $this->handlers = [
            $music->type() => $music,
            $kanban->type() => $kanban,
            $shooter->type() => $shooter,
            $racing->type() => $racing,
        ];
    }

    public function handlerForType(string $type): ?WidgetHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * Run a parsed chat command and return the message that should answer it — a fresh
     * card, the existing one, or a private ephemeral note. Called from the send path.
     */
    public function handleCommand(Channel $channel, User $user, ParsedCommand $command): Message
    {
        $type = CommandParser::NAMESPACES[$command->namespace] ?? null;
        $handler = $type !== null ? $this->handlerForType($type) : null;
        if ($handler === null) {
            return $this->ephemeral($channel, $user, 'Unknown command.');
        }

        $widget = $this->widgetFor($channel, $user, $handler);
        $outcome = $handler->command($widget, $user, $command);

        return $this->apply($widget, $user, $outcome);
    }

    /**
     * Run a card action (a button, a drag). Unlike a command it never posts to the
     * timeline — it only ever nudges state and re-syncs the open cards.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleAction(Widget $widget, User $user, string $action, array $payload): void
    {
        $handler = $this->handlerForType($widget->type);
        if ($handler === null) {
            return;
        }

        $outcome = $handler->action($widget, $user, $action, $payload);
        if ($outcome->changed) {
            $widget->save();
            broadcast(new WidgetUpdated($widget));
        }
    }

    /** The channel's widget of this kind, created (with the handler's initial state) on first use. */
    private function widgetFor(Channel $channel, User $user, WidgetHandler $handler): Widget
    {
        $widget = Widget::firstOrCreate(
            ['channel_id' => $channel->id, 'type' => $handler->type()],
            ['user_id' => $user->id, 'state' => $handler->initialState()],
        );
        $widget->setRelation('channel', $channel);

        return $widget;
    }

    private function apply(Widget $widget, User $user, WidgetOutcome $outcome): Message
    {
        if ($outcome->changed) {
            $widget->save();
            broadcast(new WidgetUpdated($widget));
        }

        if ($outcome->reply !== null) {
            return $this->ephemeral($widget->channel, $user, $outcome->reply);
        }

        if ($outcome->resurface) {
            return $this->createCard($widget, $user);
        }

        // A control command (m!pause, k!done): the effect is the WidgetUpdated patch above.
        // Hand back the existing card so the sender's client re-surfaces it, not a new one.
        return $this->latestCard($widget) ?? $this->createCard($widget, $user);
    }

    /** Drop a fresh widget card at the bottom of the timeline and announce it. */
    private function createCard(Widget $widget, User $user): Message
    {
        $message = $widget->channel->messages()->create([
            'user_id' => $user->id,
            'type' => 'widget',
            'widget_id' => $widget->id,
            'body' => null,
        ]);

        $message->load('user', 'widget');

        broadcast(new MessageSent($message));
        // Light the unread badge for anyone not looking at this channel — same as a message.
        broadcast(new ChannelActivity($message));

        return $message;
    }

    private function latestCard(Widget $widget): ?Message
    {
        $card = $widget->cards()->where('type', 'widget')->latest('id')->first();
        $card?->load('user', 'widget');

        return $card;
    }

    /**
     * A note only the actor sees — help text, "no player is running", "no card #3".
     *
     * Deliberately unsaved and unbroadcast: it exists only in the HTTP response the sender
     * gets back, so their client shows it and a reload forgets it. The negative id keeps it
     * from ever colliding with a real message row.
     */
    private function ephemeral(Channel $channel, User $user, string $body): Message
    {
        $message = new Message([
            'channel_id' => $channel->id,
            'user_id' => $user->id,
            'body' => $body,
            'type' => 'system',
        ]);
        $message->id = -(int) round(microtime(true) * 1000);
        $message->created_at = now();
        $message->updated_at = now();
        $message->setRelation('user', $user);

        return $message;
    }
}
