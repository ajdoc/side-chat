<?php

namespace App\Models;

use Database\Factories\SideChatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SideChat extends Model
{
    /** @use HasFactory<SideChatFactory> */
    use HasFactory;

    /** How many tags a post may carry, and how long one may be. Enforced in the request. */
    public const MAX_TAGS = 8;

    public const MAX_TAG_LENGTH = 32;

    protected $fillable = ['channel_id', 'side_chat_forum_id', 'user_id', 'message_id', 'name', 'tags', 'origin_author', 'origin_excerpt', 'desk_apps'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // The Side Desk's tab strip, as an ordered array of app ids. Null until customised —
        // see the migration for why that isn't the same as storing the defaults.
        // `tags` is the forum layer's labels: a flat array of strings, order as typed.
        return ['desk_apps' => 'array', 'tags' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Every side chat this person may see, for search.
     *
     * The channel gate and nothing more — deliberately weaker than the one on its messages.
     * A side chat's *card* is public to the channel: SideChatController::index hands the
     * whole list to any member, which is what puts "Deploy plan — 12 replies, started by
     * Ana" in the panel and on the timeline for people who never joined. Joining is what
     * gets you the conversation inside, and that stays gated (see Message::scopeVisibleTo).
     *
     * So a title found here is a title already on that person's screen. Anything stricter
     * would make search the one place in the app that pretends these posts don't exist.
     *
     * @param  Builder<SideChat>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('channel_id', Channel::query()->visibleTo($user)->select('channels.id'));
    }

    /**
     * The group this post is filed under, or null for "Uncategorised".
     *
     * Null is a first-class answer, not a missing one: every post ever made before forums
     * existed has it, and a post whose forum is deleted goes back to it. See the migration.
     */
    public function forum(): BelongsTo
    {
        return $this->belongsTo(SideChatForum::class, 'side_chat_forum_id');
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

    /** The Side Desk note — this side chat's one shared markdown document. */
    public function spaceNote(): HasOne
    {
        return $this->hasOne(SpaceNote::class);
    }

    /** The Open Canvas cards, in stack order (bottom first). */
    public function canvasItems(): HasMany
    {
        return $this->hasMany(CanvasItem::class)->orderBy('z');
    }

    /** The Side Desk Calendar app's entries for this side chat. */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
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

    /**
     * Clean a list of tags as typed into the list this post will actually carry.
     *
     * Trimmed, lowercased, blanks dropped, duplicates collapsed, capped. Lowercasing is
     * what makes "Bug" and "bug" one tag in the filter row rather than two that look
     * identical — a tag exists to group posts, and a case-sensitive grouping doesn't.
     *
     * Lives here, next to the column, so creating a post and editing one normalise
     * identically; the same reasoning as {@link Comment::normalize}.
     *
     * @param  array<int, string>  $tags
     * @return array<int, string>
     */
    public static function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => Str::lower(trim((string) $tag)))
            ->filter()
            ->unique()
            ->take(self::MAX_TAGS)
            ->values()
            ->all();
    }

    /** Reactions to the post itself — the forum list's 👍, not any message inside it. */
    public function reactions(): HasMany
    {
        return $this->hasMany(SideChatReaction::class);
    }

    /**
     * Comments ("word-reactions") on the post itself — `✓ Looks good (18)`.
     *
     * Distinct from *replying*, which is what the side chat's own timeline is for. A
     * comment is a short co-signable phrase about the post; a reply is a conversation
     * inside it. The forum list shows the first and links to the second.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(SideChatComment::class);
    }

    /**
     * May this person retitle, retag or delete the post? Its author (the OP), or the
     * server's staff — the same shape as {@link Thread::canManage}, and for the same
     * reason: the title is how everybody else finds it, so it isn't a passer-by's to change.
     *
     * A DM or group chat has no staff, so there it's the OP alone.
     */
    public function canManage(User $user): bool
    {
        if ($this->user_id === $user->getKey()) {
            return true;
        }

        $container = $this->loadMissing('channel')->channel?->container();

        return $container instanceof Server && $container->isStaff($user);
    }
}
