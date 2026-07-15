<?php

namespace App\Http\Requests\Conversation;

use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Open a DM with someone.
 *
 * The reachability check is the important line here. Without it this endpoint is a way to
 * put a message in front of any account on the instance, which is spam — and a block
 * button afterwards doesn't un-deliver it. Sharing a server is the weakest thing that
 * still means "we have somewhere in common", and it's already how you'd have met them.
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

                if (! app(ConversationService::class)->sharesAServerWith($me, $other)) {
                    $validator->errors()->add('user_id', 'You can only message people you share a server with.');
                }
            },
        ];
    }
}
