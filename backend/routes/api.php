<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\CanvasController;
use App\Http\Controllers\ChannelCanvasController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\ChannelDocumentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChannelSpaceNoteController;
use App\Http\Controllers\ChannelWhiteboardController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JoinRequestController;
use App\Http\Controllers\LyricsController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageInfoController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\NicknameController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReadReceiptController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SideChatController;
use App\Http\Controllers\SideChatMessageController;
use App\Http\Controllers\SpaceNoteController;
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
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::patch('preferences', [PreferencesController::class, 'update']);

    // Spotify account linking, for real Premium playback in the music widget.
    Route::get('spotify/connect', [SpotifyController::class, 'connect']);
    Route::get('spotify/status', [SpotifyController::class, 'status']);
    Route::get('spotify/token', [SpotifyController::class, 'token']);
    Route::post('spotify/disconnect', [SpotifyController::class, 'disconnect']);
});

/**
 * Large files, staged in pieces before a message claims them. Open an upload, post its chunks
 * in order, then hand the id to a send as `uploads[]` — see ChunkedUploadController.
 */
Route::middleware('auth:api')->group(function () {
    Route::post('uploads', [ChunkedUploadController::class, 'store']);
    Route::post('uploads/{upload:uuid}/chunks', [ChunkedUploadController::class, 'update']);
    Route::delete('uploads/{upload:uuid}', [ChunkedUploadController::class, 'destroy']);
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

    /*
     * What people are called *in this server*. Any member reads the map; setting your own
     * public nickname is yours, setting somebody else's is the owner's, and a private
     * alias is yours about anyone. The matching pair for chats sits with the conversation
     * routes below — same controller, because a nickname only knows about "the place".
     */
    Route::get('servers/{server}/nicknames', [NicknameController::class, 'indexForServer']);
    Route::put('servers/{server}/nicknames/{member}', [NicknameController::class, 'updateForServer']);

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
    // Forward a message into another channel you're a member of (a DM, group, or channel).
    Route::post('messages/{message}/forward', [MessageController::class, 'forward']);

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

    // Karaoke: time-synced lyrics for whatever the music widget is playing. Read-only and
    // widget-agnostic — it takes a track description, not a widget id.
    Route::get('lyrics', [LyricsController::class, 'show']);

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

    // Attachments, links and GIFs (the channel Info panel's tabs).
    Route::get('channels/{channel}/attachments', [AttachmentController::class, 'indexForChannel']);
    Route::get('channels/{channel}/links', [ChannelLinkController::class, 'index']);
    Route::get('channels/{channel}/gifs', [AttachmentController::class, 'indexForChannelGifs']);
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // GIF picker — proxies the configured providers (Giphy, Klipy) so their keys stay server-side.
    Route::get('gifs/featured', [GifController::class, 'featured']);
    Route::get('gifs/search', [GifController::class, 'search']);

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

    // Nicknames in a chat — see the server pair above. A chat has no owner worth the name,
    // so here `public` scope only ever means your own.
    Route::get('conversations/{conversation}/nicknames', [NicknameController::class, 'indexForConversation']);
    Route::put('conversations/{conversation}/nicknames/{member}', [NicknameController::class, 'updateForConversation']);
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

    // The channel's Side Space note — its one shared markdown document, gated like the board.
    Route::get('channels/{channel}/notes', [ChannelSpaceNoteController::class, 'show']);
    Route::put('channels/{channel}/notes', [ChannelSpaceNoteController::class, 'update']);

    // The channel's Open Canvas — free 2D cards, gated on membership like the board.
    Route::get('channels/{channel}/canvas', [ChannelCanvasController::class, 'index']);
    Route::post('channels/{channel}/canvas', [ChannelCanvasController::class, 'store']);
    Route::patch('channels/{channel}/canvas/{item}', [ChannelCanvasController::class, 'update']);
    Route::delete('channels/{channel}/canvas/{item}', [ChannelCanvasController::class, 'destroy']);

    // The channel's Docs app — view-only file shelf, gated on membership like the board.
    Route::get('channels/{channel}/documents', [ChannelDocumentController::class, 'index']);
    Route::post('channels/{channel}/documents', [ChannelDocumentController::class, 'store']);
    Route::post('channels/{channel}/documents/{document}/send', [ChannelDocumentController::class, 'sendToChat']);
    Route::delete('channels/{channel}/documents/{document}', [ChannelDocumentController::class, 'destroy']);

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
    // The side chat's Side Space note. Reading needs channel membership; saving needs the roster.
    Route::get('side-chats/{sideChat}/notes', [SpaceNoteController::class, 'show']);
    Route::put('side-chats/{sideChat}/notes', [SpaceNoteController::class, 'update']);
    // The side chat's Open Canvas. Reading needs channel membership; authoring needs the roster.
    Route::get('side-chats/{sideChat}/canvas', [CanvasController::class, 'index']);
    Route::post('side-chats/{sideChat}/canvas', [CanvasController::class, 'store']);
    Route::patch('side-chats/{sideChat}/canvas/{item}', [CanvasController::class, 'update']);
    Route::delete('side-chats/{sideChat}/canvas/{item}', [CanvasController::class, 'destroy']);
    // The side chat's Docs app. Listing needs channel membership; uploading needs the roster.
    Route::get('side-chats/{sideChat}/documents', [DocumentController::class, 'index']);
    Route::post('side-chats/{sideChat}/documents', [DocumentController::class, 'store']);
    Route::delete('side-chats/{sideChat}/documents/{document}', [DocumentController::class, 'destroy']);
    // Record a message as a decision (the ✅ on a side chat's card), or take it back.
    Route::post('messages/{message}/decision', [DecisionController::class, 'toggle']);
});
