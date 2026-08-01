<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook delivery
    |--------------------------------------------------------------------------
    |
    | Every one of these numbers is really the same trade-off: a bot's endpoint is a
    | third-party server we don't control, and it can be slow, wrong, or gone.
    |
    | `timeout` is short on purpose. Delivery runs on the queue, so a slow endpoint
    | doesn't hold up a send — but a worker sitting on a 30-second connect is a worker
    | not delivering anyone else's events, and a webhook receiver that can't acknowledge
    | in a few seconds should be queueing the work itself.
    |
    | `tries` / `backoff` cover the ordinary case of a receiver restarting: three
    | attempts spread over a couple of minutes ride out a deploy without turning a
    | momentary blip into lost events.
    |
    | `max_failures` is the giving-up point, counted in *consecutive* failed deliveries
    | across events rather than retries within one. An endpoint that has refused fifty
    | events in a row isn't restarting, it's gone — and something has to stop us
    | hammering a stranger's server forever. Hitting it switches the webhook off and
    | leaves it for the server's owner to fix and re-enable.
    |
    */

    'webhooks' => [
        'timeout' => (int) env('BOT_WEBHOOK_TIMEOUT', 5),
        'tries' => (int) env('BOT_WEBHOOK_TRIES', 3),
        /** Seconds to wait before each retry. */
        'backoff' => [10, 60],
        'max_failures' => (int) env('BOT_WEBHOOK_MAX_FAILURES', 50),

        /*
        | Bodies are capped the same way link previews are: a receiver's response is
        | read only far enough to know whether it succeeded. We never use the content.
        */
        'max_response_bytes' => 8 * 1024,

        /*
        | Allow webhook URLs that resolve to private addresses.
        |
        | Off, and it should stay off anywhere the servers aren't all yours: the URL is
        | typed by a user, and our server is the one making the request, so a private
        | target is an SSRF hole with extra steps (see SafeUrlFetcher for the full
        | argument). The escape hatch exists because a self-hosted deployment whose bot
        | runs in the same Docker network genuinely does need to reach `http://bot:8080`,
        | and telling those users "expose it to the internet first" is worse advice.
        */
        'allow_private_targets' => (bool) env('BOT_WEBHOOK_ALLOW_PRIVATE', false),
    ],

];
