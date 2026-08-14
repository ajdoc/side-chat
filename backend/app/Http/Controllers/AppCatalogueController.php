<?php

namespace App\Http\Controllers;

use App\Models\InstalledApp;
use App\Support\Apps\AppRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What apps exist, for the pickers that offer them.
 *
 * Two lists, kept apart on purpose. `built_in` is only *flags* — the client already ships the
 * label, icon and card size for every built-in, and sending those from here would be a second
 * source for one fact, drifting the moment a label is reworded. `installed` is the whole row,
 * because the client has never heard of those apps and has nothing to look them up in.
 *
 * Read-only, and open to any authenticated caller: the catalogue is not sensitive — it's the
 * same list the create-channel dialog draws — and gating it would only mean the picker renders
 * empty for the people allowed to use it.
 */
class AppCatalogueController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'built_in' => AppRegistry::builtIns(),
            'installed' => InstalledApp::query()
                ->where('enabled', true)
                ->orderBy('name')
                ->get()
                ->map(fn (InstalledApp $a) => [
                    'id' => $a->slug,
                    'name' => $a->name,
                    'description' => $a->description,
                    'icon' => $a->icon,
                    'version' => $a->version,
                    // An installed app is always a surface and always channelable — the two
                    // things it could be instead (a widget, a game) are built-in concepts with
                    // built-in renderers, which by definition an installed app doesn't have.
                    'family' => 'surface',
                    'desk' => true,
                    'channel' => true,
                ])
                ->all(),
        ]);
    }
}
