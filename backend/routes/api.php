<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JoinRequestController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageInfoController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReadReceiptController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\ThreadMessageController;
use App\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
]));

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Social login (Google, Facebook).
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);

    // Authenticated.
    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::patch('preferences', [PreferencesController::class, 'update']);
});

// Servers, channels, and messages.
Route::middleware('auth:api')->group(function () {
    Route::get('servers', [ServerController::class, 'index']);
    Route::post('servers', [ServerController::class, 'store']);
    Route::get('servers/{server}', [ServerController::class, 'show']);
    // Rename. Owner only, like the delete below it.
    Route::patch('servers/{server}', [ServerController::class, 'update']);
    // Owner only — deletes every channel, message and uploaded file in it.
    Route::delete('servers/{server}', [ServerController::class, 'destroy']);
    // Any member. The owner can't leave their own server; they delete it instead.
    Route::post('servers/{server}/leave', [ServerController::class, 'leave']);

    Route::get('servers/{server}/channels', [ChannelController::class, 'index']);
    Route::post('servers/{server}/channels', [ChannelController::class, 'store']);
    // Rename (name only — a channel's type is not editable). Owner only.
    Route::patch('channels/{channel}', [ChannelController::class, 'update']);
    // Owner only — deletes the channel's threads, messages and uploaded files.
    Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);

    Route::get('channels/{channel}/messages', [MessageController::class, 'index']);
    Route::post('channels/{channel}/messages', [MessageController::class, 'store']);
    // Edit/delete works for both channel and thread messages (sender-only).
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);

    // Reactions: any server member may react, on channel *and* thread messages.
    Route::post('messages/{message}/reactions', [ReactionController::class, 'toggle']);

    // "Message info": who saw it, who hasn't, who reacted.
    Route::get('messages/{message}/info', [MessageInfoController::class, 'show']);

    // Pins: any member may pin or unpin, on channel *and* thread messages.
    Route::get('channels/{channel}/pins', [PinController::class, 'index']);
    Route::post('messages/{message}/pin', [PinController::class, 'toggle']);

    // Read receipts.
    Route::get('channels/{channel}/reads', [ReadReceiptController::class, 'index']);
    Route::post('channels/{channel}/read', [ReadReceiptController::class, 'store']);

    // Invites & join requests.
    Route::get('invites/{code}', [InviteController::class, 'show']);
    Route::post('invites/{code}/join', [InviteController::class, 'join']);
    Route::get('servers/{server}/join-requests', [JoinRequestController::class, 'index']);
    Route::post('servers/{server}/join-requests/approve', [JoinRequestController::class, 'approve']);
    Route::post('servers/{server}/join-requests/decline', [JoinRequestController::class, 'decline']);

    // Attachments and links (the channel Info panel's tabs).
    Route::get('channels/{channel}/attachments', [AttachmentController::class, 'indexForChannel']);
    Route::get('channels/{channel}/links', [ChannelLinkController::class, 'index']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // Voice. Signalling and media don't come through here — see routes/channels.php. This
    // is only the roster the sidebar reads, and the ICE servers handed out on join.
    Route::get('servers/{server}/voice', [VoiceController::class, 'index']);
    Route::post('channels/{channel}/voice/join', [VoiceController::class, 'join']);
    Route::post('channels/{channel}/voice/leave', [VoiceController::class, 'leave']);
    Route::patch('channels/{channel}/voice/state', [VoiceController::class, 'updateState']);
    Route::post('channels/{channel}/voice/heartbeat', [VoiceController::class, 'heartbeat']);

    /*
     * DMs and group chats.
     *
     * Short, and that's the point. A conversation owns a Channel, so messages, edits,
     * reactions, pins, threads, attachments, read receipts, typing *and the call itself*
     * are all served by the channel routes above — a chat is addressed by its `channel_id`
     * exactly like #general is. What's left here is only what a chat has that a channel
     * doesn't: who's in it, what it's called, and a ringing phone you'd like to silence.
     */
    Route::get('conversations', [ConversationController::class, 'index']);
    Route::get('conversations/contacts', [ConversationController::class, 'contacts']);
    Route::post('conversations/dm', [ConversationController::class, 'storeDirect']);
    Route::post('conversations/group', [ConversationController::class, 'storeGroup']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
    // Rename a group. Owner only — a DM is named after whoever you're talking to.
    Route::patch('conversations/{conversation}', [ConversationController::class, 'update']);
    Route::post('conversations/{conversation}/members', [ConversationController::class, 'addMembers']);
    // Any member. You can't leave a DM; there's nothing to leave it to.
    Route::post('conversations/{conversation}/leave', [ConversationController::class, 'leave']);
    // The call. Joining one is `channels/{channel}/voice/join` like anywhere else — these
    // two are the parts a server's voice channel has no need for.
    Route::get('conversations/{conversation}/voice', [ConversationController::class, 'voice']);
    Route::post('conversations/{conversation}/call/decline', [ConversationController::class, 'declineCall']);

    // Threads.
    Route::get('channels/{channel}/threads', [ThreadController::class, 'index']);
    Route::post('channels/{channel}/threads', [ThreadController::class, 'store']);
    Route::get('threads/{thread}', [ThreadController::class, 'show']);
    Route::get('threads/{thread}/messages', [ThreadMessageController::class, 'index']);
    Route::post('threads/{thread}/messages', [ThreadMessageController::class, 'store']);
});
