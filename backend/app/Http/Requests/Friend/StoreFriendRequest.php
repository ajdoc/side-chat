<?php

namespace App\Http\Requests\Friend;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Ask someone to be friends — by id if you can see them, by name if you can't.
 *
 * The two ways in are deliberate. `user_id` is what a button next to somebody's face
 * sends: you're already looking at them. `name` is the "add by username" box, and it
 * matches *exactly* on purpose — a partial search here would be a way to page through
 * every account on the instance, and finding people you don't already know isn't what a
 * friend list is for.
 */
class StoreFriendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required_without:name', 'integer', 'exists:users,id'],
            'name' => ['required_without:user_id', 'string', 'max:255'],
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

                if ($this->target() === null) {
                    $validator->errors()->add('name', 'No account by that name.');
                }
            },
        ];
    }

    /** Whoever is being asked, however they were addressed. */
    public function target(): ?User
    {
        if ($this->filled('user_id')) {
            return User::find($this->integer('user_id'));
        }

        return User::where('name', $this->string('name')->toString())->first();
    }
}
