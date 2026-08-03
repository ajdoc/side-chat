<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use Illuminate\Validation\Rule;

class StoreReactionRoleRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $serverId = $this->resolveServer()?->getKey();

        return [
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $serverId),
            ],
            // The same post, in several rooms. Each gets its own message and its own rules,
            // so reacting in any of them grants the badge.
            'extra_channel_ids' => ['nullable', 'array', 'max:9'],
            'extra_channel_ids.*' => [
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $serverId),
            ],
            'body' => ['required', 'string', 'max:2000'],
            'pairs' => ['required', 'array', 'min:1', 'max:20'],
            'pairs.*.emoji' => ['required', 'string', 'max:16'],
            'pairs.*.badge_id' => [
                'required',
                'integer',
                // Scoped: a badge from another server is not one this message may hand out.
                Rule::exists('badges', 'id')->where('server_id', $serverId),
            ],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $emoji = collect($this->input('pairs', []))->pluck('emoji')->filter();

            // One emoji can't mean two badges on the same message — both rules would fire
            // and the second badge would look like a bug rather than a choice.
            if ($emoji->count() !== $emoji->unique()->count()) {
                $validator->errors()->add('pairs', 'Each emoji can only appear once on a message.');
            }
        }];
    }
}
