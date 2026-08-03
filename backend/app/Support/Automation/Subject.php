<?php

namespace App\Support\Automation;

use App\Models\Nickname;
use App\Models\Server;
use App\Models\User;

/**
 * The "who" half of every trigger's context, built in one place.
 *
 * Before this, each trigger assembled its own `user_id` / `user_name` pair, and they didn't
 * assemble them the same way: an id came off a column and a name came off a *relation*. When
 * that relation wasn't loaded the name arrived empty while the id arrived fine — so filtering
 * on `user_id` worked and filtering on `user_name` silently matched nothing, with no error
 * anywhere to say why. One helper, used by every trigger, is what stops that reappearing.
 *
 * It also settles what these fields *mean*, which matters more than it sounds:
 *
 *  - `user_name` is the **account** name.
 *  - `user_nickname` is what this server calls them, which is what the timeline and the
 *    roster actually show. Filtering on the name you can see requires this one.
 *  - `user_email` is the account's email — unique, never renamed, and therefore the field
 *    to reach for when a filter has to pick out one specific person for good.
 */
final class Subject
{
    /**
     * @return array<string, mixed>
     */
    public static function fields(?User $user, ?Server $server = null): array
    {
        if ($user === null) {
            return ['user_id' => null, 'user_name' => '', 'user_nickname' => '', 'user_email' => ''];
        }

        return [
            'user_id' => $user->getKey(),
            'user_name' => (string) $user->name,
            // Falls back to the account name rather than empty: "what are they called here"
            // has an answer for everybody, and a filter shouldn't have to special-case the
            // people who never set one.
            'user_nickname' => self::nicknameIn($server, $user) ?? (string) $user->name,
            'user_email' => (string) $user->email,
        ];
    }

    /**
     * The same, when the caller has an id and *may* have the model.
     *
     * Loads it if it wasn't passed, which is the defensive half: a trigger firing from a
     * place that forgot to eager-load must not produce a context with an id and no name.
     *
     * @return array<string, mixed>
     */
    public static function forId(?int $userId, ?User $user = null, ?Server $server = null): array
    {
        return self::fields($user ?? ($userId === null ? null : User::find($userId)), $server);
    }

    /**
     * Their public nickname in this server, or null.
     *
     * Public only. A *private* alias is one person's name for another and is deliberately
     * invisible to everybody else — putting it in a rule's context would leak it to whoever
     * can read the audit log.
     */
    private static function nicknameIn(?Server $server, User $user): ?string
    {
        if ($server === null) {
            return null;
        }

        return Nickname::query()
            ->where('place_type', $server->getMorphClass())
            ->where('place_id', $server->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('viewer_id')
            ->value('nickname');
    }
}
