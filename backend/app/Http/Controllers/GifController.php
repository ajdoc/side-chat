<?php

namespace App\Http\Controllers;

use App\Services\GifService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The composer's GIF picker. Proxies the configured providers (Giphy, Klipy) so their API
 * keys stay server-side, and returns their merged results plus the attribution labels.
 *
 * When no provider is configured both endpoints answer 422 (same shape as SpotifyController)
 * and the picker shows a "not configured" note.
 */
class GifController extends Controller
{
    public function __construct(private readonly GifService $gifs) {}

    /** Trending GIFs — the picker's default view. */
    public function featured(): JsonResponse
    {
        if (! $this->gifs->configured()) {
            return response()->json(['message' => 'GIFs are not configured.'], 422);
        }

        return response()->json([
            'data' => $this->gifs->featured(),
            'providers' => $this->gifs->providerLabels(),
        ]);
    }

    /** Search GIFs for `q`. */
    public function search(Request $request): JsonResponse
    {
        if (! $this->gifs->configured()) {
            return response()->json(['message' => 'GIFs are not configured.'], 422);
        }

        $query = (string) $request->query('q', '');

        return response()->json([
            'data' => $this->gifs->search($query),
            'providers' => $this->gifs->providerLabels(),
        ]);
    }
}
