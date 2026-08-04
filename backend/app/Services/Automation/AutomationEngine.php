<?php

namespace App\Services\Automation;

use App\Jobs\RunAutomation;
use App\Models\Automation;
use App\Models\BotAuditLog;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\ConditionEvaluator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two halves of running a rule: deciding which ones apply, and running one.
 *
 * They're separated by the queue. `fire()` happens on the request that caused the event and
 * must be cheap — one indexed query and a job per match — because somebody is waiting on a
 * send or a join. `run()` happens on a worker, where an action is free to post messages and
 * talk to the database without anybody watching a spinner. It's the same trade
 * DeliverBotEvent makes, for the same reason: nobody's message should be slow because
 * somebody else configured six rules.
 */
final class AutomationEngine
{
    /**
     * How many automations may cause each other before we stop.
     *
     * Actions cause events — posting a message makes `message.created` true — and that is
     * deliberate, because it's how rules compose. It is also exactly how a server melts: two
     * rules that each answer the other would run at queue speed forever. Three is enough for
     * the real cases (a rule grants a badge, the badge rule announces it, the announcement
     * pings a channel) and small enough that a runaway stops in under a second.
     *
     * The other half of the guard lives in RunMessageAutomations, which never fires
     * `message.created` for a message a bot wrote — see the comment there, and BOTS.md.
     */
    public const MAX_DEPTH = 3;

    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * Something happened. Queue whatever it means.
     *
     * Returns the number of rules queued, which is what the tests assert on and what a
     * "test this trigger" button in the dashboard reports.
     */
    public function fire(AutomationContext $context): int
    {
        if ($context->depth >= self::MAX_DEPTH) {
            // Not logged as a failure against any one rule — no single rule is at fault, the
            // cycle is. A warning in the application log is the right audience: this is an
            // operator's problem.
            Log::warning('Automation depth limit reached; not fanning out.', [
                'server_id' => $context->serverId,
                'trigger' => $context->trigger,
            ]);

            return 0;
        }

        $queued = 0;

        foreach (Automation::listeningFor($context->serverId, $context->trigger)->get() as $automation) {
            if (! $this->conditions->passes($automation->conditions, $context, $automation->condition_match)) {
                continue;
            }

            RunAutomation::dispatch($automation->getKey(), $context->toArray());
            $queued++;
        }

        return $queued;
    }

    /**
     * Run one rule's actions, in order.
     *
     * A failing action does not abort the ones after it. Rules are written as a list of
     * things to do rather than a transaction — "grant the badge, welcome them, log it" — and
     * having the welcome vanish because the log channel was deleted would be a strange
     * reading of that. Each step records its own line either way, so a partial run is
     * legible rather than mysterious.
     */
    public function run(Automation $automation, AutomationContext $context): void
    {
        $automation->loadMissing('actions');
        $automation->recordRun();

        foreach ($automation->actions as $action) {
            $handler = $this->actions->get($action->type);

            $result = $handler === null
                // A rule referring to an action this build doesn't have. Recorded rather
                // than thrown: the likely cause is a downgrade, and the owner needs to be
                // able to see which step stopped working.
                ? ActionResult::failed("There's no action called `{$action->type}`.")
                : $this->attempt($handler, $action->config ?? [], $context);

            $this->record($automation, $action->type, $result, $context);
        }
    }

    /** @param array<string, mixed> $config */
    private function attempt(AutomationActionHandler $handler, array $config, AutomationContext $context): ActionResult
    {
        try {
            return $handler->handle($config, $context);
        } catch (Throwable $e) {
            // The message goes to the operator's log in full and to the owner's dashboard in
            // one line: a stack trace is not something to render next to "Welcome Message".
            Log::error('Automation action failed.', [
                'action' => $handler->name(),
                'server_id' => $context->serverId,
                'exception' => $e,
            ]);

            return ActionResult::failed(mb_substr($e->getMessage(), 0, 200));
        }
    }

    private function record(Automation $automation, string $action, ActionResult $result, AutomationContext $context): void
    {
        BotAuditLog::create([
            'server_id' => $automation->server_id,
            'automation_id' => $automation->getKey(),
            'action' => $action,
            'outcome' => $result->outcome,
            'subject_id' => $context->get('user_id'),
            /*
             * The event that caused this, alongside whatever the action wants to report.
             *
             * `event` is the whole point of recording anything here: a filter that doesn't
             * match writes nothing at all — the rule is skipped before it ever runs — so
             * without a record of what the *values* actually were, "my filter never matches"
             * is unfalsifiable. Remove the filter, let it fire once, and this line shows the
             * exact strings the filter would have been compared against.
             *
             * Namespaced under `event` so an action's own context can never collide with it.
             */
            'context' => ['event' => $context->data] + ($result->context === [] ? [] : ['result' => $result->context]),
            'message' => $result->message,
        ]);
    }
}
