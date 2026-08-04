<?php

namespace App\Http\Requests\Conversation;

use App\Models\User;
use App\Services\ConversationService;
use App\Services\FriendService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Open a DM with someone.
 *
 * The reachability check is the important line here. Without it this endpoint is a way to
 * put a message in front of any account on the instance, which is spam — and a block
 * button afterwards doesn't un-deliver it. Sharing a server is the weakest thing that
 * still means "we have somewhere in common", and it's already how you'd have met them.
 * Being friends is the strongest, and it survives either of you leaving that server. A
 * block is the other direction entirely, and outranks both.
 */
class StoreDirectMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $me = $this->user();
                $other = User::find($this->integer('user_id'));

                if ($me === null || $other === null || $me->id === $other->id) {
                    return; // a DM with yourself is your own notes, and is allowed
                }

                // A block is the loudest possible "no", and it outranks every other reason
                // the two of you might be reachable — including a server you're both in.
                if (app(FriendService::class)->isBlockedEitherWay($me, $other)) {
                    $validator->errors()->add('user_id', 'You cannot message this person.');

                    return;
                }

                // Being friends *is* somewhere in common — it's the strongest form of it —
                // so it opens the same door a shared server does, and keeps it open after
                // one of you leaves that server.
                if (app(FriendService::class)->areFriends($me, $other)) {
                    return;
                }

                if (! app(ConversationService::class)->sharesAServerWith($me, $other)) {
                    $validator->errors()->add('user_id', 'You can only message people you share a server with, or your friends.');
                }
            },
        ];
    }
}
