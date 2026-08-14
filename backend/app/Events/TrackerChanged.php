<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something in a channel's tracker changed.
 *
 * One event for the whole app rather than a class per noun (TaskSaved, TaskRemoved,
 * ProjectSaved, CommentAdded, TagAttached...). The tracker has five kinds of row that all
 * change for the same reasons, and the client's handling is identical in every case: take
 * `subject` + `action`, upsert or drop by id in the matching list. Eight event classes would
 * be eight files of the same six lines and eight listeners to register.
 *
 * Carries the already-serialised payload rather than a model, so the resource decides the
 * shape once and the socket and the HTTP response can't disagree about it.
 *
 * Rides the channel's own private stream — the same one its messages, calendar and board use
 * — which is what lets a tracker open in a tab and in a floating window stay in step. Sent
 * with `->toOthers()`, so the actor's own view is the one it already updated locally.
 */
class TrackerChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $subject  which list changed: 'project' | 'task' | 'comment' | 'tag'
     * @param  string  $action  'saved' or 'removed' — an upsert or a delete
     * @param  array<string, mixed>  $payload  the resource for a save; at minimum an `id` for a
     *                                         removal, plus whatever the client needs to find
     *                                         which list to drop it from
     */
    public function __construct(
        public string $streamName,
        public string $subject,
        public string $action,
        public array $payload,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->streamName)];
    }

    public function broadcastAs(): string
    {
        return 'TrackerChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'subject' => $this->subject,
            'action' => $this->action,
            'payload' => $this->payload,
        ];
    }
}
