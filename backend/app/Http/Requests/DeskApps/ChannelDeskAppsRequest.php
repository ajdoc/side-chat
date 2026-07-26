<?php

namespace App\Http\Requests\DeskApps;

use App\Http\Requests\MemberRequest;

/**
 * Rearrange a channel's Side Desk tabs. A channel has no roster, so membership is the whole
 * gate — the same stance its canvas, notes and calendar take.
 */
class ChannelDeskAppsRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return DeskAppsRules::rules();
    }
}
