<?php

namespace App\Support;

use App\Http\Requests\Canvas\CanvasItemRules;
use App\Models\Widget;
use App\Support\Apps\AppRegistry;

/**
 * The widget half of the app catalogue, for the things that care about widgets specifically.
 *
 * ## What this is now
 *
 * It used to hold the whole catalogue: a `SURFACE_APPS` list, a `WIDGET_APPS` list, and an
 * `all()` over both. That made it the *second* server-side answer to "which apps exist", next
 * to what is now {@see AppRegistry} — and a second list is a second place to forget an app. It
 * was forgotten twice while the Tracker was added.
 *
 * So the catalogue moved to {@see AppRegistry} and what's left here is the one question that
 * genuinely isn't about the catalogue: **which ids resolve to a {@see Widget}**. That matters
 * to things with nothing to do with Side Desks — the `a!` commands, a canvas widget card, and
 * pressing E on Side Space furniture. Those callers ask about widgets, not about apps, and
 * pointing them at the app registry would have them depending on a fact they don't use.
 *
 * Both lists still come from {@see AppRegistry}, so there is exactly one place an app is
 * declared.
 *
 * @see CanvasItemRules  the widget card's validation, the same list
 */
final class DeskApps
{
    /**
     * Widgets promoted to apps — the ids that resolve to a Widget row rather than to storage
     * hanging off the surface.
     *
     * A method rather than the constant it used to be, because it's derived now. Callers that
     * used `DeskApps::WIDGET_APPS` become `DeskApps::widgets()`.
     *
     * @return array<int, string>
     */
    public static function widgets(): array
    {
        return array_values(array_filter(
            array_map(fn ($a) => $a['id'], AppRegistry::builtIns()),
            fn (string $id) => AppRegistry::isWidget($id),
        ));
    }

    /** Everything a Side Desk strip may store. Delegates; see the class note. */
    public static function all(): array
    {
        return AppRegistry::deskIds();
    }
}
