<?php

namespace App\DTOs\Bot;

use App\Models\Bot;
use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class UpdateBotData extends ValidatedDTO
{
    public ?string $name;

    public ?string $description;

    public ?string $avatar;

    /**
     * Every field optional: this is a PATCH, and the caller sends only what changed. A
     * present-but-null `description` or `avatar` clears it, which is why they're `nullable`
     * rather than `filled` — see UpdateBotAction for how the two are told apart.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            // Sending null unregisters the endpoint; sending a new one re-enables delivery
            // even if it had been switched off after failing. See UpdateBotAction.
            'webhook_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
            'events' => ['sometimes', 'nullable', 'array'],
            'events.*' => [Rule::in(Bot::EVENTS)],
            // The re-enable button, for an endpoint that was switched off after failing.
            'webhook_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return self::validationRules();
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
