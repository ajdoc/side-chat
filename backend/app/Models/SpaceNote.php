<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Side Space's shared note — one markdown document per surface (a side chat or a channel),
 * edited collaboratively. A save replaces the whole body and broadcasts it, but it is *not*
 * blind last-write-wins: every save carries the `version` it was based on, and a save based
 * on a stale version is refused so the client can merge the two edits rather than erase one
 * of them ({@see applyEdit()}). See {@see \App\Http\Controllers\SpaceNoteController} /
 * {@see \App\Http\Controllers\ChannelSpaceNoteController}.
 */
class SpaceNote extends Model
{
    protected $fillable = ['side_chat_id', 'channel_id', 'updated_by', 'content', 'version'];

    /**
     * Save a new body on top of `$baseVersion`, bumping the revision counter.
     *
     * Returns false — the note refreshed to whatever is actually stored — when someone else
     * saved since the editor last synced. That's the whole guard against a note "disappearing"
     * when two people type at once: instead of the slower save flattening the faster one, the
     * loser is told to reconcile against the current body and try again. The conditional
     * `where version` makes that check atomic, so two saves landing in the same instant can't
     * both pass it. A null `$baseVersion` opts out (a client that doesn't track versions).
     */
    public function applyEdit(string $content, int $userId, ?int $baseVersion = null): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->when($baseVersion !== null, fn ($q) => $q->where('version', $baseVersion))
            ->update([
                'content' => $content,
                'updated_by' => $userId,
                'version' => $this->version + 1,
                'updated_at' => now(),
            ]);

        $this->refresh();

        return $updated > 0;
    }

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Who saved the note last — shown as the "edited by" line. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The private broadcast stream this note lives on — the surface's own stream, the same
     * one its messages and whiteboard already travel over. See {@see WhiteboardStroke::streamName()}.
     */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }
}
