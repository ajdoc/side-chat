<?php

namespace App\Http\Requests\Conversation;

use App\DTOs\Conversation\AddMembersData;
use App\Http\Requests\MemberRequest;
use Illuminate\Validation\Validator;

/**
 * Add people to a group.
 *
 * Any member may, not just the owner — a group chat is a room, not a fiefdom, and who
 * added whom is recorded in the transcript as a system message either way.
 */
class AddMembersRequest extends MemberRequest
{
    use ChecksReachableMembers;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return AddMembersData::validationRules();
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->assertReachable($validator),
        ];
    }
}
