<?php

namespace App\Http\Controllers;

use App\Actions\Channel\CreateChannelAction;
use App\Actions\Channel\DeleteChannelAction;
use App\Actions\Channel\RenameChannelAction;
use App\DTOs\Channel\CreateChannelData;
use App\DTOs\Channel\UpdateChannelData;
use App\Http\Requests\Channel\DeleteChannelRequest;
use App\Http\Requests\Channel\IndexChannelRequest;
use App\Http\Requests\Channel\StoreChannelRequest;
use App\Http\Requests\Channel\UpdateChannelRequest;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Server;
use App\Services\ChannelService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ChannelController extends Controller
{
    public function __construct(private readonly ChannelService $channels) {}

    public function index(IndexChannelRequest $request, Server $server): AnonymousResourceCollection
    {
        return ChannelResource::collection($this->channels->forServer($server, $request->user()));
    }

    public function store(StoreChannelRequest $request, Server $server, CreateChannelAction $action): ChannelResource
    {
        return new ChannelResource(
            $action->handle($server, CreateChannelData::fromArray($request->validated()))
        );
    }

    /** Rename. Owner only — and only the name; a channel's type is what it *is*. */
    public function update(UpdateChannelRequest $request, Channel $channel, RenameChannelAction $action): ChannelResource
    {
        return new ChannelResource(
            $action->handle($channel, UpdateChannelData::fromArray($request->validated()))
        );
    }

    /** Owner only. Takes the channel's threads, messages and uploaded files with it. */
    public function destroy(DeleteChannelRequest $request, Channel $channel, DeleteChannelAction $action): Response
    {
        $action->handle($channel);

        return response()->noContent();
    }
}
