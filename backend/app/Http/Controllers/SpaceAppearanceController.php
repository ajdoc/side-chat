<?php

namespace App\Http\Controllers;

use App\Http\Requests\SideSpace\UpdateSpaceAppearanceRequest;
use App\Http\Resources\UserResource;
use App\Support\SideSpace\Avatars;

/**
 * What you look like when you're walking around, and what's walking behind you.
 *
 * Sibling to ProfileController rather than PreferencesController: this is not how the app looks
 * to you, it's how *you* look to everyone else — the same category as your display name. It's
 * kept out of the profile endpoint only because that one is about your identity across the whole
 * app, and this is a costume you wear in one kind of room.
 *
 * There's no broadcast. A changed look reaches the room the same way a step does — on your next
 * whisper, at most a twelfth of a second later — and everyone who isn't in a room with you has
 * no use for the news at all.
 */
class SpaceAppearanceController extends Controller
{
    public function update(UpdateSpaceAppearanceRequest $request): UserResource
    {
        $user = $request->user();
        $payload = [];

        // `input`, not `validated`, for the nested object: a validated() call reads its rules
        // key by key and would hand back a partial avatar the moment the catalogue grew a
        // field the client hasn't heard of. The values themselves have already been checked.
        if ($request->has('avatar')) {
            $payload['space_avatar'] = Avatars::normaliseLook((array) $request->input('avatar'));
        }

        // `has` rather than `filled` — sending an explicit null is how the pet goes home, and
        // `filled` would quietly ignore exactly that.
        if ($request->has('pet')) {
            $payload['space_pet'] = $request->input('pet');
        }

        // Whitespace collapsed rather than merely trimmed: a bubble is one line whatever was
        // pasted into it, and a shout of nothing but spaces is somebody turning it off.
        if ($request->has('shout')) {
            $shout = trim(preg_replace('/\s+/u', ' ', (string) $request->input('shout')) ?? '');
            $payload['space_shout'] = $shout === '' ? null : $shout;
        }

        if ($payload !== []) {
            $user->update($payload);
        }

        return new UserResource($user);
    }
}
