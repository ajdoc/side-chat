<?php

namespace App\Http\Controllers;

use App\Actions\User\UpdateProfileAction;
use App\DTOs\User\UpdateProfileData;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;

/**
 * Who you are to everyone else — as opposed to PreferencesController, which is how the app
 * looks to you. Today that is just the display name; avatars still come from the provider.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): UserResource
    {
        return new UserResource(
            $action->handle($request->user(), UpdateProfileData::fromArray($request->validated()))
        );
    }
}
