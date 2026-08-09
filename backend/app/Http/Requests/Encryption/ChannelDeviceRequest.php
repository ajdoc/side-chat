<?php

namespace App\Http\Requests\Encryption;

use App\Http\Requests\MemberRequest;

/**
 * Asking who else is in this channel, cryptographically speaking — and fetching one's own
 * sender-key inbox.
 *
 * Membership-gated for a reason beyond the obvious. Fetching bundles *consumes* a one-time
 * prekey from every device it returns, so an open version of this endpoint would let anyone
 * drain every account's stock at will, forcing the whole server onto sessions without
 * forward secrecy. It would also answer "how many devices has this person got, and when were
 * they last seen" for any account. Inside a channel the caller is already in, both are things
 * they can largely infer anyway.
 */
class ChannelDeviceRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Which of the caller's own devices is asking. Excluded from the bundle list, and
            // the addressee of the inbox.
            'device_id' => ['required', 'string', 'max:64'],

            // Bundles only: narrow the answer to devices the caller knows it hasn't reached.
            // Bounded because each entry costs somebody a one-time prekey.
            'device_key_ids' => ['sometimes', 'array', 'max:500'],
            'device_key_ids.*' => ['integer'],
        ];
    }
}
