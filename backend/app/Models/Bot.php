<?php

namespace App\Models;

use Database\Factories\BotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The machine half of a bot: its server, its owner, and its credential.
 *
 * The half that chats is a plain {@see User} with `is_bot` set — see the migration for why
 * an author is an author whether or not a person is behind it. This row is what that user
 * authenticates as, and it's deliberately not reachable from anything that renders a
 * message: nothing on the timeline should be able to touch a token.
 */
class Bot extends Model
{
    /** @use HasFactory<BotFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'server_id', 'created_by', 'description', 'token_hash', 'last_used_at',
        'webhook_url', 'webhook_secret', 'events', 'webhook_failures', 'webhook_disabled_at',
    ];

    /** Never serialise either secret — nothing outside the delivery job has a use for them. */
    protected $hidden = ['token_hash', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'webhook_disabled_at' => 'datetime',
            'events' => 'array',
            // Reversible, unlike the API token: signing requires the value itself. See the
            // webhooks migration.
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * Events delivered when a bot hasn't said which it wants.
     *
     * `message.created` alone. It's the event every bot needs and the only one that can be
     * built from what Phase 2 hooks into; opting in to more is an explicit act.
     */
    public const DEFAULT_EVENTS = ['message.created'];

    /** Every event name a bot may subscribe to. Validated against, so it's the public list. */
    public const EVENTS = ['message.created'];

    /**
     * The slash commands this bot has claimed. Replaced wholesale when it re-registers —
     * see BotCommandController.
     *
     * @return HasMany<BotCommand>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(BotCommand::class);
    }

    /** The account it posts as. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The human who made it, or null if their account is gone. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Is this bot listening at all?
     *
     * A URL that's been switched off after too many failures stays in the column on
     * purpose — the owner's screen shows them what broke, and re-enabling is a flag flip
     * rather than retyping a URL nobody wrote down.
     */
    public function webhookActive(): bool
    {
        return $this->webhook_url !== null && $this->webhook_disabled_at === null;
    }

    /**
     * Which events this bot wants. Null in the column means the default set, not silence:
     * a bot that registered a URL and said nothing else plainly wants the ordinary events,
     * and delivering nothing would look like a broken integration rather than a choice.
     *
     * @return array<int, string>
     */
    public function subscribedEvents(): array
    {
        return $this->events ?? self::DEFAULT_EVENTS;
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->subscribedEvents(), true);
    }

    /** A fresh signing secret. Shown once, like the token — see BotController. */
    public static function generateWebhookSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * A fresh bearer token, shown to its creator once and never recoverable after.
     *
     * The `sc_bot_` prefix is there to be recognisable in a leak: a secret scanner (and a
     * person reading a stack trace) can tell what this string opens without having to try it.
     */
    public static function generateToken(): string
    {
        return 'sc_bot_'.Str::random(48);
    }

    /**
     * How a token is stored and looked up.
     *
     * Plain sha256 rather than a password hash: this is 48 characters of CSPRNG output, so
     * there's no dictionary to slow an attacker down through, and a bcrypt round on every
     * bot request would mean an unindexable table scan to find the row.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
