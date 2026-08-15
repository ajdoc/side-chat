<?php

use App\Http\Controllers\AppCatalogueController;
use App\Http\Controllers\AppCommentController;
use App\Http\Controllers\AppPollController;
use App\Http\Controllers\AppReactionController;
use App\Http\Controllers\AppStickerController;
use App\Http\Controllers\AppTagController;
use App\Http\Controllers\ArpgCharacterController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\BadgeController;
use App\Http\Controllers\BoardLayerController;
use App\Http\Controllers\BotAuditLogController;
use App\Http\Controllers\BotCommandController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\BotDashboardController;
use App\Http\Controllers\BotIdentityController;
use App\Http\Controllers\BotMessageController;
use App\Http\Controllers\BotScheduleController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CanvasController;
use App\Http\Controllers\ChannelCalendarController;
use App\Http\Controllers\ChannelCanvasController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ChannelDocumentController;
use App\Http\Controllers\ChannelLinkController;
use App\Http\Controllers\ChannelMemberController;
use App\Http\Controllers\ChannelSpaceNoteController;
use App\Http\Controllers\ChannelWhiteboardController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\CommandCatalogueController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomCommandController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\DeskAppsController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\GiveawayController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JoinRequestController;
use App\Http\Controllers\LyricsController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageInfoController;
use App\Http\Controllers\NicknameController;
use App\Http\Controllers\NotificationSettingController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReactionController;
use App\Http\Controllers\ReactionRoleController;
use App\Http\Controllers\ReadReceiptController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\SideChatController;
use App\Http\Controllers\SideChatForumController;
use App\Http\Controllers\SideChatMessageController;
use App\Http\Controllers\SideSpaceController;
use App\Http\Controllers\SpaceAppearanceController;
use App\Http\Controllers\SpaceGameController;
use App\Http\Controllers\SpaceNoteController;
use App\Http\Controllers\SpotifyController;
use App\Http\Controllers\ThreadController;
use App\Http\Controllers\ThreadMessageController;
use App\Http\Controllers\TrackerProjectController;
use App\Http\Controllers\TrackerTaskController;
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

    // Social login. Still provider-shaped so a second provider is one entry in this list,
    // but Google is the only one we ship.
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google']);
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google']);

    // Authenticated.
    Route::middleware('auth:api')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:api')->group(function () {
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::patch('preferences', [PreferencesController::class, 'update']);
    // What you look like walking around a Side Space, and which starter follows you. Yours,
    // not a room's — hence no channel in the path.
    Route::patch('space/appearance', [SpaceAppearanceController::class, 'update']);

    /*
     * Push. The registry is one row per install (see the device_tokens migration): the app
     * posts its FCM token on every launch, because FCM rotates them without asking, and
     * deletes it on sign-out so a shared phone stops receiving the last person's messages.
     */
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

    // Spotify account linking, for real Premium playback in the music widget.
    Route::get('spotify/connect', [SpotifyController::class, 'connect']);
    Route::get('spotify/status', [SpotifyController::class, 'status']);
    Route::get('spotify/token', [SpotifyController::class, 'token']);
    Route::post('spotify/disconnect', [SpotifyController::class, 'disconnect']);
});

/**
 * Large files, staged before a message claims them. Open an upload, get the bytes up, then hand
 * the id to a send as `uploads[]`. Opening it answers with a `mode` saying which of the two ways
 * to send the bytes applies: `direct` (PUT the file to the signed url, then post `complete`) or
 * `chunked` (post the slices to `chunks` in order) — see ChunkedUploadController.
 */
Route::middleware('auth:api')->group(function () {
    Route::post('uploads', [ChunkedUploadController::class, 'store']);
    Route::post('uploads/{upload:uuid}/chunks', [ChunkedUploadController::class, 'update']);
    Route::post('uploads/{upload:uuid}/complete', [ChunkedUploadController::class, 'complete']);
    Route::delete('uploads/{upload:uuid}', [ChunkedUploadController::class, 'destroy']);
});

/*
 * Search, across everything the caller can see: messages, channels, servers, DMs and group
 * chats. One route rather than one per kind — see SearchController for why the command
 * palette makes that the only workable shape. Needs no membership middleware: every query
 * behind it is built from the caller's visible set (Channel::scopeVisibleTo), so an
 * unauthorised scope narrows the results to nothing rather than needing to be refused.
 */
Route::middleware('auth:api')->get('search', SearchController::class);

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
    /*
     * Server roles. Promoting a member to admin, or putting them back — owner only, since
     * an admin who can appoint admins is an owner with extra steps. What being an admin
     * *buys* is everywhere else: see ServerStaffRequest.
     */
    Route::patch('servers/{server}/members/{member}/role', [ServerController::class, 'updateRole']);

    /*
     * Bots, from the owner's side: register one, rename it, rotate its token, retire it.
     * Owner only — see StoreBotRequest. The bot's own half of the API is the `bot/` group
     * further down, which authenticates with the token this hands out rather than a
     * Passport one.
     */
    Route::get('servers/{server}/bots', [BotController::class, 'index']);
    Route::post('servers/{server}/bots', [BotController::class, 'store']);
    Route::patch('servers/{server}/bots/{bot}', [BotController::class, 'update']);
    Route::post('servers/{server}/bots/{bot}/token', [BotController::class, 'regenerate']);
    // The webhook's signing secret, rotated separately from the API token: the two protect
    // opposite directions and leak through different doors.
    Route::post('servers/{server}/bots/{bot}/webhook-secret', [BotController::class, 'regenerateWebhookSecret']);
    Route::delete('servers/{server}/bots/{bot}', [BotController::class, 'destroy']);
    // Which bot the server's automations speak as. Owner only, like every other write on a
    // bot — see the runs_automations migration for why there's exactly one.
    Route::put('servers/{server}/bots/{bot}/automations', [BotController::class, 'setAutomationBot']);

    /*
     * The bot dashboard: rules, badges, and how the bot behaves.
     *
     * Staff rather than owner-only — running the place is what an admin is for, and a
     * welcome message is squarely that. The one exception is enforced on the payload
     * instead of the route: a rule that hands out *roles* may only be written by the owner
     * (see StoreAutomationRequest), because who is an admin is the owner's alone.
     */
    Route::get('servers/{server}/bot/overview', [BotDashboardController::class, 'overview']);
    // What the builder renders its trigger and action forms from. Served rather than
    // duplicated in TypeScript, so an action added in PHP appears in the UI on its own.
    Route::get('servers/{server}/bot/catalogue', [BotDashboardController::class, 'catalogue']);
    Route::get('servers/{server}/bot/settings', [BotDashboardController::class, 'settings']);
    Route::put('servers/{server}/bot/settings', [BotDashboardController::class, 'updateSettings']);
    // The welcome message. A settings form on the outside; a `member.joined` rule on the
    // inside, so it fires through the same engine as anything hand-built.
    Route::get('servers/{server}/bot/welcome', [BotDashboardController::class, 'welcome']);
    Route::put('servers/{server}/bot/welcome', [BotDashboardController::class, 'updateWelcome']);

    Route::get('servers/{server}/automations', [AutomationController::class, 'index']);
    Route::post('servers/{server}/automations', [AutomationController::class, 'store']);
    Route::put('servers/{server}/automations/{automation}', [AutomationController::class, 'update']);
    // Off and on without opening the editor — the edit people make in a hurry.
    Route::post('servers/{server}/automations/{automation}/toggle', [AutomationController::class, 'toggle']);
    // Runs it for real, against the person who pressed the button. See the controller.
    Route::post('servers/{server}/automations/{automation}/run', [AutomationController::class, 'run']);
    Route::delete('servers/{server}/automations/{automation}', [AutomationController::class, 'destroy']);

    /*
     * Commands a server declares for itself, and the recurring posts it schedules. Both
     * staff, both reachable from a rule as well as on their own — see RunCommandAction and
     * RunScheduleAction for why the built-ins compose rather than sit in silos.
     */
    Route::get('servers/{server}/commands', [CustomCommandController::class, 'index']);
    Route::post('servers/{server}/commands', [CustomCommandController::class, 'store']);
    Route::patch('servers/{server}/commands/{command}', [CustomCommandController::class, 'update']);
    Route::delete('servers/{server}/commands/{command}', [CustomCommandController::class, 'destroy']);

    Route::get('servers/{server}/schedules', [BotScheduleController::class, 'index']);
    Route::post('servers/{server}/schedules', [BotScheduleController::class, 'store']);
    Route::patch('servers/{server}/schedules/{schedule}', [BotScheduleController::class, 'update']);
    Route::post('servers/{server}/schedules/{schedule}/toggle', [BotScheduleController::class, 'toggle']);
    // Sends it now without moving its clock — the Monday post is still due on Monday.
    Route::post('servers/{server}/schedules/{schedule}/run', [BotScheduleController::class, 'run']);
    Route::delete('servers/{server}/schedules/{schedule}', [BotScheduleController::class, 'destroy']);

    /*
     * Reaction roles: react to a message, get a badge. Creating one posts the announcement,
     * seeds the emoji and writes the rules in a breath — see the controller for why doing
     * those three separately is exactly the work this removes.
     */
    Route::get('servers/{server}/reaction-roles', [ReactionRoleController::class, 'index']);
    Route::post('servers/{server}/reaction-roles', [ReactionRoleController::class, 'store']);
    // Addressed by message: what gets removed is "that post and what it does", and half a
    // pair would leave a badge nobody could give up.
    Route::delete('servers/{server}/reaction-roles/{message}', [ReactionRoleController::class, 'destroy']);
    // Repost a buried or deleted announcement and move the rules onto the new message.
    Route::post('servers/{server}/reaction-roles/{message}/resend', [ReactionRoleController::class, 'resend']);

    Route::get('servers/{server}/giveaways', [GiveawayController::class, 'index']);
    Route::post('servers/{server}/giveaways', [GiveawayController::class, 'store']);
    Route::post('servers/{server}/giveaways/{giveaway}/draw', [GiveawayController::class, 'draw']);
    // Same as reaction roles: repost, and move entry onto the new message. Entries stand.
    Route::post('servers/{server}/giveaways/{giveaway}/resend', [GiveawayController::class, 'resend']);
    // Cancelled, not deleted — people entered, and the record is more honest than a silence.
    Route::delete('servers/{server}/giveaways/{giveaway}', [GiveawayController::class, 'destroy']);

    // Everything the bot did, paged and filterable. The Overview's glance, at length.
    Route::get('servers/{server}/bot/log', [BotAuditLogController::class, 'index']);

    Route::get('servers/{server}/badges', [BadgeController::class, 'index']);
    Route::post('servers/{server}/badges', [BadgeController::class, 'store']);
    Route::patch('servers/{server}/badges/{badge}', [BadgeController::class, 'update']);
    Route::delete('servers/{server}/badges/{badge}', [BadgeController::class, 'destroy']);
    Route::put('servers/{server}/badges/{badge}/members/{member}', [BadgeController::class, 'grant']);
    Route::delete('servers/{server}/badges/{badge}/members/{member}', [BadgeController::class, 'revoke']);

    Route::get('servers/{server}/nicknames', [NicknameController::class, 'indexForServer']);
    Route::put('servers/{server}/nicknames/{member}', [NicknameController::class, 'updateForServer']);

    Route::get('servers/{server}/channels', [ChannelController::class, 'index']);
    Route::post('servers/{server}/channels', [ChannelController::class, 'store']);
    // Rename (name only — a channel's type is not editable). Staff only.
    Route::patch('channels/{channel}', [ChannelController::class, 'update']);
    /*
     * Who may be in the channel. Staff only, and split off the rename on purpose: one is
     * cosmetic, the other decides who can read the history. Reading the allow-list is
     * gated too — knowing exactly who is in a private channel is itself private.
     */
    Route::get('channels/{channel}/access', [ChannelController::class, 'showAccess']);
    Route::put('channels/{channel}/access', [ChannelController::class, 'access']);
    // Owner only — deletes the channel's threads, messages and uploaded files.
    Route::delete('channels/{channel}', [ChannelController::class, 'destroy']);

    /*
     * End-to-end encryption, on or off. Off everywhere by default.
     *
     * Whoever is responsible for the place decides: a server channel's staff, a group's
     * owner, either person in a DM. It applies going forward only — see
     * ToggleChannelEncryptionAction for why there is no retroactive version of this.
     */
    Route::put('channels/{channel}/encryption', [EncryptionController::class, 'toggle']);

    /*
     * The key directory.
     *
     * Public halves and sealed blobs only — the server is a post box here, not a party to
     * anything. Registration is about the caller's own device (the account comes from the
     * token, never the payload); everything channel-scoped is gated on membership, because
     * fetching bundles *consumes* other people's one-time prekeys and an open version would
     * let anyone drain the server's forward secrecy. See EncryptionKeyService.
     */
    Route::put('encryption/devices', [EncryptionController::class, 'registerDevice']);
    Route::post('encryption/devices/prekeys', [EncryptionController::class, 'storePrekeys']);

    // POST, not GET, and deliberately: each bundle returned burns a one-time prekey.
    Route::post('channels/{channel}/encryption/bundles', [EncryptionController::class, 'bundles']);
    // Read-only, and consumes nothing — what the safety-number screen asks for. Pointing that
    // screen at `bundles` above would drain a one-time prekey per glance.
    Route::get('channels/{channel}/encryption/identities', [EncryptionController::class, 'identities']);
    // Who still needs my chain, and giving up on a key I can't open. The pair is what makes
    // distribution self-healing: the server knows what was actually delivered, and a recipient
    // that can't use its copy can put itself back on the list. See EncryptionKeyService.
    Route::post('channels/{channel}/encryption/pending', [EncryptionController::class, 'pending']);
    Route::post('channels/{channel}/encryption/reject', [EncryptionController::class, 'rejectKey']);
    Route::post('channels/{channel}/encryption/sender-keys', [EncryptionController::class, 'distribute']);
    Route::post('channels/{channel}/encryption/inbox', [EncryptionController::class, 'inbox']);

    /*
     * Key backup — the passphrase-escrow half of "what happens on a new device".
     *
     * A wrapped blob the server cannot read, one row per account, replaced rather than
     * appended. Opting out means simply never calling these: no row exists, and the person
     * keeps a downloaded recovery file instead. See the key_backups migration for the honest
     * account of what escrow costs.
     */
    Route::get('encryption/backup', [EncryptionController::class, 'showBackup']);
    Route::put('encryption/backup', [EncryptionController::class, 'storeBackup']);
    Route::delete('encryption/backup', [EncryptionController::class, 'destroyBackup']);

    /*
     * Discussions: the conversations inside a channel. A discussion *is* a channel, so
     * everything below this line in the file already works on one addressed by its own id —
     * these three routes are only what a discussion has that a channel doesn't.
     *
     * Creating is open to anyone in the channel unless the server has narrowed it to staff;
     * deleting is staff-only, because it takes other people's messages with it.
     */
    Route::get('channels/{channel}/discussions', [DiscussionController::class, 'index']);
    Route::post('channels/{channel}/discussions', [DiscussionController::class, 'store']);
    Route::delete('discussions/{channel}', [DiscussionController::class, 'destroy']);
    // Per-person: which discussion this channel opens on for you.
    Route::put('discussions/{channel}/default', [DiscussionController::class, 'setDefault']);
    Route::delete('discussions/{channel}/default', [DiscussionController::class, 'clearDefault']);

    // Every `/command` callable here — what the composer's autocomplete is built from.
    Route::get('channels/{channel}/commands', CommandCatalogueController::class);

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

    /*
     * How loud one place is, for one person. Addressable by channel *or* by conversation,
     * because the DM list holds conversation ids and never sees the channel inside one —
     * both land on the same row. Muting a container quiets its discussions too, which is
     * NotificationPolicy's inheritance rule rather than anything these routes do.
     */
    Route::get('channels/{channel}/notifications', [NotificationSettingController::class, 'show']);
    Route::put('channels/{channel}/notifications', [NotificationSettingController::class, 'update']);
    Route::get('conversations/{conversation}/notifications', [NotificationSettingController::class, 'showForConversation']);
    Route::put('conversations/{conversation}/notifications', [NotificationSettingController::class, 'updateForConversation']);

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
    // Video files already posted in this chat (timeline, threads and side chats alike) —
    // what the video widget's "in this chat" picker browses. `?q=` filters by filename.
    Route::get('channels/{channel}/videos', [AttachmentController::class, 'indexForChannelVideos']);
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
    // What the call plays when people come and go: readable by any member, set by the owner.
    Route::get('channels/{channel}/voice/effects', [VoiceController::class, 'effects']);
    Route::patch('channels/{channel}/voice/effects', [VoiceController::class, 'updateEffects']);
    // Owner only: move somebody else's microphone. Unlike disconnecting, this reaches into
    // another person's machine rather than just emptying their seat.
    Route::post('channels/{channel}/voice/mute', [VoiceController::class, 'mute']);
    // Any member: disconnect one participant (with user_id) or clear the room (without).
    Route::post('channels/{channel}/voice/disconnect', [VoiceController::class, 'disconnect']);

    /*
     * App channels — the Tracker, and the comments/tags every app can borrow.
     *
     * Short for the same reason the Side Space block below is: an app channel *is* a channel,
     * so its timeline, threads, side chats, reads and permissions are the endpoints above,
     * unchanged. What's left is the app's own storage.
     *
     * Everything is addressed to its channel, which is what lets MemberRequest settle who may
     * be here without the tracker knowing anything about permissions — and what scopes the
     * storage, so two tracker channels are two trackers.
     */
    // What apps exist — built-in flags plus whatever has been installed. Feeds the
    // create-channel picker and the Side Desk's "add an app" list, which are two filters over
    // one catalogue rather than two lists.
    Route::get('apps/catalogue', AppCatalogueController::class);

    Route::get('channels/{channel}/tracker/fields', [TrackerProjectController::class, 'fields']);
    Route::get('channels/{channel}/tracker/projects', [TrackerProjectController::class, 'index']);
    Route::post('channels/{channel}/tracker/projects', [TrackerProjectController::class, 'store']);
    Route::patch('channels/{channel}/tracker/projects/{project}', [TrackerProjectController::class, 'update']);
    // Takes its tasks, and their comments, tags and history, with it.
    Route::delete('channels/{channel}/tracker/projects/{project}', [TrackerProjectController::class, 'destroy']);

    // One listing for both screens: without ?project= it's the home's "your tasks", with one
    // it's the board. See TrackerTaskController::index.
    Route::get('channels/{channel}/tracker/tasks', [TrackerTaskController::class, 'index']);
    Route::post('channels/{channel}/tracker/tasks', [TrackerTaskController::class, 'store']);
    // The detail pane: the task plus the two lists a board listing leaves out.
    Route::get('channels/{channel}/tracker/tasks/{task}', [TrackerTaskController::class, 'show']);
    Route::patch('channels/{channel}/tracker/tasks/{task}', [TrackerTaskController::class, 'update']);
    Route::delete('channels/{channel}/tracker/tasks/{task}', [TrackerTaskController::class, 'destroy']);

    /*
     * Comments and tags, for anything an app owns.
     *
     * `{type}` is the short morph name of what's being commented on or tagged — 'tracker_task'
     * today — resolved by App\Support\Apps\AppSubjects. That indirection is the point: making a
     * kanban card commentable is a resolver, not another six routes.
     */
    Route::get('channels/{channel}/apps/{type}/{id}/comments', [AppCommentController::class, 'index']);
    Route::post('channels/{channel}/apps/{type}/{id}/comments', [AppCommentController::class, 'store']);
    // Addressed by comment id rather than through its target: a comment is already unique, and
    // the channel in the path is what authorises reaching it.
    Route::patch('channels/{channel}/app-comments/{comment}', [AppCommentController::class, 'update']);
    Route::delete('channels/{channel}/app-comments/{comment}', [AppCommentController::class, 'destroy']);

    /*
     * Polls — a wall of them, with results, reactions and a thread under each. Distinct from the
     * `poll` widget, which is the single card a `p!` command drops in a timeline. See the
     * app_polls migration for why both exist.
     */
    Route::get('channels/{channel}/polls', [AppPollController::class, 'index']);
    Route::post('channels/{channel}/polls', [AppPollController::class, 'store']);
    Route::get('channels/{channel}/polls/{poll}', [AppPollController::class, 'show']);
    Route::patch('channels/{channel}/polls/{poll}', [AppPollController::class, 'update']);
    Route::delete('channels/{channel}/polls/{poll}', [AppPollController::class, 'destroy']);
    // The body is the set of options you now stand behind, not a delta — see the controller.
    Route::put('channels/{channel}/polls/{poll}/vote', [AppPollController::class, 'vote']);

    // The Sticker Wall — a shared collage. Editing and deleting are yours-or-staff, unlike the
    // rest of the desk apps; see the controller.
    Route::get('channels/{channel}/stickers', [AppStickerController::class, 'index']);
    Route::post('channels/{channel}/stickers', [AppStickerController::class, 'store']);
    Route::patch('channels/{channel}/stickers/{sticker}', [AppStickerController::class, 'update']);
    Route::delete('channels/{channel}/stickers/{sticker}', [AppStickerController::class, 'destroy']);

    // Reactions on anything an app owns. One verb, because reacting and un-reacting are the
    // same gesture on the same chip.
    Route::get('channels/{channel}/apps/{type}/{id}/reactions', [AppReactionController::class, 'index']);
    Route::post('channels/{channel}/apps/{type}/{id}/reactions', [AppReactionController::class, 'toggle']);

    // The channel's vocabulary — shared by every app in it, not per project.
    Route::get('channels/{channel}/app-tags', [AppTagController::class, 'index']);
    Route::post('channels/{channel}/app-tags', [AppTagController::class, 'store']);
    Route::patch('channels/{channel}/app-tags/{tag}', [AppTagController::class, 'update']);
    Route::delete('channels/{channel}/app-tags/{tag}', [AppTagController::class, 'destroy']);
    // Putting one on something, and taking it off.
    Route::get('channels/{channel}/apps/{type}/{id}/tags', [AppTagController::class, 'forItem']);
    Route::put('channels/{channel}/apps/{type}/{id}/tags/{tag}', [AppTagController::class, 'attach']);
    Route::delete('channels/{channel}/apps/{type}/{id}/tags/{tag}', [AppTagController::class, 'detach']);

    /*
     * Side Spaces — the walkable rooms.
     *
     * Short for the same reason the DM block is: a Side Space *is* a channel with a call in
     * it, so messages, threads, side chats, the Side Desk and the entire voice stack are the
     * endpoints above, unchanged. What's left here is the map, and the one position per person
     * that outlives a tab.
     *
     * Movement itself is absent on purpose — it's whispered peer-to-peer over the room's
     * presence channel and never reaches this file. See routes/channels.php.
     */
    Route::get('space/map-presets', [SideSpaceController::class, 'presets']);
    // The ways a *room* inside a map can be furnished — a different thing from the layouts
    // above, which are whole maps. See App\Support\SideSpace\RoomPresets.
    Route::get('space/room-presets', [SideSpaceController::class, 'roomPresets']);
    Route::get('channels/{channel}/space/map', [SideSpaceController::class, 'show']);
    // Owner only: this replaces the room everyone is standing in.
    Route::put('channels/{channel}/space/map', [SideSpaceController::class, 'update']);
    // Any member: rearrange the furniture. The geometry above is owner-only; the furniture
    // isn't, so a room can be decorated by whoever's in it.
    Route::put('channels/{channel}/space/objects', [SideSpaceController::class, 'objects']);
    Route::post('channels/{channel}/space/position', [SideSpaceController::class, 'position']);
    // Pressing E on the furniture. Answers with the channel's widget of whatever type that
    // piece opens — the speaker's music player is the channel's music player.
    Route::post('channels/{channel}/space/interact', [SideSpaceController::class, 'interact']);
    /*
     * "Everyone follow me." Staff only, and the only thing in the room that moves other people's
     * avatars without asking them — which is exactly why it's an endpoint rather than one more
     * whisper alongside the movement ones. See App\Http\Requests\SideSpace\SummonSpaceRequest.
     */
    Route::post('channels/{channel}/space/summon', [SideSpaceController::class, 'summon']);

    /*
     * Rooms and their doors.
     *
     * Deliberately *not* part of the map payload above. That one is saved by any member — the
     * room is built by the group — so a lock kept inside it would be a lock any member could
     * delete by saving the room around it. These are rows with their own gates: appointing a
     * room owner is the server owner's alone, and locking a door belongs to whoever is
     * responsible for the room it guards. See App\Support\SideSpace\Doors.
     */
    Route::put('channels/{channel}/space/rooms/{zone}', [SideSpaceController::class, 'assignRoom']);
    Route::get('channels/{channel}/space/locks', [SideSpaceController::class, 'locks']);
    Route::put('channels/{channel}/space/locks/{object}', [SideSpaceController::class, 'lockDoor']);
    Route::delete('channels/{channel}/space/locks/{object}', [SideSpaceController::class, 'unlockDoor']);
    /*
     * Saying the password at a door. Throttled, which the other three aren't: this is the one
     * endpoint in the group that anybody may call about a door they have no claim on, so the
     * rate of guesses is the only thing standing between a four-character phrase and a script.
     */
    Route::post('channels/{channel}/space/locks/{object}/enter', [SideSpaceController::class, 'enterDoor'])
        ->middleware('throttle:10,1');

    /*
     * Games in the room — "the map becomes a game". The catalogue is global; everything else is
     * scoped to a channel and, inside that, to the people standing in it. Movement during a game
     * is still the whispered peer-to-peer movement of any Side Space; only the game's own moves
     * (a task, a kill, a vote) come through here. See App\Services\Games.
     */
    Route::get('space/games', [SpaceGameController::class, 'catalogue']);

    /*
     * Dungeon heroes. No channel in the path on purpose: a character belongs to a player, not to
     * a room, and is the one thing in a crawl that outlives the run — see ArpgCharacterController.
     */
    Route::get('arpg/characters', [ArpgCharacterController::class, 'index']);
    Route::post('arpg/characters', [ArpgCharacterController::class, 'store']);
    Route::post('arpg/characters/{character}/select', [ArpgCharacterController::class, 'select']);
    Route::post('arpg/characters/{character}/skills', [ArpgCharacterController::class, 'learn']);
    // Taking the next job in the line — mage to wizard. A decision, hence a POST of its own.
    Route::post('arpg/characters/{character}/advance', [ArpgCharacterController::class, 'advance']);
    /*
     * What a skill *is* — served rather than duplicated in the engine, so tuning a number is a
     * change in one file. The engine implements six kinds, not thirty-two skills.
     */
    Route::get('arpg/skills', [ArpgCharacterController::class, 'skills']);
    Route::delete('arpg/characters/{character}', [ArpgCharacterController::class, 'destroy']);

    Route::get('channels/{channel}/space/game', [SpaceGameController::class, 'show']);
    Route::post('channels/{channel}/space/game', [SpaceGameController::class, 'propose']);
    Route::post('channels/{channel}/space/game/vote', [SpaceGameController::class, 'vote']);
    Route::post('channels/{channel}/space/game/join', [SpaceGameController::class, 'join']);
    Route::post('channels/{channel}/space/game/act', [SpaceGameController::class, 'act']);
    Route::delete('channels/{channel}/space/game', [SpaceGameController::class, 'cancel']);

    /*
     * Friends.
     *
     * One table, four tabs: your friends, what's outstanding either way, and who you've
     * blocked. Nothing here browses the instance — you add someone you can already see, or
     * by typing their name exactly (see StoreFriendRequest for why).
     *
     * What a friendship buys you lives elsewhere: friends may DM each other without sharing
     * a server, and a block shuts that door both ways.
     */
    Route::get('friends', [FriendController::class, 'index']);
    Route::get('friends/requests', [FriendController::class, 'pending']);
    Route::get('friends/blocked', [FriendController::class, 'blocked']);
    // Send a request — or accept theirs, if you both pressed Add at once.
    Route::post('friends', [FriendController::class, 'store']);
    // Answering. Only the person who was asked; the action enforces it.
    Route::post('friends/{friendship}/accept', [FriendController::class, 'accept']);
    Route::post('friends/{friendship}/decline', [FriendController::class, 'decline']);
    // Unfriend, or take back a request. Either party — same row, same delete.
    Route::delete('friends/{friendship}', [FriendController::class, 'destroy']);
    Route::delete('friends/user/{user}', [FriendController::class, 'destroyByUser']);
    // Blocking reuses the same row: the two states are mutually exclusive by definition.
    Route::post('friends/block', [FriendController::class, 'block']);
    Route::delete('friends/block/{user}', [FriendController::class, 'unblock']);

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
    // Retitle / delete. The thread's creator or the server's staff — see ThreadAuthorRequest.
    // Deleting takes every reply (and their files) with it; the parent message stays put.
    Route::patch('threads/{thread}', [ThreadController::class, 'update']);
    Route::delete('threads/{thread}', [ThreadController::class, 'destroy']);
    Route::get('threads/{thread}/messages', [ThreadMessageController::class, 'index']);
    Route::post('threads/{thread}/messages', [ThreadMessageController::class, 'store']);

    // The channel's own shared whiteboard — same board a side chat has (below), gated on
    // channel membership rather than a roster: anyone in the channel may read and draw.
    Route::get('channels/{channel}/whiteboard', [ChannelWhiteboardController::class, 'index']);
    Route::post('channels/{channel}/whiteboard/strokes', [ChannelWhiteboardController::class, 'store']);
    Route::patch('channels/{channel}/whiteboard/strokes/{stroke}', [ChannelWhiteboardController::class, 'update']);
    Route::delete('channels/{channel}/whiteboard/strokes/{stroke}', [ChannelWhiteboardController::class, 'destroy']);
    Route::delete('channels/{channel}/whiteboard', [ChannelWhiteboardController::class, 'clear']);

    // The channel's Side Desk note — its one shared markdown document, gated like the board.
    Route::get('channels/{channel}/notes', [ChannelSpaceNoteController::class, 'show']);
    Route::put('channels/{channel}/notes', [ChannelSpaceNoteController::class, 'update']);

    // The channel's Open Canvas — free 2D cards, gated on membership like the board.
    Route::get('channels/{channel}/canvas', [ChannelCanvasController::class, 'index']);
    Route::post('channels/{channel}/canvas', [ChannelCanvasController::class, 'store']);
    Route::patch('channels/{channel}/canvas/{item}', [ChannelCanvasController::class, 'update']);
    Route::delete('channels/{channel}/canvas/{item}', [ChannelCanvasController::class, 'destroy']);

    // The channel's Calendar app — a shared schedule, gated on membership like the board. The
    // Calendar *tab* and the Calendar *canvas card* are two views of exactly these rows.
    Route::get('channels/{channel}/calendar', [ChannelCalendarController::class, 'index']);
    Route::post('channels/{channel}/calendar', [ChannelCalendarController::class, 'store']);
    Route::patch('channels/{channel}/calendar/{event}', [ChannelCalendarController::class, 'update']);
    Route::delete('channels/{channel}/calendar/{event}', [ChannelCalendarController::class, 'destroy']);

    // Which apps the channel's Side Desk shows. Shared by everyone in the channel, so this is
    // the whole surface's tab strip, not one person's — see the migration.
    // A board's layers — names and visibility. The strokes carry the index; this is the rest.
    Route::get('channels/{channel}/whiteboard/layers', [BoardLayerController::class, 'showChannel']);
    Route::put('channels/{channel}/whiteboard/layers', [BoardLayerController::class, 'updateChannel']);
    Route::get('side-chats/{sideChat}/whiteboard/layers', [BoardLayerController::class, 'showSideChat']);
    Route::put('side-chats/{sideChat}/whiteboard/layers', [BoardLayerController::class, 'updateSideChat']);

    Route::get('channels/{channel}/desk-apps', [DeskAppsController::class, 'showChannel']);
    Route::put('channels/{channel}/desk-apps', [DeskAppsController::class, 'updateChannel']);

    // Open the channel's widget of a type, creating it on first use — what a widget app tab
    // resolves through, and the same row the timeline and canvas cards render.
    Route::post('channels/{channel}/widgets/ensure', [WidgetController::class, 'ensure']);

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
    /*
     * Forum groups: the headings the side chat list folds under. Reading them needs only
     * channel membership; arranging them is the staff's (ManageSideChatForumRequest), on
     * the same argument as the channels themselves — the layout of a shared place is not a
     * passer-by's to rearrange. Filing your *own* post into a group is not here: that's
     * `side_chat_forum_id` on the PATCH above, which the OP may send.
     */
    Route::get('channels/{channel}/side-chat-forums', [SideChatForumController::class, 'index']);
    Route::post('channels/{channel}/side-chat-forums', [SideChatForumController::class, 'store']);
    Route::put('channels/{channel}/side-chat-forums/order', [SideChatForumController::class, 'reorder']);
    Route::patch('side-chat-forums/{forum}', [SideChatForumController::class, 'update']);
    Route::delete('side-chat-forums/{forum}', [SideChatForumController::class, 'destroy']);
    /*
     * The forum layer. Retitling, retagging and deleting are the OP's or the staff's
     * (ManageSideChatRequest); reacting to the post is anyone in the channel's, because a
     * list you must join a post to vote on isn't a list.
     */
    Route::patch('side-chats/{sideChat}', [SideChatController::class, 'update']);
    Route::delete('side-chats/{sideChat}', [SideChatController::class, 'destroy']);
    Route::post('side-chats/{sideChat}/reactions', [SideChatController::class, 'react']);
    /*
     * Comments on the post — the co-signable phrase chips, not replies. Replying to a post
     * *is* posting into its timeline (`side-chats/{id}/messages` below), which is why there
     * is no separate reply endpoint here: a reply is a message, and always was.
     */
    Route::get('side-chats/{sideChat}/comments', [SideChatController::class, 'comments']);
    Route::post('side-chats/{sideChat}/comments', [SideChatController::class, 'comment']);
    Route::delete('side-chat-comments/{comment}', [SideChatController::class, 'destroyComment']);
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
    // The side chat's Side Desk note. Reading needs channel membership; saving needs the roster.
    Route::get('side-chats/{sideChat}/notes', [SpaceNoteController::class, 'show']);
    Route::put('side-chats/{sideChat}/notes', [SpaceNoteController::class, 'update']);
    // The side chat's Open Canvas. Reading needs channel membership; authoring needs the roster.
    Route::get('side-chats/{sideChat}/canvas', [CanvasController::class, 'index']);
    Route::post('side-chats/{sideChat}/canvas', [CanvasController::class, 'store']);
    Route::patch('side-chats/{sideChat}/canvas/{item}', [CanvasController::class, 'update']);
    Route::delete('side-chats/{sideChat}/canvas/{item}', [CanvasController::class, 'destroy']);
    // The side chat's Calendar app. Reading needs channel membership; authoring needs the roster.
    Route::get('side-chats/{sideChat}/calendar', [CalendarController::class, 'index']);
    Route::post('side-chats/{sideChat}/calendar', [CalendarController::class, 'store']);
    Route::patch('side-chats/{sideChat}/calendar/{event}', [CalendarController::class, 'update']);
    Route::delete('side-chats/{sideChat}/calendar/{event}', [CalendarController::class, 'destroy']);
    // Which apps the side chat's Side Desk shows — shared by its roster, like everything else on it.
    Route::get('side-chats/{sideChat}/desk-apps', [DeskAppsController::class, 'showSideChat']);
    Route::put('side-chats/{sideChat}/desk-apps', [DeskAppsController::class, 'updateSideChat']);
    // The side chat's Docs app. Listing needs channel membership; uploading needs the roster.
    Route::get('side-chats/{sideChat}/documents', [DocumentController::class, 'index']);
    Route::post('side-chats/{sideChat}/documents', [DocumentController::class, 'store']);
    Route::delete('side-chats/{sideChat}/documents/{document}', [DocumentController::class, 'destroy']);
    // Record a message as a decision (the ✅ on a side chat's card), or take it back.
    Route::post('messages/{message}/decision', [DecisionController::class, 'toggle']);
});

/*
 * The bot API.
 *
 * Everything above authenticates a person holding a short-lived Passport token. These two
 * routes authenticate a *bot* holding the long-lived string its server's owner was shown
 * once — see App\Http\Middleware\AuthenticateBot. Kept as its own prefix rather than mixed
 * into the routes above so the whole of a bot's reach is legible in one place: it can find
 * out where it is, and it can talk. Nothing else is exposed to a credential that lives in
 * somebody's CI config.
 */
Route::middleware('auth.bot')->prefix('bot')->group(function () {
    Route::get('me', BotIdentityController::class);
    Route::post('channels/{channel}/messages', [BotMessageController::class, 'store']);
    // What this bot answers to. Declared by the bot on boot, replacing whatever the last
    // version of it registered — see BotCommandController.
    Route::get('commands', [BotCommandController::class, 'index']);
    Route::put('commands', [BotCommandController::class, 'update']);
});
