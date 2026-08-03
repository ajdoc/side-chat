<?php

namespace App\Console\Commands;

use App\Models\Giveaway;
use App\Services\Giveaways\GiveawayDrawer;
use Illuminate\Console\Command;

/**
 * Draws every giveaway whose time is up. Run every minute — see routes/console.php.
 *
 * Same shape as the schedule runner: an indexed query that finds nothing on almost every
 * tick, so asking this often costs near nothing.
 */
class DrawGiveaways extends Command
{
    protected $signature = 'bot:draw-giveaways';

    protected $description = 'Draw any giveaways that have ended.';

    public function handle(GiveawayDrawer $drawer): int
    {
        $due = Giveaway::whereNull('drawn_at')
            ->whereNull('cancelled_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($due as $giveaway) {
            $drawer->draw($giveaway);
        }

        $this->info("Drew {$due->count()} giveaway(s).");

        return self::SUCCESS;
    }
}
