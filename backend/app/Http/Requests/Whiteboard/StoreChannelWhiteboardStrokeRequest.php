<?php

namespace App\Http\Requests\Whiteboard;

/**
 * A committed stroke on a channel's board: channel membership (from {@see
 * ChannelWhiteboardRequest}) plus the shared {@see StrokeRules} validation.
 */
class StoreChannelWhiteboardStrokeRequest extends ChannelWhiteboardRequest
{
    use StrokeRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->strokeRules();
    }
}
