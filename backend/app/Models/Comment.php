<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Comment extends Model
{
    /** @use HasFactory<\Database\Factories\CommentFactory> */
    use HasFactory;

    protected $fillable = ['message_id', 'user_id', 'body', 'body_key', 'emoji'];

    /**
     * The grouping key for a comment: what makes "Looks good" and "looks good " the same
     * phrase. Kept here so the action, the unique constraint's fill, and any backfill all
     * normalize identically.
     */
    public static function normalize(string $body): string
    {
        return Str::lower(trim($body));
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
