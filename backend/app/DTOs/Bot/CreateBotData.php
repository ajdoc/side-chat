<?php

namespace App\DTOs\Bot;

use App\Models\Bot;
use Illuminate\Validation\Rule;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateBotData extends ValidatedDTO
{
    public string $name;
    public ?string $description;
    public ?string $avatar;

    /** Where to deliver events. Optional — a bot that only ever posts needs no endpoint. */
    public ?string $webhook_url;

    /**
     * Which events to deliver, or null for the default set. See Bot::subscribedEvents.
     *
     * @var array<int, string>|null
     */
    public ?array $events;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'url', 'max:2048'],
            // http as well as https: a self-hosted bot on the same network is a real case,
            // and the signature — not the transport — is what proves a delivery is ours.
            'webhook_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in(Bot::EVENTS)],
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
        return ['description' => null, 'avatar' => null, 'webhook_url' => null, 'events' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
