<?php

namespace App\Http\Requests\Server;

use App\DTOs\Server\BulkJoinRequestData;
use App\Http\Requests\ServerStaffRequest;

/**
 * Used by both approve and decline — a single action is a bulk of one.
 *
 * Staff only. Deciding who gets into the server is the first thing an owner wants help
 * with and the first thing a stranger shouldn't be able to do, so it moved off plain
 * membership the moment there was an admin role to move it to.
 */
class BulkJoinRequestsRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return BulkJoinRequestData::validationRules();
    }
}
