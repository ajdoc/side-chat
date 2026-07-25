<?php

namespace App\Services\Games;

use App\Models\SpaceGame;
use App\Models\User;
use App\Services\Widgets\RedactsState;
use App\Services\Widgets\WidgetHandler;
use Illuminate\Validation\ValidationException;

/**
 * One game's brain. It owns the shape and rules of that game's `state` and nothing else —
 * persistence, broadcasting, the start vote and the roster are the {@see GameService}'s job,
 * exactly as a {@see WidgetHandler} leaves those to the widget service.
 *
 * This interface *is* the extensibility the room's games are built for. Adding a game — a pet
 * battle, a heist, a quiz — is writing one of these and registering it. The framework already
 * knows how to propose it, vote it in, run its actions, redact its secrets and end it, because
 * none of that is game-specific. What's game-specific is only the four questions below: how many
 * can play, what the opening position is, what an action does, and what each player is allowed
 * to see.
 */
interface GameHandler
{
    /** The `type` this handles — matches SpaceGame::$type and the value the propose menu sends. */
    public function type(): string;

    /** What the propose menu calls it. */
    public function label(): string;

    /**
     * How this game gets off the ground.
     *
     *   - `vote`      — put to the whole room; a majority starts it. Among Us.
     *   - `challenge` — aimed at one person; it starts when *they* accept. A duel.
     *
     * This is the one place the framework needs a game to declare its social shape, and it's why
     * a pet battle and a room-wide game can share every other piece of the machinery.
     */
    public function startMode(): string;

    /** A sentence for the propose menu — what the room is agreeing to play. */
    public function blurb(): string;

    /** Fewest players it's worth starting with. The vote can't start a game below this. */
    public function minPlayers(): int;

    /** Most it can hold. Extra arrivals spectate. */
    public function maxPlayers(): int;

    /**
     * The opening state, given the game and the ids of everyone who's playing.
     *
     * The players are those in the room when the vote passed — a snapshot. The game is passed
     * whole (not just the ids) because most games need the *room*: Among Us scatters its tasks
     * across the map's walkable tiles, and only the game knows the map.
     *
     * @param  array<int, int>  $playerIds
     * @return array<string, mixed> the initial `state`
     */
    public function start(SpaceGame $game, array $playerIds): array;

    /**
     * Apply one player's action, returning the new state.
     *
     * Throw {@see ValidationException} to refuse it — a dead player
     * voting, an impostor's kill still on cooldown. The service persists whatever comes back and
     * tells the room; the handler never touches the database or the broadcaster.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the new `state`
     */
    public function act(SpaceGame $game, User $actor, string $action, array $payload): array;

    /**
     * The state as this viewer is allowed to see it — the redaction step, exactly like a
     * widget's {@see RedactsState}.
     *
     * This is where a game's secrets are kept: Among Us hands each player their own role and
     * their own task list and nobody else's. `$viewer` is null for someone reading from outside
     * the room, who gets the barest public view. Must not mutate the passed-in state.
     *
     * @return array<string, mixed>
     */
    public function view(SpaceGame $game, ?User $viewer): array;
}
