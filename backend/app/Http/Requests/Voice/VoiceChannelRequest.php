<?php

namespace App\Http\Requests\Voice;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use Illuminate\Validation\Validator;

/**
 * Anything done inside a call: membership (inherited) plus the channel being somewhere a
 * call is allowed to happen at all — a server's voice channel, or a DM/group chat, whose
 * single channel doubles as the call. See Channel::allowsCalls().
 *
 * A 422 rather than a 404, because #general exists — you just can't talk into it.
 *
 * Used directly by the endpoints that take no body (leave, heartbeat), and extended by
 * the ones that do.
 */
class VoiceChannelRequest extends MemberRequest
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $channel = $this->route('channel');

                if ($channel instanceof Channel && ! $channel->allowsCalls()) {
                    $validator->errors()->add('channel', 'This is not a voice channel.');
                }
            },
        ];
    }
}
