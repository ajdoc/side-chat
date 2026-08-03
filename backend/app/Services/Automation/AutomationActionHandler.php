<?php

namespace App\Services\Automation;

use App\Services\Commands\SlashCommand;
use App\Support\Automation\ActionResult;
use App\Support\Automation\AutomationContext;

/**
 * One thing a rule can do.
 *
 * The same shape as {@see SlashCommand}: a small class that names
 * itself, describes itself, and does one thing. The registry is the only place that knows
 * the full set, so adding an action is adding a class and a line.
 *
 * A handler never throws to signal "there was nothing to do" — it returns a skip. The
 * difference matters because it's the difference between a red line on somebody's dashboard
 * and a grey one, and most of what goes "wrong" in an automation (the member already left,
 * they already had the badge) is not wrong at all.
 */
interface AutomationActionHandler
{
    /** The stored `type` string. Stable — it's in people's rows. */
    public function name(): string;

    /** What the dashboard calls it. */
    public function label(): string;

    /**
     * The config fields this action needs, for the builder to render a form from.
     *
     * Each entry is `{key, type, label, required, ...}` where `type` is one of `text`,
     * `textarea`, `channel`, `badge`, `role`, `number`, `boolean`. The types name *pickers*
     * rather than primitives so the form can offer a channel dropdown instead of asking
     * somebody to find a channel id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schema(): array;

    /**
     * Do it.
     *
     * @param  array<string, mixed>  $config  As configured, already merged with defaults.
     */
    public function handle(array $config, AutomationContext $context): ActionResult;
}
