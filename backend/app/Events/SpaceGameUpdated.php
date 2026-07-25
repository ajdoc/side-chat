<?php

namespace App\Events;

use App\Models\SpaceGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The game moved — a vote came in, it started, somebody was killed, a meeting opened.
 *
 * Only a *reference* travels, like {@see WidgetUpdated}, and for a stronger reason than its size:
 * the game's state is redacted per viewer (who's the impostor is not the same fact for everyone),
 * so there is no one state that could ride the socket even if it fit. On this ping each client
 * GETs its *own* view from the game endpoint. Broadcasts now rather than via the queue — a game
 * a second out of date is a game where somebody has already been killed on your neighbour's
 * screen and not yet on yours.
 */
class SpaceGameUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SpaceGame $game) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->game->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'SpaceGameUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->game->channel_id,
            'type' => $this->game->type,
            'status' => $this->game->status,
        ];
    }
}
