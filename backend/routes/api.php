<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChannelWhiteboardController;
use App\Http\Controllers\CommentController;
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
use App\Http\Controllers\SideChatController;
use App\Http\Controllers\SideChatMessageController;
use App\Http\Controllers\SpotifyController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\ThreadMessageController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\WhiteboardController;
use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
]));

// Public: Spotify sends the browser here after a user authorises the account link. It
// carries no Bearer token — the caller is identified by the encrypted OAuth `state`.
Route::get('spotify/callback', [SpotifyController::class, 'callback']);

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

    // Spotify account linking, for real Premium playback in the music widget.
    Route::get('spotify/connect', [SpotifyController::class, 'connect']);
    Route::get('spotify/status', [SpotifyController::class, 'status']);
    Route::get('spotify/token', [SpotifyController::class, 'token']);
    Route::post('spotify/disconnect', [SpotifyController::class, 'disconnect']);
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
    // Who can be @mentioned here — powers the composer's autocomplete.
    Route::get('channels/{channel}/members', [ChannelMemberController::class, 'index']);
    // Edit/delete works for both channel and thread messages (sender-only).
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);

    // Reactions: any server member may react, on channel *and* thread messages.
    Route::post('messages/{message}/reactions', [ReactionController::class, 'toggle']);

    // Comments ("word-reactions"): a short annotation on a message. Store toggles a phrase
    // (co-sign / un-co-sign); index is the full list behind the chips; destroy removes one.
    Route::get('messages/{message}/comments', [CommentController::class, 'index']);
    Route::post('messages/{message}/comments', [CommentController::class, 'store']);
    Route::delete('comments/{comment}', [CommentController::class, 'destroy']);

    // "Message info": who saw it, who hasn't, who reacted.
    Route::get('messages/{message}/info', [MessageInfoController::class, 'show']);

    // Widgets: the music player and kanban board are created by chat commands (m!/k!),
    // so there's no "create" here — only the card's own buttons/drags, which run through
    // one free-form action endpoint and broadcast their result as WidgetUpdated. That
    // broadcast carries only a reference (the state is too big for Pusher's 10KB cap), so
    // `show` is how a client pulls the fresh state after being nudged.
    Route::get('widgets/{widget}', [WidgetController::class, 'show']);
    Route::post('widgets/{widget}/action', [WidgetController::class, 'action']);

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
    // Any member: disconnect one participant (with user_id) or clear the room (without).
    Route::post('channels/{channel}/voice/disconnect', [VoiceController::class, 'disconnect']);

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

    // The channel's own shared whiteboard — same board a side chat has (below), gated on
    // channel membership rather than a roster: anyone in the channel may read and draw.
    Route::get('channels/{channel}/whiteboard', [ChannelWhiteboardController::class, 'index']);
    Route::post('channels/{channel}/whiteboard/strokes', [ChannelWhiteboardController::class, 'store']);
    Route::patch('channels/{channel}/whiteboard/strokes/{stroke}', [ChannelWhiteboardController::class, 'update']);
    Route::delete('channels/{channel}/whiteboard/strokes/{stroke}', [ChannelWhiteboardController::class, 'destroy']);
    Route::delete('channels/{channel}/whiteboard', [ChannelWhiteboardController::class, 'clear']);

    /*
     * Side chats: a mini room spun off a message, with its own roster and timeline.
     *
     * Reading (index/show/messages index) needs only channel membership; taking part
     * (posting, recording a decision) needs a place on the roster — that's what join buys.
     */
    Route::get('channels/{channel}/side-chats', [SideChatController::class, 'index']);
    Route::post('channels/{channel}/side-chats', [SideChatController::class, 'store']);
    Route::get('side-chats/{sideChat}', [SideChatController::class, 'show']);
    // Standing highlights: the side chat's decisions and pinned messages (the panel's card).
    Route::get('side-chats/{sideChat}/highlights', [SideChatController::class, 'highlights']);
    Route::post('side-chats/{sideChat}/join', [SideChatController::class, 'join']);
    Route::post('side-chats/{sideChat}/leave', [SideChatController::class, 'leave']);
    // Add other channel members to the roster. Any participant may bring people in.
    Route::post('side-chats/{sideChat}/participants', [SideChatController::class, 'addParticipants']);
    Route::get('side-chats/{sideChat}/messages', [SideChatMessageController::class, 'index']);
    Route::post('side-chats/{sideChat}/messages', [SideChatMessageController::class, 'store']);
    // A side chat's own threads — its workspace list, kept out of the channel's Threads panel.
    Route::get('side-chats/{sideChat}/threads', [ThreadController::class, 'sideChatIndex']);
    Route::post('side-chats/{sideChat}/threads', [ThreadController::class, 'sideChatStore']);
    /*
     * The shared whiteboard: the persistent half of the side chat's workspace. Reading the
     * board needs only channel membership; drawing on it needs a place on the roster — the
     * same line join draws for posting. The live drag + cursor never come here; they ride
     * over whispers on the sidechat.{id} stream.
     */
    Route::get('side-chats/{sideChat}/whiteboard', [WhiteboardController::class, 'index']);
    Route::post('side-chats/{sideChat}/whiteboard/strokes', [WhiteboardController::class, 'store']);
    Route::patch('side-chats/{sideChat}/whiteboard/strokes/{stroke}', [WhiteboardController::class, 'update']);
    Route::delete('side-chats/{sideChat}/whiteboard/strokes/{stroke}', [WhiteboardController::class, 'destroy']);
    Route::delete('side-chats/{sideChat}/whiteboard', [WhiteboardController::class, 'clear']);
    // Record a message as a decision (the ✅ on a side chat's card), or take it back.
    Route::post('messages/{message}/decision', [DecisionController::class, 'toggle']);
});
