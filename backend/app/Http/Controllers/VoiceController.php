<?php

namespace App\Http\Controllers;

use App\Actions\Voice\DisconnectVoiceParticipantsAction;
use App\Actions\Voice\JoinVoiceChannelAction;
use App\Actions\Voice\LeaveVoiceChannelAction;
use App\Actions\Voice\MuteVoiceParticipantAction;
use App\Actions\Voice\UpdateVoiceEffectsAction;
use App\Actions\Voice\UpdateVoiceStateAction;
use App\DTOs\Voice\UpdateVoiceEffectsData;
use App\DTOs\Voice\UpdateVoiceStateData;
use App\Http\Requests\Voice\DisconnectVoiceParticipantsRequest;
use App\Http\Requests\Voice\IndexVoiceRequest;
use App\Http\Requests\Voice\JoinVoiceChannelRequest;
use App\Http\Requests\Voice\MuteVoiceParticipantRequest;
use App\Http\Requests\Voice\UpdateVoiceEffectsRequest;
use App\Http\Requests\Voice\UpdateVoiceStateRequest;
use App\Http\Requests\Voice\VoiceChannelRequest;
use App\Http\Resources\VoiceParticipantResource;
use App\Http\Requests\Voice\RecordCallRequest;
use App\Actions\Message\PostSystemMessageAction;
use App\Events\VoiceStateUpdated;
use App\Models\VoiceParticipant;
use App\Models\Channel;
use App\Models\Server;
use App\Services\Sfu\VoiceTransportResolver;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The bookkeeping around a call — not the call itself. Audio and screen data never touch
 * *this* server: they go peer-to-peer, negotiated over the `voice.{id}` presence channel, or
 * else through an SFU that is somebody else's box entirely. What's here is who's in the room,
 * what they say they're doing, which of those two shapes the call takes, and the ICE servers
 * the browser needs to find its way to the other end.
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
    public function join(
        JoinVoiceChannelRequest $request,
        Channel $channel,
        JoinVoiceChannelAction $action,
        VoiceTransportResolver $transports,
    ): JsonResponse {
        $action->handle($channel, $request->user());

        $participants = $this->voice->participants($channel);

        // How this call should be carried. Resolved *after* the join, so the occupancy the
        // decision is made on includes everyone actually in the room — the joiner among them,
        // which is why the resolver isn't told to add one itself.
        $transport = $transports->resolve($channel, $request->user(), max(0, $participants->count() - 1));

        return response()->json([
            'data' => VoiceParticipantResource::collection($participants)->resolve(),
            'transport' => $transport['transport'],
            'sfu' => $transport['sfu'],
            // Always sent, whichever transport was chosen: a mesh call needs them, and so does
            // the client's fallback out of an SFU that fails after this response was written.
            'ice_servers' => $this->voice->iceServers(),
            'max_participants' => (int) config('webrtc.max_participants'),
            // What the room plays when somebody arrives or leaves. Handed over on join so
            // the very first arrival after yours already has it — an effect that had to be
            // fetched would miss the event it exists for.
            'effects' => $channel->voiceEffects(),
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

    /**
     * What this call plays for each person, and what it does for everybody else. Read by the
     * owner's settings dialog; the people in a call get the same payload handed to them on
     * join, and on VoiceEffectsUpdated after that.
     */
    public function effects(VoiceChannelRequest $request, Channel $channel): JsonResponse
    {
        return response()->json(['data' => $channel->voiceEffects()]);
    }

    /**
     * Attach an effect to one person — or, with no `user_id`, set the room's default for
     * everybody nobody has singled out. Owner only; see UpdateVoiceEffectsRequest for why
     * this one isn't open to the room the way muting and disconnecting are.
     */
    public function updateEffects(UpdateVoiceEffectsRequest $request, Channel $channel, UpdateVoiceEffectsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle($channel, UpdateVoiceEffectsData::fromArray($request->validated())),
        ]);
    }

    /**
     * Start or stop recording the call — the *announcement* half of it.
     *
     * The bytes never come here. Mixing and encoding happen in the browser that pressed the
     * button (see the client's useCallRecorder), and the finished file arrives later as an
     * ordinary upload. What the server owns is the part everybody else needs *while* it happens:
     * the flag on the participant, which every client already renders off the roster, and a line
     * in the timeline saying who started it.
     *
     * Both, deliberately. The flag is live and disappears with the call; the message outlives it.
     * "Was this meeting recorded?" is a question people ask afterwards, and a badge that vanished
     * when the room emptied cannot answer it.
     */
    public function record(RecordCallRequest $request, Channel $channel, PostSystemMessageAction $system): JsonResponse
    {
        $user = $request->user();
        $recording = $request->boolean('recording');

        $participant = VoiceParticipant::query()
            ->where('channel_id', $channel->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        // You can only record a call you are in. Not an error worth raising — the client asks
        // this on the way out of a room too.
        if ($participant === null) {
            return response()->json(['recording' => false]);
        }

        if ((bool) $participant->recording === $recording) {
            return response()->json(['recording' => $recording]);
        }

        $participant->update(['recording' => $recording, 'last_seen_at' => now()]);

        broadcast(new VoiceStateUpdated($channel));

        $system->handle($channel, $user, $recording
            ? '🔴 **Recording started.** Everyone in the call can see that it is being recorded.'
            : '⏹️ **Recording stopped.** The file will appear here once it has finished uploading.');

        return response()->json(['recording' => $recording]);
    }

    /**
     * Mute or unmute somebody else's microphone. Owner only — see MuteVoiceParticipantRequest
     * for why this one is not open to the room the way disconnecting is.
     */
    public function mute(MuteVoiceParticipantRequest $request, Channel $channel, MuteVoiceParticipantAction $action): JsonResponse
    {
        $applied = $action->handle($channel, $request->integer('user_id'), $request->boolean('muted'));

        return response()->json(['applied' => $applied]);
    }

    /**
     * Disconnect someone else from the call, or clear the whole room — open to any member of
     * the channel. With a `user_id`, that one person; without, everyone but you. Each removal
     * takes the ordinary leave path, so a chat's call ends normally when the last person is
     * turned out.
     */
    public function disconnect(DisconnectVoiceParticipantsRequest $request, Channel $channel, DisconnectVoiceParticipantsAction $action): JsonResponse
    {
        $count = $action->handle(
            $channel,
            $request->user(),
            $request->filled('user_id') ? $request->integer('user_id') : null,
        );

        return response()->json(['disconnected' => $count]);
    }
}
