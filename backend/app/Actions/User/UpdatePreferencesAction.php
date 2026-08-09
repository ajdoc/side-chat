<?php

namespace App\Actions\User;

use App\DTOs\User\UpdatePreferencesData;
use App\Models\User;

final class UpdatePreferencesAction
{
    public function handle(User $user, UpdatePreferencesData $data): User
    {
        $payload = array_filter([
            'theme_mode' => $data->theme_mode,
            'theme_color' => $data->theme_color,
            'notify_channel_default' => $data->notify_channel_default,
            'notify_dm_default' => $data->notify_dm_default,
            'push_enabled' => $data->push_enabled,
        ], static fn ($value): bool => $value !== null);

        if ($payload !== []) {
            $user->update($payload);
        }

        return $user;
    }
}
