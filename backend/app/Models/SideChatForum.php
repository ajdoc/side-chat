<?php

namespace App\Models;

use Database\Factories\SideChatForumFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of side chats inside one channel — the "group forum" heading the list
 * folds under.
 *
 * See the migration for why this exists alongside tags. In short: a tag describes a post,
 * a forum arranges the list, and only one of those can be created empty, renamed, ordered
 * and curated.
 */
class SideChatForum extends Model
{
    /** @use HasFactory<SideChatForumFactory> */
    use HasFactory;

    /** Long enough to name a topic, short enough to stay one line in the panel. */
    public const MAX_NAME_LENGTH = 60;

    protected $fillable = ['channel_id', 'name', 'position'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Every group this person may see, for search. The channel gate alone, like the posts
     * filed under it — a heading is part of how the list is laid out, and the list is open
     * to the whole channel.
     *
     * @param  Builder<SideChatForum>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('channel_id', Channel::query()->visibleTo($user)->select('channels.id'));
    }

    /** The posts filed here. Ordering is the list's business, not the group's. */
    public function sideChats(): HasMany
    {
        return $this->hasMany(SideChat::class);
    }

    /**
     * May this person create, rename, reorder or delete this channel's forums?
     *
     * The server's staff — the same rule that governs the channels themselves, and for the
     * same reason: a forum is part of how the place is laid out, not a thing a passer-by
     * rearranges for everybody else. Filing your own post into one is open to anyone, which
     * is the useful half and lives on the post, not here.
     *
     * A DM or group chat has no staff. Rather than making forums impossible there, everyone
     * in the chat qualifies — in a room of three people the alternative to "anyone may tidy
     * up" is "nobody may".
     */
    public static function canManageIn(Channel $channel, User $user): bool
    {
        $container = $channel->container();

        if ($container instanceof Server) {
            return $container->isStaff($user);
        }

        return $container !== null && $container->hasMember($user);
    }
}
