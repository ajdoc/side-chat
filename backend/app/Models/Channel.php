<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /**
     * `space` is a Side Space: a room you walk an avatar around, hearing whoever is near you.
     * Like a voice channel it is a text channel that also holds a call — the map sits on top of
     * the same timeline, and everything below it is unaware of the map (see allowsCalls).
     *
     * `app` is the same trick applied to software instead of a room: the channel's body is an
     * application — a tracker, a board, a doc shelf — over a timeline that carries on existing
     * underneath it. Which app is one row in `channel_apps` (see {@see ChannelApp}), and it
     * hangs off the *discussion*, exactly as a Side Space's map does, so a container full of
     * discussions is a folder of apps at no extra cost.
     */
    public const TYPES = ['text', 'voice', 'space', 'app'];

    /**
     * The entrance/exit effects a call may be given — everything the browser knows how to
     * draw and synthesise on its own (see VoiceEffects.vue). A closed catalogue on purpose:
     * nothing here is an asset anybody uploads, so a room can't be made to play something
     * unvetted at everyone in it.
     */
    public const VOICE_EFFECTS = ['fireworks', 'confetti', 'sparkles'];

    protected $fillable = [
        'server_id',
        'conversation_id',
        'parent_id',
        'name',
        'type',
        'is_private',
        'position',
        'join_effect',
        'leave_effect',
        'desk_apps',
        'board_layers',
        'encrypted',
        'encryption_epoch',
        'encryption_toggled_by',
        'encryption_toggled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // The Side Desk's tab strip, as an ordered array of app ids. Null until customised —
        // see the migration for why that isn't the same as storing the defaults.
        return [
            'desk_apps' => 'array',
            'board_layers' => 'array',
            'is_private' => 'boolean',
            'encrypted' => 'boolean',
            'encryption_toggled_at' => 'datetime',
        ];
    }

    /**
     * Are messages sent here encrypted before they leave the sender's device?
     *
     * Answers for *now*, and only for now. It is the right question when writing a message
     * and the wrong one when reading one: the timeline outlives the setting, so anything
     * inspecting a body asks {@see Message::isEncrypted()} instead.
     */
    public function isEncrypted(): bool
    {
        return (bool) $this->encrypted;
    }

    /**
     * The key era a message written right now belongs to, or null if it would be plaintext.
     *
     * Paired with isEncrypted() rather than folded into it because the two are stamped onto
     * the message together and must never disagree — a ciphertext with no epoch is
     * undecryptable, and a plaintext with one sends readers hunting for a key.
     */
    public function currentEpoch(): ?int
    {
        return $this->isEncrypted() ? (int) $this->encryption_epoch : null;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The channel this one is a discussion of, or null if this channel is itself a container.
     *
     * Nesting is exactly one level deep — a discussion never has discussions of its own. See
     * DISCUSSIONS.md; the short version is that this is the category system, and a sidebar
     * three levels deep is a sidebar nobody can read.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'parent_id');
    }

    /** This container's discussions, in the order the sidebar draws them. */
    public function discussions(): HasMany
    {
        return $this->hasMany(Channel::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** A discussion — it has a timeline, a desk and (if its type allows) a call of its own. */
    public function isDiscussion(): bool
    {
        return $this->parent_id !== null;
    }

    /** A container — it holds discussions and nothing else. Never joinable, never postable. */
    public function isContainer(): bool
    {
        return $this->parent_id === null;
    }

    /** Set instead of `server_id` when this channel is a DM or a group chat. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Whatever this channel lives in. Exactly one of the two, enforced by a CHECK
     * constraint on the table — so the null-coalesce below is a formality, not a fallback.
     *
     * Everything that used to reach for `$channel->server` to ask a question about
     * membership or broadcasting goes through here instead. That single redirection is
     * what let DMs reuse the entire message stack unchanged.
     */
    public function container(): ?MessageContainer
    {
        $this->loadMissing('server', 'conversation');

        return $this->server ?? $this->conversation;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class)->latest();
    }

    public function sideChats(): HasMany
    {
        return $this->hasMany(SideChat::class)->latest();
    }

    /** The groups the side chat list folds under, in the order they're shown. */
    public function sideChatForums(): HasMany
    {
        return $this->hasMany(SideChatForum::class)->orderBy('position')->orderBy('id');
    }

    /** The channel's interactive widgets — its music player, its kanban board. */
    public function widgets(): HasMany
    {
        return $this->hasMany(Widget::class);
    }

    /**
     * The channel's own shared whiteboard: every committed stroke in paint order.
     *
     * Layer first, then id. Layer *is* paint order now — that's the whole feature — and within
     * a layer the older mark is still underneath.
     */
    public function whiteboardStrokes(): HasMany
    {
        return $this->hasMany(WhiteboardStroke::class)->orderBy('layer')->orderBy('id');
    }

    /** The channel's Side Desk note — its one shared markdown document. */
    public function spaceNote(): HasOne
    {
        return $this->hasOne(SpaceNote::class);
    }

    /** The channel's Open Canvas cards, in stack order (bottom first). */
    public function canvasItems(): HasMany
    {
        return $this->hasMany(CanvasItem::class)->orderBy('z');
    }

    /** The Side Desk Calendar app's entries for this channel. */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /** The channel's Docs app files. */
    public function spaceDocuments(): HasMany
    {
        return $this->hasMany(SpaceDocument::class);
    }

    /** How far each member has read in this channel. */
    public function reads(): HasMany
    {
        return $this->hasMany(ChannelRead::class);
    }

    /** Who is currently sitting in this voice channel. Empty for a text channel. */
    public function voiceParticipants(): HasMany
    {
        return $this->hasMany(VoiceParticipant::class);
    }

    /**
     * The channel's explicit allow-list. Only consulted when `is_private` — a public
     * channel's roster is its container's, and keeping rows for that would be a second,
     * silently-diverging copy of the server's membership.
     */
    public function allowedMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * May this person see (and therefore read, post in, subscribe to) this channel?
     *
     * Two gates, in order. Membership of the container is the one that has always been
     * here: you must be in the server, the DM or the group chat. Private channels add a
     * second: you must also be on the channel's own allow-list, unless you're the server's
     * owner or one of its admins — the people who set the lock keep the key.
     *
     * Every path into a channel funnels through this method (the request layer, the
     * broadcast auth in routes/channels.php, the sidebar listing), which is why the access
     * rule could be added in one place rather than at each of them.
     */
    public function hasMember(User $user): bool
    {
        $container = $this->container();

        if ($container === null || ! $container->hasMember($user)) {
            return false;
        }

        if (! $this->passesAccess($user, $container)) {
            return false;
        }

        // A discussion is also gated by the channel it is a discussion of. Without this line a
        // private channel would hide itself and then hand out every one of its discussions,
        // since the children carry `is_private = false` — the lock is on the container, so the
        // container is where the children have to ask.
        $this->loadMissing('parent');

        return $this->parent === null || $this->parent->passesAccess($user, $container);
    }

    /**
     * This one channel's own access gate, container membership already established.
     *
     * Split out of {@see hasMember} because it now has to be asked twice — once of the
     * discussion, once of its parent — and asking it twice must mean asking the *same*
     * question, not two similar ones written out separately.
     */
    public function passesAccess(User $user, ?MessageContainer $container = null): bool
    {
        if (! $this->is_private) {
            return true;
        }

        $container ??= $this->container();

        return ($container instanceof Server && $container->isStaff($user))
            || $this->allowedMembers()->whereKey($user->getKey())->exists();
    }

    /**
     * Every channel this person may read, as a query rather than an answer.
     *
     * The set-shaped twin of {@see hasMember}: that one asks about a channel you already
     * hold, this one asks for all of them at once. Search needs the second form — "which of
     * the million messages in this database may you see" cannot be answered a row at a
     * time — and the two must agree exactly, or search becomes the one door in the app with
     * a different lock on it. So the clauses below are hasMember's, in the same order:
     * container membership, then the private channel's own allow-list, with a server's
     * staff let through because the people who set the lock keep the key.
     *
     * Left as a subquery on purpose. `whereIn('channel_id', Channel::visibleTo($user)
     * ->select('id'))` is one round trip and one plan; plucking the ids first is a second
     * query whose answer is stale the moment it lands.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->where(function (Builder $inServer) use ($user) {
                $inServer
                    ->whereIn('server_id', $user->servers()->select('servers.id'))
                    ->where(fn (Builder $access) => $this->applyAccessClause($access, $user, 'channels'));
            })->orWhereIn('conversation_id', $user->conversations()->select('conversations.id'));
        })->where(function (Builder $q) use ($user) {
            // ...and, if this is a discussion, its container has to let you in too. The set-shaped
            // twin of the parent check in hasMember, and it has to be here rather than left to the
            // caller: search reaches messages by channel id alone, so a private channel whose
            // discussions were visible would be a private channel with a public archive.
            //
            // Membership of the server or conversation isn't re-asked of the parent — a discussion
            // carries its container's `server_id`/`conversation_id`, so the clause above already
            // settled that for both rows at once.
            $q->whereNull('parent_id')->orWhereExists(function ($exists) use ($user) {
                $exists->from('channels as parents')->whereColumn('parents.id', 'channels.parent_id');
                $this->applyAccessClause($exists, $user, 'parents');
            });
        });
    }

    /**
     * "Is this row's own lock open for you?", as a grouped where clause on whichever table
     * holds the row — `channels` for the discussion itself, `parents` for its container.
     *
     * Pulled out for the same reason {@see passesAccess} was: the rule is now asked of two
     * rows per channel, and two copies of an access rule is one copy and one future bug.
     *
     * @param  Builder<Channel>|\Illuminate\Database\Query\Builder  $query
     */
    private function applyAccessClause($query, User $user, string $table): void
    {
        $query->where(function ($access) use ($user, $table) {
            $access
                ->where($table.'.is_private', false)
                ->orWhereIn($table.'.server_id', Server::where('owner_id', $user->getKey())->select('id'))
                ->orWhereIn($table.'.server_id', $user->servers()->wherePivot('role', Server::ROLE_ADMIN)->select('servers.id'))
                ->orWhereExists(fn ($exists) => $exists
                    ->from('channel_user')
                    ->whereColumn('channel_user.channel_id', $table.'.id')
                    ->where('channel_user.user_id', $user->getKey()));
        });
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isVoice(): bool
    {
        return $this->type === 'voice';
    }

    /** A Side Space — a walkable room with proximity audio. */
    public function isSpace(): bool
    {
        return $this->type === 'space';
    }

    /** An app channel — a channel whose body is an application rather than a timeline. */
    public function isApp(): bool
    {
        return $this->type === 'app';
    }

    /** Which app this is, when it's an app channel. Null for every other kind of channel. */
    public function app(): HasOne
    {
        return $this->hasOne(ChannelApp::class);
    }

    /**
     * The app this channel should be *drawn* as — its own, or its first discussion's.
     *
     * A container has no app row: the row hangs off the discussion, exactly as a Side Space's
     * map does. But the sidebar draws containers, so asking a container "which app are you"
     * and getting null is how an app channel ends up wearing a `#` like a text channel.
     *
     * So the container borrows from what's inside it. First discussion rather than a survey of
     * all of them, because the tree shows one icon per row and a channel holding a tracker and
     * a board has to pick one — the first is the one it was created as.
     *
     * Reads only already-loaded relations and never triggers a query: it's called once per
     * sidebar row, and lazy loading throws outside production anyway.
     */
    public function displayAppId(): ?string
    {
        if ($this->relationLoaded('app') && $this->app !== null) {
            return $this->app->app_id;
        }

        if (! $this->relationLoaded('discussions')) {
            return null;
        }

        return $this->discussions
            ->first(fn (Channel $d) => $d->relationLoaded('app') && $d->app !== null)
            ?->app?->app_id;
    }

    /**
     * This channel's Tracker projects.
     *
     * Storage hanging off the channel, exactly like its calendar events and canvas items — so a
     * tracker app channel has one, and so does any channel whose Side Desk shows the Tracker
     * tab. Nothing here is conditional on the channel's `type`.
     *
     * @return HasMany<TrackerProject, $this>
     */
    public function trackerProjects(): HasMany
    {
        return $this->hasMany(TrackerProject::class);
    }

    /**
     * The channel's shared tag vocabulary — used by the Tracker, and by whatever picks up
     * {@see Concerns\HasAppActivity} next.
     *
     * @return HasMany<AppTag, $this>
     */
    public function appTags(): HasMany
    {
        return $this->hasMany(AppTag::class);
    }

    /**
     * This channel's Polls — the wall the Polls app draws.
     *
     * @return HasMany<AppPoll, $this>
     */
    public function polls(): HasMany
    {
        return $this->hasMany(AppPoll::class);
    }

    /**
     * This channel's Sticker Wall.
     *
     * @return HasMany<AppSticker, $this>
     */
    public function stickers(): HasMany
    {
        return $this->hasMany(AppSticker::class);
    }

    /** The room itself, when this is a Side Space. Null for every other kind of channel. */
    public function spaceMap(): HasOne
    {
        return $this->hasOne(SideSpaceMap::class);
    }

    /** The game currently living in this Side Space, if any — proposed, running or just ended. */
    public function spaceGame(): HasOne
    {
        return $this->hasOne(SpaceGame::class);
    }

    /** Belongs to a DM or a group chat rather than a server. */
    public function isDirect(): bool
    {
        return $this->conversation_id !== null;
    }

    /**
     * Can a call be held in here?
     *
     * In a server, only in a voice channel — #general is for typing in, and a call
     * nobody was invited to would just be noise appearing in the sidebar.
     *
     * In a DM or group chat, always. There is only ever one channel, so refusing to call
     * from it would mean refusing to call at all; and the person you're calling gets rung
     * rather than ambushed. So a chat's channel is a text channel that can also hold a
     * call — which is why this is a question about the *container*, not about `type`.
     *
     * A Side Space always can, and by the same argument a voice channel can: walking into the
     * room *is* joining the call. That one clause is what lights the whole call stack up for the
     * new type — the `voice.{id}` presence channel, the roster, the heartbeat and the sidebar
     * all gate on this method and needed nothing else.
     */
    public function allowsCalls(): bool
    {
        return $this->isVoice() || $this->isSpace() || $this->isDirect();
    }

    /** Effects attached to particular people in this channel's call. */
    public function voiceEffectAssignments(): HasMany
    {
        return $this->hasMany(VoiceEffectAssignment::class);
    }

    /**
     * Everything this call plays when people come and go, as one payload.
     *
     * Two layers, and the split is the feature: `default` is what happens for anybody in
     * particular, and `people` is the list of exceptions the owner has singled out. Handed
     * over whole rather than as a lookup per arrival, because it has to be in the browser
     * *before* the door opens — an effect fetched on the event it exists for is an effect
     * that plays late or not at all.
     *
     * @return array{
     *     default: array{join: string|null, leave: string|null},
     *     people: array<int, array{user_id: int, join: string|null, leave: string|null}>
     * }
     */
    public function voiceEffects(): array
    {
        return [
            'default' => [
                'join' => $this->join_effect,
                'leave' => $this->leave_effect,
            ],
            'people' => $this->voiceEffectAssignments()
                ->get()
                ->map(fn (VoiceEffectAssignment $a) => [
                    'user_id' => $a->user_id,
                    'join' => $a->join_effect,
                    'leave' => $a->leave_effect,
                ])
                ->values()
                ->all(),
        ];
    }
}
