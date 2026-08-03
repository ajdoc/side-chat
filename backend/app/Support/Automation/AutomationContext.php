<?php

namespace App\Support\Automation;

use App\Models\Server;
use App\Models\User;

/**
 * Everything an automation knows about the thing that just happened.
 *
 * One immutable bag rather than a typed object per trigger. The engine has to do three
 * things with this — match conditions against it, fill placeholders in a template from it,
 * and hand it to actions — and all three want to look values up *by name* against whatever
 * the trigger happened to supply. A class hierarchy would give each of those a switch on
 * the trigger, which is the thing the registry exists to avoid.
 *
 * It crosses a queue boundary (see RunAutomation), so it holds ids and scalars, never
 * models. A model serialised into a job is a model that may have been edited or deleted by
 * the time the job runs, and an automation acting on stale state is worse than one that
 * notices the row is gone.
 */
final class AutomationContext
{
    /**
     * How many automations deep we already are.
     *
     * An action can cause an event that fires another automation — post a message, and
     * `message.created` is now true. That is a feature (it's how rules compose) and it is
     * also how a server sets itself on fire, so the depth rides along in the context and
     * the engine refuses to fan out past the ceiling. See AutomationEngine::MAX_DEPTH.
     */
    public function __construct(
        public readonly int $serverId,
        public readonly string $trigger,
        /** @var array<string, mixed> Flat, scalar-only. See the class comment. */
        public readonly array $data = [],
        public readonly int $depth = 0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function make(Server $server, string $trigger, array $data, int $depth = 0): self
    {
        return new self($server->getKey(), $trigger, $data, $depth);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** The member this event is about, if it's about anybody. */
    public function subject(): ?User
    {
        $id = $this->get('user_id');

        return $id === null ? null : User::find($id);
    }

    public function server(): ?Server
    {
        return Server::find($this->serverId);
    }

    /** @param array<string, mixed> $extra */
    public function with(array $extra): self
    {
        return new self($this->serverId, $this->trigger, [...$this->data, ...$extra], $this->depth);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'server_id' => $this->serverId,
            'trigger' => $this->trigger,
            'data' => $this->data,
            'depth' => $this->depth,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            (int) $payload['server_id'],
            (string) $payload['trigger'],
            (array) ($payload['data'] ?? []),
            (int) ($payload['depth'] ?? 0),
        );
    }
}
