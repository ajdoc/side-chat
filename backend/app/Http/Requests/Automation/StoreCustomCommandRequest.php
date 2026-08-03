<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use App\Models\CustomCommand;
use App\Services\Commands\SlashCommandService;
use Illuminate\Validation\Rule;

class StoreCustomCommandRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $serverId = $this->resolveServer()?->getKey();
        $commandId = $this->route('command')?->getKey();

        return [
            'name' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:32',
                // The shape both `/name` and `!name` can carry: a letter first, so `/8ball`
                // is fine but `/1` isn't a command in either syntax.
                'regex:/^[a-zA-Z][a-zA-Z0-9-]*$/',
                // A server may not redefine `/help` or `/roll` and quietly become the only
                // way to find out what anything does — the same rule bots are held to.
                Rule::notIn(app(SlashCommandService::class)->reservedNames()),
                Rule::unique('custom_commands', 'name')->where('server_id', $serverId)->ignore($commandId),
            ],
            'kind' => ['sometimes', Rule::in(CustomCommand::KINDS)],
            'description' => ['nullable', 'string', 'max:255'],
            'response' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:2000'],
            'required_badge_id' => [
                'nullable',
                'integer',
                // Scoped: a badge from another server is not a gate this server may set.
                Rule::exists('badges', 'id')->where('server_id', $serverId),
            ],
            // An hour is plenty for "stop somebody spamming !ip", and an unbounded number
            // would be a way to make a command look broken rather than rate-limited.
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:3600'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
