<?php

namespace App\Http\Controllers;

use App\Actions\SideChat\ToggleDecisionAction;
use App\Http\Requests\SideChat\ToggleDecisionRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;

class DecisionController extends Controller
{
    /** Record a side-chat message as a decision, or take the mark back. Participants only. */
    public function toggle(ToggleDecisionRequest $request, Message $message, ToggleDecisionAction $action): MessageResource
    {
        return new MessageResource($action->handle($message, $request->user()));
    }
}
