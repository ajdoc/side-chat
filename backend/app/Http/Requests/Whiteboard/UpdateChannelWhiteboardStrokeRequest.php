<?php

namespace App\Http\Requests\Whiteboard;

/**
 * Moving or resizing a stroke on a channel's board — channel membership (from {@see
 * ChannelWhiteboardRequest}) plus the payload validation from {@see StrokeRules}.
 */
class UpdateChannelWhiteboardStrokeRequest extends ChannelWhiteboardRequest
{
    use StrokeRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->payloadRules();
    }
}
