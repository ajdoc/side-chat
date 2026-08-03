<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use Illuminate\Validation\Rule;

class StoreGiveawayRequest extends ServerStaffRequest
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
            // Announced in several rooms if asked. Entries stay unique per person.
            'extra_channel_ids' => ['nullable', 'array', 'max:9'],
            'extra_channel_ids.*' => [
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $serverId),
            ],
            'prize' => ['required', 'string', 'max:200'],
            'emoji' => ['sometimes', 'string', 'max:16'],
            // Twenty is well past what anybody runs, and an unbounded number would let one
            // giveaway name every member of the server a winner.
            'winner_count' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'required_badge_id' => [
                'nullable',
                'integer',
                Rule::exists('badges', 'id')->where('server_id', $serverId),
            ],
            // Must be in the future: a giveaway that closed before it opened would be drawn
            // on the next tick with nobody in it.
            'ends_at' => ['required', 'date', 'after:now'],
        ];
    }
}
