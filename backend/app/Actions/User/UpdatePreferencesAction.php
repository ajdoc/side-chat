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
        ], static fn ($value): bool => $value !== null);

        if ($payload !== []) {
            $user->update($payload);
        }

        return $user;
    }
}
