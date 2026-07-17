<?php

namespace App\Events;

use App\Http\Resources\LinkPreviewResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message's link previews finished unfurling.
 *
 * Separate from MessageUpdated on purpose: unfurling is not an edit (it must not flip
 * the "(edited)" marker), it happens seconds after the message lands, and it carries
 * only the previews — so it can't race a real edit and clobber the body.
 */
class MessagePreviewsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('linkPreviews');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->message->streamName())];
    }

    public function broadcastAs(): string
    {
        return 'MessagePreviewsUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->message->id,
            'link_previews' => LinkPreviewResource::collection(
                $this->message->linkPreviews->filter->isRenderable()->values()
            )->resolve(),
        ];
    }
}
