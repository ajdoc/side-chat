<?php

namespace App\Http\Controllers;

use App\Actions\Channel\MarkChannelReadAction;
use App\Http\Requests\Channel\IndexChannelReadRequest;
use App\Http\Requests\Channel\MarkChannelReadRequest;
use App\Http\Resources\ChannelReadResource;
use App\Models\Channel;
use App\Services\ReadReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReadReceiptController extends Controller
{
    public function __construct(private readonly ReadReceiptService $reads) {}

    /** Where every member of this channel has read up to — the seen-by avatars. */
    public function index(IndexChannelReadRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return ChannelReadResource::collection($this->reads->forChannel($channel));
    }

    /** Advance the caller's read marker (defaults to the newest message in the channel). */
    public function store(MarkChannelReadRequest $request, Channel $channel, MarkChannelReadAction $action): JsonResponse
    {
        $read = $action->handle($channel, $request->user(), $request->integer('message_id') ?: null);

        return response()->json([
            'last_read_message_id' => $read?->last_read_message_id,
        ]);
    }
}
