<?php

namespace App\Http\Requests\Voice;

use App\DTOs\Voice\UpdateVoiceEffectsData;
use App\Http\Requests\ServerOwnerRequest;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Validation\Validator;

/**
 * Attaching an effect to somebody is the owner's to do.
 *
 * Everything else inside a call is open to anyone in it — muting, even turning people out —
 * because those are things you do to a call in progress and the next click undoes them. An
 * entrance effect is different: it's a standing decision about how the room greets a named
 * person, and it fires at everybody present without their asking. That belongs to whoever
 * owns the place.
 *
 * ServerOwnerRequest resolves to null for a DM or group chat, so those are refused: a chat
 * has no owner to be, and its call is a conversation rather than a venue.
 */
class UpdateVoiceEffectsRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return UpdateVoiceEffectsData::validationRules();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $channel = $this->route('channel');
                if (! $channel instanceof Channel) {
                    return;
                }

                if (! $channel->allowsCalls()) {
                    $validator->errors()->add('channel', 'This is not a voice channel.');
                }

                // An effect for somebody who can't get into the room is a decision about
                // nobody — and a way to probe whether a given account exists here.
                $userId = $this->input('user_id');
                if ($userId === null) {
                    return;
                }

                $user = User::find($userId);

                if (! $user || ! $channel->hasMember($user)) {
                    $validator->errors()->add('user_id', 'That person isn\'t a member here.');
                }
            },
        ];
    }
}
