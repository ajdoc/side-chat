<?php

namespace App\Models;

use App\Support\Apps\AppRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An app in the catalogue that didn't ship with the client.
 *
 * The dynamic half of {@see AppRegistry}. See the migration for what a row means and why the
 * entry URL must live on a foreign origin.
 */
class InstalledApp extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'icon', 'entry_url', 'version', 'enabled', 'bot_id', 'installed_by'];

    protected $attributes = ['enabled' => true, 'version' => '0.0.0'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** The identity it acts as when it calls the API. Null while it only draws. */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * The slugs a channel may currently be created as.
     *
     * Disabled apps are excluded, which is the kill switch working: no *new* channel can be
     * made with one, while the channels that already point at it keep their timelines and
     * simply render the "app unavailable" notice.
     *
     * @return array<int, string>
     */
    public static function enabledSlugs(): array
    {
        return static::query()->where('enabled', true)->pluck('slug')->all();
    }

    /**
     * A slug is only free if no built-in already answers to it.
     *
     * The unique index can't see the built-ins — they're a PHP constant — so this is the check
     * that keeps one namespace honest. An installed app shadowing `tracker` would silently
     * change what every existing tracker channel renders.
     */
    public static function slugAvailable(string $slug): bool
    {
        // isExternal() is true when nothing built in answers to the slug, which is exactly the
        // first half of "free".
        return AppRegistry::isExternal($slug)
            && ! static::where('slug', $slug)->exists();
    }
}
