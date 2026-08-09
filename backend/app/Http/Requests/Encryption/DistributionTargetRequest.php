<?php

namespace App\Http\Requests\Encryption;

use App\Http\Requests\MemberRequest;

/**
 * Asking which devices in this channel still need a sender's chain for an era.
 *
 * Membership-gated like everything channel-scoped, and cheap: it reads rows and consumes
 * nothing, so unlike a bundle fetch there is no cost to asking often. That matters, because
 * a client asks on every channel open.
 */
class DistributionTargetRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64'],
            'epoch' => ['required', 'integer', 'min:1'],
        ];
    }
}
