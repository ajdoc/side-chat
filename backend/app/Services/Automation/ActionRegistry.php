<?php

namespace App\Services\Automation;

use App\Services\Automation\Actions\AddReactionAction;
use App\Services\Automation\Actions\DirectMessageAction;
use App\Services\Automation\Actions\EnterGiveawayAction;
use App\Services\Automation\Actions\GrantBadgeAction;
use App\Services\Automation\Actions\PostMessageAction;
use App\Services\Automation\Actions\RevokeBadgeAction;
use App\Services\Automation\Actions\RunCommandAction;
use App\Services\Automation\Actions\RunScheduleAction;
use App\Services\Automation\Actions\SetRoleAction;
use App\Services\Commands\SlashCommandService;
use Illuminate\Contracts\Container\Container;

/**
 * Everything a rule can do, in one place.
 *
 * Modelled on {@see SlashCommandService::BUILT_INS} — a const list of
 * class strings, resolved through the container so handlers can take the services they need
 * (SendMessageAction, the badge models) as constructor dependencies rather than reaching for
 * facades.
 *
 * Resolved lazily and cached per instance: the registry is asked "is `post_message` a real
 * action" on every save and every run, and instantiating every handler to answer that would
 * be silly.
 */
final class ActionRegistry
{
    /** @var array<int, class-string<AutomationActionHandler>> */
    private const HANDLERS = [
        PostMessageAction::class,
        DirectMessageAction::class,
        GrantBadgeAction::class,
        RevokeBadgeAction::class,
        SetRoleAction::class,
        AddReactionAction::class,
        // The two that make the built-in features compose rather than sit in silos.
        RunCommandAction::class,
        RunScheduleAction::class,
        EnterGiveawayAction::class,
    ];

    /** @var array<string, AutomationActionHandler>|null */
    private ?array $handlers = null;

    public function __construct(private readonly Container $container) {}

    public function get(string $type): ?AutomationActionHandler
    {
        return $this->handlers()[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return $this->get($type) !== null;
    }

    public function names(): array
    {
        return array_keys($this->handlers());
    }

    /**
     * What the builder renders its action forms from.
     *
     * @return array<int, array{name: string, label: string, schema: array<int, array<string, mixed>>}>
     */
    public function catalogue(): array
    {
        return array_values(array_map(fn (AutomationActionHandler $handler) => [
            'name' => $handler->name(),
            'label' => $handler->label(),
            'schema' => $handler->schema(),
        ], $this->handlers()));
    }

    /** @return array<string, AutomationActionHandler> */
    private function handlers(): array
    {
        if ($this->handlers === null) {
            $this->handlers = [];

            foreach (self::HANDLERS as $class) {
                $handler = $this->container->make($class);
                $this->handlers[$handler->name()] = $handler;
            }
        }

        return $this->handlers;
    }
}
