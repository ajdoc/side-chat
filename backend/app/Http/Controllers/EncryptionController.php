<?php

namespace App\Http\Controllers;

use App\Actions\Channel\ToggleChannelEncryptionAction;
use App\Http\Requests\Channel\ToggleEncryptionRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;

/**
 * The encryption switch on a channel.
 *
 * One endpoint for now. Device keys, prekeys and sender-key distribution land here too
 * once there is anything to encrypt with — this phase is the flag and its consequences,
 * which are worth proving on their own: every feature that reads a message body has to
 * degrade and recover correctly before a single byte is ciphertext.
 */
class EncryptionController extends Controller
{
    public function toggle(ToggleEncryptionRequest $request, Channel $channel, ToggleChannelEncryptionAction $action): ChannelResource
    {
        return new ChannelResource(
            $action->handle($channel, $request->user(), $request->boolean('encrypted'))
        );
    }
}
