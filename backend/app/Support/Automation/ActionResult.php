<?php

namespace App\Support\Automation;

use App\Models\BotAuditLog;

/**
 * What one action did, in the form the audit log wants.
 *
 * Three outcomes, and the middle one is the reason this class exists: a *skip* is an action
 * that correctly declined to act — the member had already left, the badge was already held,
 * no bot has been chosen to speak as. Folding those into failure would fill the dashboard
 * with red for a server that is working perfectly, and folding them into success would hide
 * the one case somebody is actually trying to debug ("why did nothing happen?").
 */
final class ActionResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $message = null,
        /** @var array<string, mixed> Anything worth showing next to the line. */
        public readonly array $context = [],
    ) {}

    /** @param array<string, mixed> $context */
    public static function ok(?string $message = null, array $context = []): self
    {
        return new self(BotAuditLog::OK, $message, $context);
    }

    /** Nothing was wrong; there was nothing to do. `$why` is shown to the server's owner. */
    public static function skipped(string $why): self
    {
        return new self(BotAuditLog::SKIPPED, $why);
    }

    public static function failed(string $why): self
    {
        return new self(BotAuditLog::FAILED, $why);
    }

    public function succeeded(): bool
    {
        return $this->outcome === BotAuditLog::OK;
    }
}
