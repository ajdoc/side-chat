<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Services\SafeUrlFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts one event to one bot's webhook.
 *
 * Off the request path, always: a send must not wait on — or fail because of — somebody
 * else's server. One job per bot per event rather than one job that loops, so a dead
 * endpoint retries on its own schedule instead of holding up the bot next to it.
 *
 * The payload arrives as a plain array rather than a Message. A queued job can run after
 * the message it describes has been edited or deleted, and a webhook that reported a
 * message's *later* state — or silently dropped the event because the row was gone — would
 * be lying about what happened. The snapshot is taken when the event fires.
 */
class DeliverBotEvent implements ShouldQueue
{
    use Queueable;

    /** Signature header, and the scheme it names. See {@see self::sign()}. */
    public const SIGNATURE_HEADER = 'X-SideChat-Signature';

    public const TIMESTAMP_HEADER = 'X-SideChat-Timestamp';

    /** Declared, not just assigned: a dynamic property would be a deprecation, not a setting. */
    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $data  The event body, already snapshotted.
     */
    public function __construct(
        public int $botId,
        public string $event,
        public array $data,
        public string $deliveryId,
    ) {
        $this->tries = (int) config('bots.webhooks.tries', 3);
    }

    /**
     * Seconds between attempts — a short one for a receiver that blipped, a long one for a
     * receiver that's being deployed.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return config('bots.webhooks.backoff', [10, 60]);
    }

    public function handle(SafeUrlFetcher $urls): void
    {
        $bot = Bot::find($this->botId);

        // Re-checked here, not just at fan-out: a bot can be retired, or its webhook
        // switched off, between the event firing and this attempt (including between
        // retries). Returning rather than failing — there's nothing wrong, there's just
        // nobody to tell any more.
        if ($bot === null || ! $bot->webhookActive()) {
            return;
        }

        $url = (string) $bot->webhook_url;
        $body = $this->body();
        $timestamp = (string) now()->getTimestamp();

        $request = Http::withHeaders([
            'Content-Type' => 'application/json',
            'User-Agent' => 'SideChatBot/1.0 (+webhooks)',
            'X-SideChat-Event' => $this->event,
            'X-SideChat-Delivery' => $this->deliveryId,
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => $this->sign($timestamp, $body, (string) $bot->webhook_secret),
        ])
            ->timeout((int) config('bots.webhooks.timeout', 5))
            ->connectTimeout((int) config('bots.webhooks.timeout', 5))
            // A redirect is not followed. Following one would mean re-running every SSRF
            // check on the new target, and a webhook receiver has no legitimate reason to
            // bounce us elsewhere — the owner can just register the real URL.
            ->withOptions(['allow_redirects' => false]);

        if (! config('bots.webhooks.allow_private_targets', false)) {
            $target = $urls->pin($url);

            if ($target === null) {
                // Not retryable, and not a transient failure of the receiver: the URL
                // itself is unusable. Counted as a failure so a bot pointed at something
                // it should never have reached eventually switches itself off.
                $this->recordFailure($bot);

                return;
            }

            [$host, $port, $ip] = $target;
            // Connect to the address we validated, not to whatever the name resolves to a
            // second time — see SafeUrlFetcher::pin.
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
        }

        $response = $request->withBody($body, 'application/json')->post($url);

        if (! $response->successful()) {
            // Thrown, not recorded: this is the retryable case, and the counter is only
            // touched once the retries are exhausted (see failed()).
            throw new RuntimeException("Bot webhook returned HTTP {$response->status()}.");
        }

        // Any success clears the slate — `webhook_failures` counts *consecutive* failures,
        // so an endpoint that's up but flaky never creeps towards being switched off.
        if ($bot->webhook_failures !== 0) {
            $bot->forceFill(['webhook_failures' => 0])->saveQuietly();
        }
    }

    /** Every attempt is spent. One strike against the endpoint. */
    public function failed(): void
    {
        $bot = Bot::find($this->botId);

        if ($bot !== null) {
            $this->recordFailure($bot);
        }
    }

    /**
     * The signature a receiver checks.
     *
     * Over `{timestamp}.{body}` rather than the body alone, so a captured delivery can't be
     * replayed later: the timestamp is signed, it's in a header, and a receiver that
     * rejects old ones gets replay protection for free. Comparing the two is the
     * receiver's job — this is the half we can do.
     */
    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** The exact bytes that get signed and sent — built once so the two can't diverge. */
    private function body(): string
    {
        return (string) json_encode([
            'id' => $this->deliveryId,
            'event' => $this->event,
            'data' => $this->data,
        ]);
    }

    /**
     * Count a failure, and switch the webhook off if it's been failing for long enough.
     *
     * Switching off rather than deleting the URL: the owner's screen needs to be able to
     * say *what* stopped working, and re-enabling should be a button rather than an
     * archaeology exercise.
     */
    private function recordFailure(Bot $bot): void
    {
        $failures = $bot->webhook_failures + 1;
        $ceiling = (int) config('bots.webhooks.max_failures', 50);

        $bot->forceFill([
            'webhook_failures' => $failures,
            'webhook_disabled_at' => $failures >= $ceiling ? now() : $bot->webhook_disabled_at,
        ])->saveQuietly();
    }
}
