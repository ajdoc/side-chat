<?php

namespace App\Jobs;

use App\Events\MessagePreviewsUpdated;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Services\LinkPreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Unfurls one URL off the request path — the sender shouldn't wait on someone else's
 * slow server to see their own message. When it lands, the message it came from is
 * told about it over the websocket.
 */
class FetchLinkPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Comfortably above SafeUrlFetcher's own timeout × redirect hops. */
    public int $timeout = 30;

    public function __construct(
        public LinkPreview $preview,
        public Message $message,
    ) {}

    public function handle(LinkPreviewService $previews): void
    {
        $this->preview->refresh();

        // Two messages can name the same new URL at once, and both queue a fetch. By the
        // time the second one runs the first has usually filled the row in — in which
        // case there's nothing to fetch, but this message still needs telling.
        if ($this->preview->status === 'pending' || $this->preview->isStale()) {
            $previews->unfurl($this->preview);
        }

        // The message can be deleted (or edited to drop the link) while we were fetching.
        // The preview row is still worth keeping — the next message to use that URL gets
        // it for free — but there's nobody left to broadcast to.
        $message = $this->message->fresh();

        if ($message !== null) {
            broadcast(new MessagePreviewsUpdated($message->load('linkPreviews')));
        }
    }

    /** A URL we couldn't reach stays failed rather than pending, so nothing waits on it. */
    public function failed(): void
    {
        $this->preview->refresh();

        if ($this->preview->status === 'pending') {
            $this->preview->update(['status' => 'failed', 'fetched_at' => now()]);
        }
    }
}
