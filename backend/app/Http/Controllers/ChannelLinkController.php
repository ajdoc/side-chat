<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\IndexChannelLinkRequest;
use App\Http\Resources\ChannelLinkResource;
use App\Models\Channel;
use App\Services\LinkPreviewService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChannelLinkController extends Controller
{
    public function __construct(private readonly LinkPreviewService $links) {}

    /** Every link shared in this channel — the Info > Links tab. */
    public function index(IndexChannelLinkRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return ChannelLinkResource::collection($this->links->forChannel($channel));
    }
}
