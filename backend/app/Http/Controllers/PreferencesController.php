<?php

namespace App\Http\Controllers;

use App\Actions\User\UpdatePreferencesAction;
use App\DTOs\User\UpdatePreferencesData;
use App\Http\Requests\User\UpdatePreferencesRequest;
use App\Http\Resources\UserResource;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request, UpdatePreferencesAction $action): UserResource
    {
        return new UserResource(
            $action->handle($request->user(), UpdatePreferencesData::fromArray($request->validated()))
        );
    }
}
