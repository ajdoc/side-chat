<?php

namespace App\Services\Moba;

use App\Models\MobaMatch;
use App\Models\MobaProfile;

/**
 * Rating: Elo, applied to teams.
 *
 * ## Why Elo and not something cleverer
 *
 * Glicko and TrueSkill are better at what they do — they model uncertainty, which matters when
 * you have a handful of games and a wide population. This game has neither yet. Elo is a dozen
 * lines, everyone understands what it does, and it can be replaced later without touching
 * anything that reads `mmr`, because what it produces is a single number and that is all that is
 * stored.
 *
 * ## Team Elo
 *
 * Each team's rating is the mean of its players'. The expected result comes from the difference
 * between those means, and every player on a side moves by the same amount. Weighting individual
 * contribution — by kills, by damage — is a well-known way to teach players to farm their own
 * statistics instead of winning, so the whole team moves together.
 */
class RatingService
{
    /**
     * How far a single result can move a rating.
     *
     * 32 is the traditional value and about right here: a player is placed within a dozen games
     * and still moves meaningfully afterwards. Higher and ratings bounce; lower and a smurf
     * spends fifty games in the wrong bracket ruining them for everyone in it.
     */
    private const K_FACTOR = 32;

    /**
     * Apply a finished match's result to everyone in it.
     *
     * Writes `mmr_change` onto each seat as well as the profile, so the post-game screen can
     * show what a game was worth without recomputing it — and so a rating formula that changes
     * later does not rewrite history.
     */
    public function apply(MobaMatch $match): void
    {
        if ($match->winning_team === null) {
            return;
        }

        $players = $match->players()->with('user')->get();
        if ($players->isEmpty()) {
            return;
        }

        $profiles = $players->mapWithKeys(
            fn ($player) => [$player->user_id => MobaProfile::forUser($player->user_id)]
        );

        $meanFor = function (int $team) use ($players, $profiles): float {
            $side = $players->where('team', $team);
            if ($side->isEmpty()) {
                return MobaProfile::STARTING_MMR;
            }

            return $side->avg(fn ($p) => $profiles[$p->user_id]->mmr);
        };

        $means = [
            MobaMatch::TEAM_BLUE => $meanFor(MobaMatch::TEAM_BLUE),
            MobaMatch::TEAM_RED => $meanFor(MobaMatch::TEAM_RED),
        ];

        foreach ($players as $player) {
            $own = $means[$player->team];
            $other = $means[$player->team === MobaMatch::TEAM_BLUE ? MobaMatch::TEAM_RED : MobaMatch::TEAM_BLUE];

            $expected = 1 / (1 + pow(10, ($other - $own) / 400));
            $won = $player->team === $match->winning_team;
            $change = (int) round(self::K_FACTOR * (($won ? 1 : 0) - $expected));

            $profile = $profiles[$player->user_id];
            // Floored rather than allowed to go negative: a rating below zero means nothing and
            // is a demoralising thing to show someone.
            $profile->mmr = max(0, $profile->mmr + $change);
            $profile->games++;
            if ($won) {
                $profile->wins++;
            }
            $profile->save();

            $player->mmr_change = $change;
            $player->save();
        }
    }
}
