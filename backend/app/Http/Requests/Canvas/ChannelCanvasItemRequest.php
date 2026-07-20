<?php

namespace App\Http\Requests\Canvas;

use App\Http\Requests\MemberRequest;

/**
 * Create or change a card on a channel's Open Canvas. A channel has no roster, so membership
 * is the whole gate for both (via {@see MemberRequest}). One class serves store and update:
 * `kind`/`content` are required only when creating (POST); an update may carry just geometry.
 */
class ChannelCanvasItemRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CanvasItemRules::forMethod($this->isMethod('post'));
    }
}
