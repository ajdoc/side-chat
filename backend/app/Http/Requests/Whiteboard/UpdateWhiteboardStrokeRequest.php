<?php

namespace App\Http\Requests\Whiteboard;

/**
 * Moving or resizing a stroke on a side chat's board — the roster gate from {@see
 * ManageWhiteboardRequest} plus the payload validation from {@see StrokeRules}. Only the
 * geometry changes; `kind` and `client_id` stay put, so this validates `payload` alone.
 */
class UpdateWhiteboardStrokeRequest extends ManageWhiteboardRequest
{
    use StrokeRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->payloadRules();
    }
}
