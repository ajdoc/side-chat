<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use Illuminate\Validation\Rule;

/**
 * The welcome message form. Writes a `member.joined` → `post_message` rule — see
 * BotDashboardController::updateWelcome.
 */
class UpdateWelcomeRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Null switches greeting off, and deletes the rule rather than disabling it.
            'channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $this->resolveServer()?->getKey()),
            ],
            // Required alongside a channel: a greeting with no words is not a greeting, and
            // the action would skip it every time anyway (see PostMessageAction).
            'body' => ['required_with:channel_id', 'nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
