<?php

namespace App\Support\Notifications;

/**
 * How loud one place is allowed to be.
 *
 * Three levels and no more. The temptation is to add "mentions and replies", "direct
 * mentions but not @all", and so on; each one is another rule the user has to hold in
 * their head to predict whether their phone will ring, and the whole point of this
 * setting is to make that predictable.
 *
 * Ordered quietest-last so {@see NotificationPolicy} can talk about "at least".
 */
enum NotifyLevel: string
{
    /** Every message in the place. */
    case All = 'all';

    /** Only when the message names you, or says @all. */
    case Mentions = 'mentions';

    /** Nothing, ever. The badge still counts — this governs alerts, not unread state. */
    case None = 'none';

    /** @return array<int, string> For validation rules and the client's picker alike. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Null-tolerant parse, so a legacy or hand-edited row degrades rather than throws. */
    public static function parse(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** Does a message of this kind clear this level's bar? */
    public function admits(bool $isMention): bool
    {
        return match ($this) {
            self::All => true,
            self::Mentions => $isMention,
            self::None => false,
        };
    }
}
