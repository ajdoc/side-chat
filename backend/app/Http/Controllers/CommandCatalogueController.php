<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\ViewCommandsRequest;
use App\Models\Channel;
use App\Services\Commands\SlashCommandService;
use Illuminate\Http\JsonResponse;

/**
 * Every `/command` callable in a channel — what the composer's autocomplete is built from.
 *
 * Per channel rather than per server because the answer genuinely differs: a bot that isn't
 * in this private channel can't be called from it, and listing its commands here would
 * offer people something that won't work. Same list `/help` prints, from the same method.
 */
class CommandCatalogueController extends Controller
{
    public function __invoke(ViewCommandsRequest $request, Channel $channel, SlashCommandService $slash): JsonResponse
    {
        return response()->json(['data' => $slash->catalogue($channel, $request->user())]);
    }
}
