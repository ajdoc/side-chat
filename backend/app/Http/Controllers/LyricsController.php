<?php

namespace App\Http\Controllers;

use App\Services\Widgets\LyricsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lyrics for the music widget's karaoke view.
 *
 * A proxy rather than a browser-side fetch for two reasons: LRCLIB sets no CORS headers we
 * can rely on, and every listener in a room asks for the same track at the same moment —
 * one cached server lookup serves them all. See {@see LyricsResolver}.
 */
class LyricsController extends Controller
{
    public function __construct(private readonly LyricsResolver $lyrics) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:300'],
            'artist' => ['nullable', 'string', 'max:200'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:36000'],
        ]);

        $found = $this->lyrics->find(
            $data['title'],
            $data['artist'] ?? null,
            isset($data['duration']) ? (int) $data['duration'] : null,
        );

        // A miss is an ordinary outcome, not a 404 — the pane just says "no lyrics found".
        return response()->json(['lyrics' => $found]);
    }
}
