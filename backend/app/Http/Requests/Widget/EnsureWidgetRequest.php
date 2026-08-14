<?php

namespace App\Http\Requests\Widget;

use App\Http\Requests\MemberRequest;
use App\Support\DeskApps;

/**
 * Open the channel's widget of a given type, creating it if it isn't there yet.
 *
 * Channel membership is the gate — the same one dropping a widget card on the canvas passes,
 * and creating a widget has always been an ordinary member power (typing `m!p` does it).
 */
class EnsureWidgetRequest extends MemberRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:'.implode(',', DeskApps::widgets())],
        ];
    }
}
