<?php

namespace App\Http\Requests\Message;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use Illuminate\Validation\Validator;

/**
 * Forwarding touches two places, so it's authorized in two halves:
 *
 *  - the *source* — MemberRequest's authorize() checks membership of the message being
 *    forwarded (the route-bound `message`), so you can't forward out of a room you can't
 *    see; and
 *  - the *target* — the `channel_id` you're forwarding into, checked below, so you can't
 *    forward into a room you're not a member of.
 */
class ForwardMessageRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return; // channel_id already failed a base rule — no point checking membership
            }

            $target = Channel::find($this->integer('channel_id'));

            if ($target === null || ! $target->hasMember($this->user())) {
                $validator->errors()->add('channel_id', 'You are not a member of the selected destination.');
            }
        });
    }
}
