<?php

namespace App\Http\Controllers;

use App\Actions\Voice\JoinVoiceChannelAction;
use App\Actions\Voice\LeaveVoiceChannelAction;
use App\Actions\Voice\UpdateVoiceStateAction;
use App\DTOs\Voice\UpdateVoiceStateData;
use App\Http\Requests\Voice\IndexVoiceRequest;
use App\Http\Requests\Voice\JoinVoiceChannelRequest;
use App\Http\Requests\Voice\UpdateVoiceStateRequest;
use App\Http\Requests\Voice\VoiceChannelRequest;
use App\Http\Resources\VoiceParticipantResource;
use App\Models\Channel;
use App\Models\Server;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The bookkeeping around a call — not the call itself. Audio and screen data never touch
 * this server: they go peer-to-peer, negotiated over the `voice.{id}` presence channel.
 * What's here is who's in the room, what they say they're doing, and the ICE servers the
 * browser needs to find its way to the other end.
 */
class VoiceController extends Controller
{
    public function __construct(private readonly VoiceService $voice) {}

    /**
     * Every occupied voice channel in a server, keyed by channel id. Feeds the faces under
     * each voice channel in the sidebar, for members who aren't in any call.
     */
    public function index(IndexVoiceRequest $request, Server $server): JsonResponse
    {
        $roster = $this->voice->rosterForServer($server);

        return response()->json([
            'data' => array_map(
                fn ($participants) => VoiceParticipantResource::collection($participants)->resolve(),
                $roster,
            ),
        ]);
    }

    /**
     * Take a seat, and get back everything needed to open the peer connections: who else is
     * in there, and where to find the STUN/TURN servers.
     */
    public function join(JoinVoiceChannelRequest $request, Channel $channel, JoinVoiceChannelAction $action): JsonResponse
    {
        $action->handle($channel, $request->user());

        return response()->json([
            'data' => VoiceParticipantResource::collection($this->voice->participants($channel))->resolve(),
            'ice_servers' => $this->voice->iceServers(),
            'max_participants' => (int) config('webrtc.max_participants'),
        ]);
    }

    public function leave(VoiceChannelRequest $request, Channel $channel, LeaveVoiceChannelAction $action): Response
    {
        $action->handle($channel, $request->user());

        return response()->noContent();
    }

    /** Publish a change to your own mic / deafen / screen-share state. */
    public function updateState(UpdateVoiceStateRequest $request, Channel $channel, UpdateVoiceStateAction $action): JsonResponse
    {
        $participant = $action->handle(
            $channel,
            $request->user(),
            UpdateVoiceStateData::fromArray($request->validated()),
        );

        return response()->json([
            'data' => $participant ? (new VoiceParticipantResource($participant))->resolve() : null,
        ]);
    }

    /**
     * "Still here." Keeps the row alive so a browser that dies without leaving eventually
     * stops showing up as a ghost in the sidebar. Cheap on purpose: a touch, no broadcast.
     */
    public function heartbeat(VoiceChannelRequest $request, Channel $channel, UpdateVoiceStateAction $action): Response
    {
        $action->handle($channel, $request->user(), UpdateVoiceStateData::fromArray([]));

        return response()->noContent();
    }
}
