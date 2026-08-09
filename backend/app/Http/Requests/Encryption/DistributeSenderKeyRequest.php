<?php

namespace App\Http\Requests\Encryption;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use Illuminate\Validation\Validator;

/**
 * Handing out a sender key, wrapped once per recipient device.
 *
 * Channel membership is the gate — a sender key is only meaningful to people in the channel,
 * and {@see MemberRequest} already applies the private-channel access list on top. Which
 * *devices* may receive a row is checked again in the service, against the same roster: the
 * request proves the caller belongs here, the service proves each recipient does.
 *
 * The epoch is checked against the channel rather than trusted. A client that could name any
 * epoch could write rows into an era that hasn't started — harmless to read, but it would let
 * somebody pre-seed a future era with a key of their choosing and confuse clients about which
 * key is current the moment it began.
 */
class DistributeSenderKeyRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64'],
            'epoch' => ['required', 'integer', 'min:1'],
            'keys' => ['required', 'array', 'min:1', 'max:500'],
            'keys.*.recipient_device_key_id' => ['required', 'integer'],
            'keys.*.wrapped_key' => ['required', 'string', 'max:1024'],
            'keys.*.wrap_iv' => ['required', 'string', 'max:64'],
            'keys.*.ephemeral_public' => ['required', 'string', 'max:512'],
            'keys.*.prekey_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $channel = $this->route('channel');

                if (! $channel instanceof Channel) {
                    return;
                }

                // Distributing an *older* era is legitimate and common: a member on a new
                // device needs the keys for eras they were present for. Distributing a future
                // one never is.
                if ($this->integer('epoch') > (int) $channel->encryption_epoch) {
                    $validator->errors()->add('epoch', 'That key era hasn’t started in this channel.');
                }
            },
        ];
    }
}
