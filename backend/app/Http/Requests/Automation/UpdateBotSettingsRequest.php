<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use App\Models\Server;
use Illuminate\Validation\Rule;

/**
 * The Configuration page's save.
 *
 * Every channel is checked against *this* server. A channel id is not a capability, and
 * without the scope somebody could point their mod log at a channel in a server they aren't
 * in and have our bot narrate their moderation into it.
 */
class UpdateBotSettingsRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $serverId = $this->resolveServer()?->getKey();

        $channel = [
            'nullable',
            'integer',
            Rule::exists('channels', 'id')->where('server_id', $serverId),
        ];

        return [
            // One character, and not whitespace — a prefix of ' ' would make every message
            // a command.
            'command_prefix' => ['sometimes', 'string', 'size:1', 'regex:/^\S$/'],
            'mod_log_channel_id' => $channel,
            'announcement_channel_id' => $channel,
            'reminder_channel_id' => $channel,
            // Empty is meaningful and is the default: moderation commands stay off until
            // somebody says who has them. See the bot_settings migration.
            'mod_roles' => ['nullable', 'array'],
            'mod_roles.*' => [Rule::in(Server::ROLES)],
        ];
    }
}
