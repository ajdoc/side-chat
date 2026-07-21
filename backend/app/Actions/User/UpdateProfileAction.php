<?php

namespace App\Actions\User;

use App\DTOs\User\UpdateProfileData;
use App\Models\User;

final class UpdateProfileAction
{
    public function handle(User $user, UpdateProfileData $data): User
    {
        $name = $data->name !== null ? trim($data->name) : null;

        if ($name !== null && $name !== '') {
            $user->update(['name' => $name]);
        }

        return $user;
    }
}
