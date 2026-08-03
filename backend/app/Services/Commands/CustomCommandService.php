<?php

namespace App\Services\Commands;

use App\Models\Badge;
use App\Models\Channel;
use App\Models\CustomCommand;
use App\Models\User;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Template;
use App\Support\Commands\SlashOutcome;
use Illuminate\Support\Facades\Cache;

/**
 * The commands a server invented for itself — looked up, gated, and answered.
 *
 * One service for both shapes. `/rules` arrives through SlashCommandService and `!rules`
 * through the send path, but everything *after* the lookup — is it enabled, may this person
 * run it, are they on cooldown, what does the response say — is identical, and having it in
 * one place is what stops the two shapes drifting into behaving differently.
 */
class CustomCommandService
{
    /**
     * Find the command this verb names, in the shape it was typed.
     *
     * `$kind` matters: a command set to `prefix` only must not answer a slash, or a server
     * that deliberately moved something off `/` would find it still there.
     */
    public function find(int $serverId, string $verb, string $kind): ?CustomCommand
    {
        return CustomCommand::enabledIn($serverId)
            ->with('requiredBadge')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($verb)])
            ->get()
            ->first(fn (CustomCommand $command) => $command->answersTo($kind));
    }

    /**
     * Run it, and say what the channel should see.
     *
     * A refusal is always *private* — a cooldown or a missing badge is between the person
     * and the bot. Announcing "you can't use that" to the room turns a small no into a
     * public one, which is a strange thing to do to somebody who typed the wrong thing.
     */
    public function run(CustomCommand $command, Channel $channel, User $user): SlashOutcome
    {
        if (($badge = $command->requiredBadge) !== null && ! $this->holds($badge, $user)) {
            return SlashOutcome::note("`{$command->name}` needs the **{$badge->name}** badge.");
        }

        if (($wait = $this->cooldownRemaining($command, $user)) > 0) {
            return SlashOutcome::note("`{$command->name}` is on cooldown — try again in {$wait}s.");
        }

        $this->startCooldown($command, $user);
        $command->recordUse();

        // Posted as the person who typed it, like `/roll` — see SlashOutcome::say. A custom
        // command is the server answering its own FAQ, and routing it through the bot would
        // make it fail on servers that haven't chosen one.
        return SlashOutcome::say($this->render($command, $channel, $user));
    }

    /**
     * The response, with its placeholders filled in.
     *
     * Reuses the automation templater rather than a second one, so `{user}` means the same
     * thing in a command's response as it does in a welcome message. `{args}` is the one
     * addition, and only a command has it.
     */
    public function render(CustomCommand $command, Channel $channel, User $user, string $args = ''): string
    {
        return Template::render($command->response, new AutomationContext($command->server_id, 'command', [
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'channel_name' => $channel->name,
            // Loaded rather than reached for: lazy loading throws outside production, and
            // a command's response is one of the few places that names the server.
            'server_name' => $channel->loadMissing('server')->server?->name ?? '',
            'args' => $args,
        ]));
    }

    private function holds(Badge $badge, User $user): bool
    {
        return $badge->holders()->whereKey($user->getKey())->exists();
    }

    /**
     * Cooldowns live in the cache, not the database.
     *
     * They're per person per command and expire on their own, which is exactly what a cache
     * key with a TTL is. A table would mean a row written on every command and a sweeper to
     * clear them, to enforce something whose worst failure — a cache flush lets somebody
     * run `!ip` twice — nobody would notice.
     */
    private function cooldownRemaining(CustomCommand $command, User $user): int
    {
        if ($command->cooldown_seconds < 1) {
            return 0;
        }

        $until = Cache::get($this->cooldownKey($command, $user));

        return $until === null ? 0 : max(0, (int) $until - now()->getTimestamp());
    }

    private function startCooldown(CustomCommand $command, User $user): void
    {
        if ($command->cooldown_seconds < 1) {
            return;
        }

        Cache::put(
            $this->cooldownKey($command, $user),
            now()->getTimestamp() + $command->cooldown_seconds,
            $command->cooldown_seconds,
        );
    }

    private function cooldownKey(CustomCommand $command, User $user): string
    {
        return "command-cooldown:{$command->getKey()}:{$user->getKey()}";
    }
}
