<?php

namespace App\Actions\SideChat;

use App\DTOs\SideChat\CreateSideChatData;
use App\Events\SideChatCreated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SideChat;
use App\Models\User;
use App\Services\SideChatService;
use Illuminate\Support\Str;

final class CreateSideChatAction
{
    public function __construct(private readonly SideChatService $sideChats) {}

    public function handle(Channel $channel, User $user, CreateSideChatData $data): SideChat
    {
        // Snapshot the origin message now, so "Started from …" survives it being deleted later.
        $origin = $data->message_id ? Message::with('user')->find($data->message_id) : null;

        $sideChat = $channel->sideChats()->create([
            'user_id' => $user->id,
            'message_id' => $data->message_id,
            'name' => $data->name,
            'origin_author' => $origin?->user?->name,
            'origin_excerpt' => $origin?->body ? Str::limit($origin->body, 280) : null,
        ]);

        // The creator is the first participant — the "owner" of the room they just opened.
        $sideChat->participants()->attach($user->id, ['role' => 'owner']);

        $this->sideChats->loadForDisplay($sideChat);

        broadcast(new SideChatCreated($sideChat));

        return $sideChat;
    }
}
