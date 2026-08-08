<?php

namespace App\Models;

use App\Support\Commands\CommandParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A server's bot configuration. See the bot_settings migration.
 */
class BotSettings extends Model
{
    protected $table = 'bot_settings';

    protected $fillable = [
        'server_id', 'command_prefix', 'mod_log_channel_id', 'announcement_channel_id',
        'reminder_channel_id', 'mod_roles',
    ];

    protected function casts(): array
    {
        return ['mod_roles' => 'array'];
    }

    public const DEFAULT_PREFIX = '!';

    /**
     * Mirrors the column defaults, in PHP.
     *
     * The migration's `default('!')` applies when the row is *written*, not to the model
     * that `firstOrCreate` hands back — so without this the very first read of a server's
     * settings returns a null prefix and the Configuration page renders an empty box for a
     * value that is really `!`.
     */
    protected $attributes = [
        'command_prefix' => self::DEFAULT_PREFIX,
    ];

    /**
     * Keep the prefix cache honest.
     *
     * On the model rather than in the controller: the cache is this class's own invention,
     * so any future writer gets the invalidation for free instead of having to know about it.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $settings) => self::forget((int) $settings->server_id));
        static::deleted(fn (self $settings) => self::forget((int) $settings->server_id));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * This server's settings, creating the row on first read.
     *
     * Read-through rather than making every caller cope with null. The defaults in the
     * migration *are* the configuration of a server nobody has configured, so materialising
     * them costs one row and removes a null check from every reader.
     */
    public static function forServer(Server $server): self
    {
        return self::firstOrCreate(['server_id' => $server->getKey()]);
    }

    /**
     * This server's command prefix, cached.
     *
     * Read on the send path — potentially for every message anybody types — so it must not
     * be a query each time. Cached forever and busted on write (see {@see self::forget}),
     * because the value changes roughly never and a TTL would just mean re-reading it for
     * no reason.
     *
     * Callers should still gate on {@see CommandParser::mightBePrefixed}
     * first: this is cheap, but not free, and the answer is irrelevant for a message that
     * couldn't be a command anyway.
     */
    public static function prefixFor(int $serverId): string
    {
        return Cache::rememberForever(
            self::cacheKey($serverId),
            fn () => (string) (self::where('server_id', $serverId)->value('command_prefix') ?? self::DEFAULT_PREFIX),
        );
    }

    public static function forget(int $serverId): void
    {
        Cache::forget(self::cacheKey($serverId));
    }

    private static function cacheKey(int $serverId): string
    {
        return "bot-settings:prefix:{$serverId}";
    }

    /** May somebody with this role run the moderation commands? Empty list means nobody. */
    public function allowsModeration(string $role): bool
    {
        return in_array($role, $this->mod_roles ?? [], true);
    }
}
