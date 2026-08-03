<?php

namespace App\Http\Requests\Automation;

use App\Http\Requests\ServerStaffRequest;
use Illuminate\Validation\Rule;

class StoreBadgeRequest extends ServerStaffRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $serverId = $this->resolveServer()?->getKey();
        // Null on create, the badge's own id on update — so renaming a badge to what it's
        // already called isn't a collision with itself.
        $badgeId = $this->route('badge')?->getKey();

        return [
            'name' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:32',
                // Two badges with one name are indistinguishable everywhere they're shown,
                // and a rule naming one of them would be picking at random.
                Rule::unique('badges', 'name')->where('server_id', $serverId)->ignore($badgeId),
            ],
            'emoji' => ['nullable', 'string', 'max:16'],
            // A hex colour, because that's what the swatch picker emits and what the badge
            // renders with.
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
