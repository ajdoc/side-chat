<?php

namespace App\Http\Requests\DeskApps;

use App\Support\Apps\AppRegistry;

/**
 * Validation for a Side Desk's tab strip, shared by the channel and side chat gates so both
 * accept the same body.
 *
 * The array *is* the order — the client sends the strip as it should read, left to right — so
 * there is no separate position field to keep in sync. `distinct` matters more than it looks:
 * the same app twice would render two tabs over one piece of state, and removing "the" tab
 * would leave its twin behind.
 */
final class DeskAppsRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            // `present`, not `required`: an empty strip is a legitimate desk (the Open Canvas is
            // pinned by the client and never stored), and `required` rejects an empty array.
            'apps' => ['present', 'array', 'max:20'],
            'apps.*' => ['string', 'distinct', 'in:'.implode(',', AppRegistry::deskIds())],
        ];
    }
}
