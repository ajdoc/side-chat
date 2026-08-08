<?php

namespace App\Services\Commands;

use App\Jobs\DeliverBotEvent;
use App\Models\BotCommand;
use App\Models\Channel;
use App\Models\CustomCommand;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerRegistry;
use App\Services\Commands\Handlers\EightBallCommand;
use App\Services\Commands\Handlers\MeCommand;
use App\Services\Commands\Handlers\RemindCommand;
use App\Services\Commands\Handlers\RollCommand;
use App\Services\Commands\Handlers\ShrugCommand;
use App\Services\Commands\Handlers\WebCommand;
use App\Support\Automation\AutomationContext;
use App\Support\Automation\Subject;
use App\Support\Commands\ParsedCommand;
use App\Support\Commands\SlashOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything `/` can mean, in one place.
 *
 * A slash command resolves in three steps, in this order:
 *
 *  1. a **built-in** — the handful of things the app itself does (`/roll`, `/me`);
 *  2. a **bot's** command — registered by a bot in this server, delivered to its webhook;
 *  3. nothing, which is answered with a private "no such command" rather than by posting
 *     the message as typed. Somebody who wrote `/rol 2d6` meant to roll dice; putting that
 *     in the channel would be a strange way to tell them they'd missed.
 *
 * Built-ins win on purpose. A bot could otherwise claim `/help` and quietly become the only
 * way to find out what anything does.
 */
class SlashCommandService
{
    /** @var array<int, class-string<SlashCommand>> */
    private const BUILT_INS = [
        RollCommand::class,
        EightBallCommand::class,
        MeCommand::class,
        ShrugCommand::class,
        RemindCommand::class,
        WebCommand::class,
    ];

    /** `/help` is answered here rather than by a handler — it's a view of this registry. */
    public const HELP = 'help';

    /** @var array<string, SlashCommand>|null */
    private ?array $builtIns = null;

    public function __construct(
        private readonly AutomationEngine $automations,
        private readonly CustomCommandService $custom,
    ) {}

    public function handle(Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        // Fired for the *attempt*, before resolution, and regardless of what answers it.
        // A rule is not a command handler — it can't reply to the person who typed — so
        // whether the verb resolved isn't a fact it needs. What it wants is "somebody ran
        // /deploy here", which is true even when nothing was listening.
        $this->fireAutomations($channel, $user, $command);

        if ($command->verb === self::HELP) {
            return SlashOutcome::note($this->help($channel, $user));
        }

        $builtIn = $this->builtIns()[$command->verb] ?? null;

        if ($builtIn !== null) {
            return $builtIn->handle($channel, $user, $command);
        }

        // A command this server declared for itself, between the built-ins and the bots.
        // Built-ins still win (a server can't redefine `/help`), but a custom command beats
        // a bot's: a thing the server itself declared shouldn't be shadowed by something a
        // bot registered afterwards.
        if ($channel->server_id !== null) {
            $custom = $this->custom->find($channel->server_id, $command->verb, CustomCommand::SLASH);

            if ($custom !== null) {
                return $this->custom->run($custom, $channel, $user);
            }
        }

        $botCommand = $this->botCommand($channel, $command->verb);

        if ($botCommand !== null) {
            return $this->deliverToBot($botCommand, $channel, $user, $command);
        }

        return SlashOutcome::note("There's no command called `/{$command->verb}`. Try `/help`.");
    }

    /**
     * Every command callable in this channel — built-ins, then whatever the bots here
     * answer to. Feeds `/help` and the composer's autocomplete, so both are always telling
     * the truth about the same list.
     *
     * @return array<int, array{name: string, description: string|null, usage: string|null, bot: string|null}>
     */
    public function catalogue(Channel $channel, User $user): array
    {
        $commands = [[
            'name' => self::HELP,
            'description' => 'List the commands you can use here.',
            'usage' => '/help',
            'bot' => null,
        ]];

        foreach ($this->builtIns() as $handler) {
            $commands[] = [
                'name' => $handler->name(),
                'description' => $handler->description(),
                'usage' => $handler->usage(),
                'bot' => null,
            ];
        }

        // In resolution order, so `/help` reads as the list it actually is. A custom
        // command that is prefix-only is left out — it isn't callable with a slash, and
        // listing it here would be an invitation the resolver refuses.
        if ($channel->server_id !== null) {
            foreach (CustomCommand::enabledIn($channel->server_id)->orderBy('name')->get() as $command) {
                if (! $command->answersTo(CustomCommand::SLASH)) {
                    continue;
                }

                $commands[] = [
                    'name' => $command->name,
                    'description' => $command->description,
                    'usage' => '/'.$command->name,
                    'bot' => null,
                ];
            }
        }

        foreach ($this->botCommands($channel) as $command) {
            $commands[] = [
                'name' => $command->name,
                'description' => $command->description,
                'usage' => $command->usage,
                // Named, because "who am I talking to" is the difference between a command
                // the app runs and one that leaves the building.
                'bot' => $command->bot?->user?->name,
            ];
        }

        return $commands;
    }

    /** `command.invoked`, for the rules in this channel's server. */
    private function fireAutomations(Channel $channel, User $user, ParsedCommand $command): void
    {
        // A bot can reach this path (its messages go through the ordinary send), and a bot
        // running a command must not be able to trigger a rule whose action runs a command.
        if ($channel->server_id === null || $user->is_bot) {
            return;
        }

        $channel->loadMissing('server');

        $this->automations->fire(new AutomationContext(
            $channel->server_id,
            TriggerRegistry::COMMAND_INVOKED,
            [
                ...Subject::fields($user, $channel->server),
                'channel_id' => $channel->getKey(),
                'channel_name' => $channel->name,
                'command' => $command->verb,
                'args' => $command->args,
            ],
        ));
    }

    /** The names nothing else may claim: a bot can't shadow a built-in. */
    public function reservedNames(): array
    {
        return [self::HELP, ...array_keys($this->builtIns())];
    }

    /**
     * Hands the invocation to the bot that registered it.
     *
     * The answer is a private "sent it" rather than silence: the message the person typed
     * has just vanished from their composer, and something has to account for it. What
     * happens next is the bot's business — it replies through the bot API like anything
     * else, and if it never does, nothing here is left half-finished.
     */
    private function deliverToBot(BotCommand $botCommand, Channel $channel, User $user, ParsedCommand $command): SlashOutcome
    {
        $bot = $botCommand->bot;

        if ($bot === null || ! $bot->webhookActive() || $bot->user === null || ! $channel->hasMember($bot->user)) {
            return SlashOutcome::note("`/{$command->verb}` belongs to a bot that isn't listening right now.");
        }

        DeliverBotEvent::dispatch($bot->id, 'command.invoked', [
            'command' => $botCommand->name,
            'args' => $command->args,
            'channel_id' => $channel->id,
            // Who typed it, so the bot can address them back. Its account name, not a
            // nickname: the bot is answering into a channel where nicknames are rendered
            // from the id anyway.
            'user' => ['id' => $user->id, 'name' => $user->name],
        ], (string) Str::uuid());

        return SlashOutcome::note("Sent `/{$command->verb}` to **{$bot->user->name}**.");
    }

    private function botCommand(Channel $channel, string $name): ?BotCommand
    {
        return $this->botCommands($channel)->firstWhere('name', $name);
    }

    /**
     * The bot commands callable in *this* channel.
     *
     * Filtered by what each bot can see, not merely by which server it's in: a bot that was
     * never added to a private channel shouldn't be reachable from inside one, and
     * shouldn't appear in its autocomplete either — the list of commands a bot answers to
     * is itself a hint about what that bot is for.
     *
     * @return Collection<int, BotCommand>
     */
    private function botCommands(Channel $channel): Collection
    {
        if ($channel->server_id === null) {
            return collect(); // A DM or group chat: bots belong to servers.
        }

        return BotCommand::with('bot.user')
            ->where('server_id', $channel->server_id)
            ->get()
            ->filter(fn (BotCommand $command) => $command->bot?->user !== null
                && $channel->hasMember($command->bot->user))
            ->values();
    }

    private function help(Channel $channel, User $user): string
    {
        $lines = array_map(function (array $command): string {
            $usage = $command['usage'] ?? '/'.$command['name'];
            $description = $command['description'] ?? '';
            $by = $command['bot'] !== null ? " _(via {$command['bot']})_" : '';

            return "`{$usage}` — {$description}{$by}";
        }, $this->catalogue($channel, $user));

        return "**Commands**\n".implode("\n", $lines);
    }

    /** @return array<string, SlashCommand> */
    private function builtIns(): array
    {
        if ($this->builtIns === null) {
            $this->builtIns = [];

            foreach (self::BUILT_INS as $class) {
                $handler = app($class);
                $this->builtIns[$handler->name()] = $handler;
            }
        }

        return $this->builtIns;
    }
}
