<?php

namespace App\Models;

use App\Contracts\MessageContainer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelFactory> */
    use HasFactory;

    public const TYPES = ['text', 'voice'];

    protected $fillable = ['server_id', 'conversation_id', 'name', 'type', 'position'];

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
}
