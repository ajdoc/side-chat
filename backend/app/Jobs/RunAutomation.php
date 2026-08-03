<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Services\Automation\AutomationEngine;
use App\Support\Automation\AutomationContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one rule, off the request that caused it.
 *
 * One job per rule rather than one job that loops over all of them, so a rule that fails
 * retries on its own without re-running the ones beside it — the same shape as
 * DeliverBotEvent, and for the same reason.
 *
 * The context travels as a plain array, not a context object holding models: a job can run
 * after the member has left or the message has been deleted, and an automation acting on a
 * model snapshot from before that would be acting on something untrue. Ids re-resolve, and
 * an action that finds its subject gone records a skip.
 */
class RunAutomation implements ShouldQueue
{
    use Queueable;

    /**
     * Once, then give up.
     *
     * Retrying is wrong here in a way it isn't for a webhook. A webhook delivery is
     * idempotent from our side — the receiver deduplicates on the delivery id. An action is
     * not: a retried `post_message` posts twice, and a member who gets welcomed three times
     * because a *later* action in the same rule was flaky is a worse outcome than one who
     * doesn't get welcomed at all. Failures are recorded in the audit log for the owner to
     * see and re-run by hand.
     */
    public int $tries = 1;

    /** @param array<string, mixed> $context As produced by AutomationContext::toArray. */
    public function __construct(
        public int $automationId,
        public array $context,
    ) {}

    public function handle(AutomationEngine $engine): void
    {
        $automation = Automation::with('actions')->find($this->automationId);

        // Deleted or switched off between the trigger firing and the worker picking this
        // up. Both mean "don't", not "something went wrong" — a rule disabled a moment ago
        // was disabled for a reason and honouring the queue's memory of it would be rude.
        if ($automation === null || ! $automation->enabled) {
            return;
        }

        $engine->run($automation, AutomationContext::fromArray($this->context));
    }
}
