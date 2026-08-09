<?php

namespace App\Http\Requests\Encryption;

use App\Services\EncryptionKeyService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Topping up a device's stock of single-use prekeys.
 *
 * Its own endpoint rather than part of registration because the two happen on different
 * schedules: registration is once per install, refilling is whenever the stock runs low,
 * which for a busy account is often. See {@see DeviceKey::claimPrekey()} for what drains it.
 */
class StorePrekeysRequest extends FormRequest
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
            'one_time_prekeys' => ['required', 'array', 'min:1', 'max:'.EncryptionKeyService::PREKEY_TARGET],
            'one_time_prekeys.*.prekey_id' => ['required', 'string', 'max:64'],
            'one_time_prekeys.*.public_key' => ['required', 'string', 'max:512'],
        ];
    }
}
