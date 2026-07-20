<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SideChat extends Model
{
    /** @use HasFactory<\Database\Factories\SideChatFactory> */
    use HasFactory;

    protected $fillable = ['channel_id', 'user_id', 'message_id', 'name', 'origin_author', 'origin_excerpt'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Who started it — the "started by" on the card, kept even after they leave. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The message this side chat branched off (may be null). */
    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Threads spun off this side chat's messages — its own, kept out of the channel's list. */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    /** The shared whiteboard: every committed stroke, oldest first (paint order). */
    public function whiteboardStrokes(): HasMany
    {
        return $this->hasMany(WhiteboardStroke::class)->orderBy('id');
    }

    /** The Side Space note — this side chat's one shared markdown document. */
    public function spaceNote(): HasOne
    {
        return $this->hasOne(SpaceNote::class);
    }

    /** The Open Canvas cards, in stack order (bottom first). */
    public function canvasItems(): HasMany
    {
        return $this->hasMany(CanvasItem::class)->orderBy('z');
    }

    /** The Docs app files for this side chat. */
    public function spaceDocuments(): HasMany
    {
        return $this->hasMany(SpaceDocument::class);
    }

    /** The roster — who has joined. Carries the pivot role and when they joined. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps()
            ->orderByPivot('created_at');
    }

    /** May this user post / pin / record decisions here? (i.e. have they joined?) */
    public function hasParticipant(User $user): bool
    {
        return $this->participants()->whereKey($user->id)->exists();
    }
}
