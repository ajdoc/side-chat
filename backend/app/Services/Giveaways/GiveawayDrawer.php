<?php

namespace App\Services\Giveaways;

use App\Models\Automation;
use App\Models\Giveaway;
use App\Services\Automation\Actions\PostMessageAction;
use App\Support\Automation\AutomationContext;
use Illuminate\Support\Facades\DB;

/**
 * Picking the winners and saying who they are.
 *
 * A service rather than a method on the model or the controller, because two callers need
 * exactly the same behaviour: the runner (`bot:draw-giveaways`) and the "draw now" button.
 * An early draw that announced differently from an on-time one would be a small lie about
 * what the button does.
 */
class GiveawayDrawer
{
    public function __construct(private readonly PostMessageAction $post) {}

    /**
     * Draw it, announce it, and stop taking entries.
     *
     * The order matters: the winners are recorded *first*, in a transaction, and only then
     * is anything posted. A crash mid-announcement leaves a drawn giveaway with a missing
     * message, which somebody can fix by looking; a crash the other way round would leave
     * winners announced and not recorded, which nobody can.
     */
    public function draw(Giveaway $giveaway): void
    {
        $winners = DB::transaction(function () use ($giveaway) {
            $winners = $giveaway->draw();
            // The entry rule has done its job. Deleting it is belt-and-braces — the action
            // already refuses a closed giveaway — but it keeps a server's rule list from
            // filling up with rules for giveaways that ended months ago.
            $this->entryRules($giveaway)->each->delete();

            return $winners;
        });

        $names = $winners->load('user:id,name')
            ->map(fn ($entry) => $entry->user?->name)
            ->filter();

        $this->post->handle(
            ['channel_id' => $giveaway->channel_id, 'body' => $this->result($giveaway, $names)],
            new AutomationContext($giveaway->server_id, 'giveaway.drawn', [
                'giveaway_id' => $giveaway->getKey(),
                'prize' => $giveaway->prize,
            ]),
        );
    }

    /** @param \Illuminate\Support\Collection<int, string> $names */
    private function result(Giveaway $giveaway, $names): string
    {
        // Said plainly rather than dressed up. A giveaway nobody entered is a normal thing
        // that happens, and announcing a winner who doesn't exist would be worse.
        if ($names->isEmpty()) {
            return "🎁 **{$giveaway->prize}** — nobody entered, so there's no winner.";
        }

        $list = $names->map(fn (string $name) => "**{$name}**")->join(', ', ' and ');

        return "🎉 **{$giveaway->prize}** goes to {$list}. Congratulations!";
    }

    /** @return \Illuminate\Support\Collection<int, Automation> */
    private function entryRules(Giveaway $giveaway)
    {
        return Automation::where('server_id', $giveaway->server_id)
            ->where('builtin', Automation::BUILTIN_GIVEAWAY)
            ->get()
            ->filter(fn (Automation $rule) => (int) $rule->triggerOption('giveaway_id') === $giveaway->getKey());
    }
}
