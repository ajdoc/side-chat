<?php

namespace App\Http\Requests\Encryption;

use App\Http\Requests\MemberRequest;

/**
 * Discarding a sender key this device cannot open.
 *
 * Destructive, and the destruction is somebody else's row — so the service scopes the delete
 * to keys addressed to the caller's *own* device. Being able to name an arbitrary recipient
 * would be a way to quietly cut a third party out of a conversation by deleting the key they
 * were about to read it with.
 */
class RejectSenderKeyRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Which of the caller's own devices is giving up on the key.
            'device_id' => ['required', 'string', 'max:64'],
            'epoch' => ['required', 'integer', 'min:1'],
            // Whose key it is — the sending device, as named in the message envelope.
            'sender_device_id' => ['required', 'string', 'max:64'],
        ];
    }
}
