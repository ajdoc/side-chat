<?php

namespace App\Http\Requests\Tracker;

use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use App\Models\TrackerProject;
use Illuminate\Validation\Rule;

/**
 * Creating or renaming a project.
 *
 * The key is uppercased before validation rather than after, so the uniqueness rule and the
 * stored value are the same string — otherwise "hrip" would pass a check against a channel
 * that already has HRIP and then collide with it at the index.
 */
class StoreProjectRequest extends MemberRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('key')) {
            $this->merge(['key' => strtoupper(trim((string) $this->input('key')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        /** @var TrackerProject|null $project */
        $project = $this->route('project');

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'key' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:10',
                // Letters and digits only, starting with a letter: the key is half of a
                // reference people type into chat, and a space or a dash in it would make
                // "HRIP-1" ambiguous about where the key ends.
                'regex:/^[A-Z][A-Z0-9]*$/',
                Rule::unique('tracker_projects', 'key')
                    ->where('channel_id', $channel->getKey())
                    ->ignore($project?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.regex' => 'A project key is letters and digits, starting with a letter — like HRIP.',
            'key.unique' => 'Another project in this channel already uses that key.',
        ];
    }
}
