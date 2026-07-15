<?php

namespace App\Http\Requests\Server;

use App\DTOs\Server\BulkJoinRequestData;
use App\Http\Requests\MemberRequest;

/** Used by both approve and decline - a single action is a bulk of one. */
class BulkJoinRequestsRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return BulkJoinRequestData::validationRules();
    }
}
