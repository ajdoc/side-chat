<?php

namespace App\Http\Controllers;

use App\Http\Requests\Channel\ShowNotificationSettingRequest;
use App\Http\Requests\Channel\UpdateNotificationSettingRequest;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Notifications\NotificationSettingService;
use App\Support\Notifications\NotifyLevel;
use Illuminate\Http\JsonResponse;

/**
 * Per-place notification settings — one channel, or a chat's single channel.
 *
 * The conversation routes exist because that's the id the client has: the DM list is a
 * list of conversations and never mentions the channel inside one. They resolve to the
 * same row and the same service.
 */
class NotificationSettingController extends Controller
{
    public function __construct(private readonly NotificationSettingService $settings) {}

    public function show(ShowNotificationSettingRequest $request, Channel $channel): JsonResponse
    {
        return response()->json($this->settings->show($channel, $request->user()));
    }

    public function update(UpdateNotificationSettingRequest $request, Channel $channel): JsonResponse
    {
        return response()->json($this->apply($request, $channel));
    }

    public function showForConversation(ShowNotificationSettingRequest $request, Conversation $conversation): JsonResponse
    {
        return response()->json($this->settings->show($this->channelOf($conversation), $request->user()));
    }

    public function updateForConversation(UpdateNotificationSettingRequest $request, Conversation $conversation): JsonResponse
    {
        return response()->json($this->apply($request, $this->channelOf($conversation)));
    }

    /**
     * Write whichever of the two settings the request actually mentioned.
     *
     * `has()` rather than reading the value, because null is a real instruction here — it
     * means "clear this" — and is indistinguishable from "not sent" any other way. A menu
     * that changes the level must not silently lift a mute as a side effect.
     *
     * @return array<string, mixed>
     */
    private function apply(UpdateNotificationSettingRequest $request, Channel $channel): array
    {
        $this->settings->set(
            $channel,
            $request->user(),
            NotifyLevel::parse($request->input('notify_level')),
            $request->has('mute_minutes') ? $request->integer('mute_minutes') ?: null : null,
            touchLevel: $request->has('notify_level'),
            touchMute: $request->has('mute_minutes'),
        );

        return $this->settings->show($channel, $request->user());
    }

    /** A conversation has exactly one channel, and that's where its messages live. */
    private function channelOf(Conversation $conversation): Channel
    {
        return $conversation->loadMissing('channel')->channel;
    }
}
