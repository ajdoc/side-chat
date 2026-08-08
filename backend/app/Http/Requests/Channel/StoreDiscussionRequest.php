<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use App\Models\Server;
use Illuminate\Validation\Rule;

/**
 * Adding a discussion to a channel.
 *
 * Two gates. MemberRequest's, which asks the *channel* and so already refuses anyone a private
 * channel is hidden from; and the server's own `discussion_creation` policy on top. A DM or a
 * group chat has no such policy — there is nobody to be staff — so anyone in the conversation
 * may add one.
 */
class StoreDiscussionRequest extends MemberRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $container = $this->resolveContainer();
        $user = $this->user();

        return ! $container instanceof Server || $container->canCreateDiscussions($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Channel $parent */
        $parent = $this->route('channel');

        return [
            'name' => ['required', 'string', 'max:100'],
            // Which sibling's room the new one starts as a copy of. Only meaningful for a Side
            // Space, and constrained to this channel's own discussions — "copy from" is not a
            // way to read a map out of a channel you can't see.
            'copy_from' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('parent_id', $parent->getKey()),
            ],
        ];
    }
}
