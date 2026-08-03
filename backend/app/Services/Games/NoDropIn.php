<?php

namespace App\Services\Games;

use App\Models\SpaceGame;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * For a game whose roster is settled the moment it starts.
 *
 * Most games are this: the players are whoever was there when it began, and a latecomer
 * spectates. Only a game built to be walked into — a dungeon run, a lobby — wants the other
 * answer, so the two lines that say "no" live here rather than in every handler.
 */
trait NoDropIn
{
    public function joinable(): bool
    {
        return false;
    }

    /** Never reached — the service checks {@see joinable} first — but a lie here would be worse. */
    public function join(SpaceGame $game, User $user): array
    {
        throw ValidationException::withMessages(['join' => 'This game is already under way.']);
    }
}
