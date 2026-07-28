<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use Database\Factories\ChannelFactory;
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
     */
    public const TYPES = ['text', 'voice', 'space'];

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
        'name',
        'type',
        'is_private',
        'position',
        'join_effect',
        'leave_effect',
        'desk_apps',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // The Side Desk's tab strip, as an ordered array of app ids. Null until customised —
        // see the migration for why that isn't the same as storing the defaults.
        return ['desk_apps' => 'array', 'is_private' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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

    /** The channel's interactive widgets — its music player, its kanban board. */
    public function widgets(): HasMany
    {
        return $this->hasMany(Widget::class);
    }

    /** The channel's own shared whiteboard: every committed stroke, oldest first (paint order). */
    public function whiteboardStrokes(): HasMany
    {
        return $this->hasMany(WhiteboardStroke::class)->orderBy('id');
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

        if (! $this->is_private) {
            return true;
        }

        return ($container instanceof Server && $container->isStaff($user))
            || $this->allowedMembers()->whereKey($user->getKey())->exists();
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
