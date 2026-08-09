<?php

namespace App\Http\Requests\Encryption;

use App\Services\EncryptionKeyService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publishing this device's public keys.
 *
 * Authorised for any signed-in account, because it is only ever about the caller's *own*
 * device — the user id comes from the token, never from the payload, so there is no way to
 * register a device in somebody else's name.
 *
 * The validation is all shape and size. The server cannot check that these keys are
 * well-formed points on P-256, and shouldn't pretend to: it has no business parsing key
 * material, and a client that publishes rubbish only breaks its own sessions. What the
 * limits do is stop the directory being used as a place to store arbitrary data.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64'],
            'identity_public' => ['required', 'string', 'max:512'],
            'signing_public' => ['required', 'string', 'max:512'],
            'signed_prekey' => ['required', 'string', 'max:512'],
            'prekey_signature' => ['required', 'string', 'max:512'],

            // Optional on registration: a client may publish keys first and top up its stock
            // in a second call. Capped at the target so one request can't fill the table.
            'one_time_prekeys' => ['sometimes', 'array', 'max:'.EncryptionKeyService::PREKEY_TARGET],
            'one_time_prekeys.*.prekey_id' => ['required', 'string', 'max:64'],
            'one_time_prekeys.*.public_key' => ['required', 'string', 'max:512'],
        ];
    }
}
