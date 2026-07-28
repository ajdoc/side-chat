<?php

namespace App\Http\Requests\Server;

use App\Http\Requests\ServerOwnerRequest;
use App\Models\Server;
use Illuminate\Validation\Rule;

/**
 * Promote a member to admin, or put them back. Owner only, deliberately: an admin who can
 * appoint admins can appoint themselves a successor and the owner's last distinct power
 * would be gone. See ServerStaffRequest for everything the two *do* share.
 */
class UpdateMemberRoleRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(Server::ROLES)],
        ];
    }
}
