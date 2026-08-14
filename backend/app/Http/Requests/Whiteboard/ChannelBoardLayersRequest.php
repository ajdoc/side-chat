<?php

namespace App\Http\Requests\Whiteboard;

use App\Http\Requests\MemberRequest;

/**
 * Rearranging a channel's board layers. A channel has no roster, so membership is the whole
 * gate — the same stance the strokes themselves take.
 */
class ChannelBoardLayersRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return BoardLayerRules::rules();
    }
}
