<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Cached unfurl of one URL. Rows start life as `pending` and are filled in by the
 * FetchLinkPreview job; `failed` rows are kept so a dead link isn't retried on every
 * message that mentions it.
 */
class LinkPreview extends Model
{
    /** @use HasFactory<\Database\Factories\LinkPreviewFactory> */
    use HasFactory;

    /** Consider a successful unfurl stale after this long, and refetch it. */
    public const TTL_DAYS = 7;

    protected $fillable = [
        'url_hash', 'url', 'status', 'kind', 'title', 'description', 'site_name', 'image_url', 'fetched_at',
    ];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    public static function hashFor(string $url): string
    {
        return hash('sha256', $url);
    }

    public function isStale(): bool
    {
        return $this->fetched_at === null
            || $this->fetched_at->lt(now()->subDays(self::TTL_DAYS));
    }

    /** Only `ok` previews are worth showing — pending/failed render nothing. */
    public function isRenderable(): bool
    {
        return $this->status === 'ok';
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class);
    }
}
