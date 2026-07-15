<?php

namespace App\Events;

use App\Http\Resources\UserResource;
use App\Models\ChannelRead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Someone read further in a channel — moves their seen-by avatar down the timeline. */
class ChannelReadUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ChannelRead $read)
    {
        $this->read->loadMissing('user');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->read->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'ChannelReadUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channel_id' => $this->read->channel_id,
            'user' => (new UserResource($this->read->user))->resolve(),
            'last_read_message_id' => $this->read->last_read_message_id,
            'read_at' => $this->read->read_at,
        ];
    }
}
