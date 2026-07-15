<?php

namespace App\Http\Controllers;

use App\Actions\Message\TogglePinAction;
use App\Http\Requests\Channel\IndexPinnedRequest;
use App\Http\Requests\Message\TogglePinRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Services\PinService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PinController extends Controller
{
    public function __construct(private readonly PinService $pins) {}

    /** The channel's pinned messages — the Info > Pinned tab. */
    public function index(IndexPinnedRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return MessageResource::collection($this->pins->forChannel($channel));
    }

    /** Pin, or unpin if it's already pinned. Any member. */
    public function toggle(TogglePinRequest $request, Message $message, TogglePinAction $action): MessageResource
    {
        return new MessageResource($action->handle($message, $request->user()));
    }
}
