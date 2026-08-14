<?php

namespace App\Http\Requests\Tracker;

use App\Http\Requests\MemberRequest;
use App\Support\Tracker\TrackerFields;

/**
 * Creating or recolouring a tag in a channel's vocabulary.
 *
 * Uniqueness isn't checked here: the controller resolves an existing tag by its normalized
 * name and hands it back instead of failing. Typing a tag that already exists is the normal
 * way to reuse one, not an error to correct.
 */
class StoreAppTagRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:40'],
            'color' => ['sometimes', 'string', 'in:'.implode(',', TrackerFields::TAG_COLORS)],
        ];
    }
}
