<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use App\Models\BotSchedule;
use Illuminate\Validation\Rule;

class StoreBotScheduleRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->isMethod('POST');

        return [
            'name' => [$post ? 'required' : 'sometimes', 'string', 'max:80'],
            'channel_id' => [
                'nullable',
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $this->resolveServer()?->getKey()),
            ],
            // Extra channels, beyond the first. Capped: a schedule that posts to twenty
            // rooms is an announcement nobody reads twice.
            'extra_channel_ids' => ['nullable', 'array', 'max:9'],
            'extra_channel_ids.*' => [
                'integer',
                Rule::exists('channels', 'id')->where('server_id', $this->resolveServer()?->getKey()),
            ],
            'body' => [$post ? 'required' : 'sometimes', 'string', 'max:2000'],
            'cron' => [
                $post ? 'required' : 'sometimes',
                'string',
                'max:64',
                // Checked against the parser that will actually run it, not a regex that
                // approximates cron — an expression this accepts and the runner can't read
                // would be a schedule that silently never fires.
                fn (string $attribute, mixed $value, callable $fail) => BotSchedule::validCron((string) $value)
                    ?: $fail('That isn’t a schedule we can read.'),
            ],
            // Against PHP's own list, so "every Monday at 9" means nine somewhere real.
            'timezone' => ['sometimes', 'string', 'timezone'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
