<?php

namespace App\Http\Controllers;

use App\Actions\Reaction\ToggleReactionAction;
use App\DTOs\Reaction\ToggleReactionData;
use App\Http\Requests\Reaction\ToggleReactionRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;

class ReactionController extends Controller
{
    /** Add or remove the caller's reaction; returns the refreshed message. */
    public function toggle(ToggleReactionRequest $request, Message $message, ToggleReactionAction $action): MessageResource
    {
        return new MessageResource(
            $action->handle($message, $request->user(), ToggleReactionData::fromArray($request->validated()))
        );
    }
}
