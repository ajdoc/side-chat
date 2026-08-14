<?php

namespace App\DTOs\Channel;

use App\Models\Channel;
use App\Support\Apps\AppRegistry;
use App\Support\SideSpace\MapPresets;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

final class CreateChannelData extends ValidatedDTO
{
    public string $name;

    public string $type;

    /**
     * Which room a Side Space starts as. Null for every other channel type — and required for
     * a Side Space, because a map has to exist before anybody can walk into it and there is no
     * sensible default room to invent on their behalf.
     */
    public ?string $preset;

    /**
     * Which app an app channel is. Null for every other type — and required for an `app`, for
     * the same reason a Side Space needs a preset: the channel is the app, so there is nothing
     * sensible to render until somebody has said which one.
     *
     * Snake_case to match the wire, rather than `$appId` plus a `mapData()` entry: the package
     * applies that mapping *before* validation, so the rule keyed `app_id` would be looking for
     * a key that had already been renamed away and `required_if` would fire on every app
     * channel. The rules are shared with {@see StoreChannelRequest}, which sees the raw request,
     * so the wire name is the one that has to win.
     */
    public ?string $app_id;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:'.implode(',', Channel::TYPES)],
            'preset' => ['nullable', 'required_if:type,space', 'string', 'in:'.implode(',', MapPresets::keys())],
            'app_id' => ['nullable', 'required_if:type,app', 'string', 'in:'.implode(',', AppRegistry::channelIds())],
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
        // Explicit, so each is always defined rather than unset on the channel types that have
        // no map and no app.
        return ['preset' => null, 'app_id' => null];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
