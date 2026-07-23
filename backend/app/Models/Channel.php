<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Channel extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelFactory> */
    use HasFactory;

    public const TYPES = ['text', 'voice'];

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
        'position',
        'join_effect',
        'leave_effect',
    ];

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

    /** The channel's Side Space note — its one shared markdown document. */
    public function spaceNote(): HasOne
    {
        return $this->hasOne(SpaceNote::class);
    }

    /** The channel's Open Canvas cards, in stack order (bottom first). */
    public function canvasItems(): HasMany
    {
        return $this->hasMany(CanvasItem::class)->orderBy('z');
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

    public function hasMember(User $user): bool
    {
        return (bool) $this->container()?->hasMember($user);
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isVoice(): bool
    {
        return $this->type === 'voice';
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
     */
    public function allowsCalls(): bool
    {
        return $this->isVoice() || $this->isDirect();
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
