<?php

namespace App\Search;

use Illuminate\Http\Request;

/**
 * The narrowing part of a search, separated from the term itself.
 *
 * A value object rather than an array because these are read in four places and a typo in
 * a string key fails silently as "no filter" — which is the worst possible failure mode
 * for something whose whole job is to *reduce* a result set.
 */
final class SearchFilters
{
    /** What `has:` may ask for. */
    public const HAS = ['link', 'file', 'image'];

    public function __construct(
        public readonly ?int $channelId = null,
        public readonly ?int $serverId = null,
        public readonly ?int $conversationId = null,
        /** Only messages by this person. */
        public readonly ?int $fromUserId = null,
        /** ISO dates; `after` is inclusive of the day, `before` exclusive. */
        public readonly ?string $after = null,
        public readonly ?string $before = null,
        /** One of self::HAS. */
        public readonly ?string $has = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            channelId: $request->integer('channel_id') ?: null,
            serverId: $request->integer('server_id') ?: null,
            conversationId: $request->integer('conversation_id') ?: null,
            fromUserId: $request->integer('from') ?: null,
            after: $request->string('after')->value() ?: null,
            before: $request->string('before')->value() ?: null,
            has: $request->string('has')->value() ?: null,
        );
    }
}
