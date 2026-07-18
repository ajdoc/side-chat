<?php

namespace App\Http\Requests\Whiteboard;

/**
 * A committed stroke on a side chat's board: the roster gate from {@see
 * ManageWhiteboardRequest} plus the shared {@see StrokeRules} validation.
 */
class StoreWhiteboardStrokeRequest extends ManageWhiteboardRequest
{
    use StrokeRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->strokeRules();
    }
}
