<?php

namespace App\Services;

use App\Contracts\MessageContainer;
use App\Models\Nickname;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who is called what, where.
 *
 * Three names can be in play for one person in one place, and they beat each other in a
 * fixed order:
 *
 *   1. **What I call them here** — my private alias. Only I see it, so it wins for me.
 *   2. **What they're called here** — the public nickname they (or the server's owner)
 *      set. Everyone in the place sees it.
 *   3. **Their name** — the global one on their account.
 *
 * The map this produces is deliberately *sparse*: it holds only the people whose name is
 * overridden here. Everyone else is absent, and the client falls through to the name it
 * already has on the user object. That keeps it small enough to ship as one blob when a
 * place is opened, which is what saves every message, roster and voice tile from needing
 * a nickname of its own attached.
 */
final class NicknameService
{
    /**
     * The two kinds of naming in force in `$place` for `$viewer`, kept apart.
     *
     * They're returned separately rather than pre-collapsed because the difference matters
     * downstream. `public` is what *everyone* here calls this person, which makes it the
     * only name a client may put in text other people will read — an @mention, above all,
     * has to name somebody every reader's parser can find. `private` is the viewer's own
     * relabelling and exists nowhere but on their screen; it wins wherever a name is merely
     * being *displayed* to them, which the client does by laying one over the other.
     *
     * @return array{public: array<int, string>, private: array<int, string>}
     */
    public function mapsFor(MessageContainer&Model $place, User $viewer): array
    {
        $rows = Nickname::query()
            ->where('place_type', $place->getMorphClass())
            ->where('place_id', $place->getKey())
            ->where(fn ($query) => $query->whereNull('viewer_id')->orWhere('viewer_id', $viewer->id))
            ->get(['user_id', 'viewer_id', 'nickname']);

        $maps = ['public' => [], 'private' => []];

        foreach ($rows as $row) {
            $bucket = $row->viewer_id === null ? 'public' : 'private';
            $maps[$bucket][(int) $row->user_id] = $row->nickname;
        }

        return $maps;
    }

    /**
     * Every name a member can be @mentioned by here: their account name, and their public
     * nickname when they have one.
     *
     * Both have to work. Someone who reads "@Ada" in a server where Ada goes by "ada-ops"
     * is reading a message written by somebody who saw one name or the other, and either
     * should light up her sidebar. Private aliases are deliberately absent — a name only
     * one person can see is a name nobody else's parser could ever match.
     *
     * @return array<int, array<int, string>> Member id => the names that address them.
     */
    public function mentionNamesFor(MessageContainer&Model $place): array
    {
        /** @var array<int, string> $names */
        $names = $place->members()->pluck('name', 'users.id')->all();

        $public = Nickname::query()
            ->where('place_type', $place->getMorphClass())
            ->where('place_id', $place->getKey())
            ->whereNull('viewer_id')
            ->pluck('nickname', 'user_id')
            ->all();

        $out = [];

        foreach ($names as $id => $name) {
            $id = (int) $id;
            $out[$id] = array_values(array_unique(array_filter([$name, $public[$id] ?? null])));
        }

        return $out;
    }

    /**
     * Set — or, with a null/blank nickname, clear — one naming.
     *
     * `$viewer` null writes the public nickname; a viewer writes their own private alias.
     * Clearing deletes the row rather than storing an empty string, so "no nickname" has
     * exactly one representation and mapsFor never has to filter blanks out.
     */
    public function set(MessageContainer&Model $place, User $target, ?User $viewer, ?string $nickname): ?Nickname
    {
        $nickname = $nickname !== null ? trim($nickname) : null;

        $keys = [
            'place_type' => $place->getMorphClass(),
            'place_id' => $place->getKey(),
            'user_id' => $target->id,
            'viewer_id' => $viewer?->id,
        ];

        if ($nickname === null || $nickname === '') {
            Nickname::query()->where($keys)->delete();

            return null;
        }

        return Nickname::query()->updateOrCreate($keys, ['nickname' => $nickname]);
    }

    /**
     * May `$actor` set the *public* nickname of `$target` in `$place`?
     *
     * Your own, always — your name here is yours. Somebody else's, only if you own the
     * place, and only servers have an owner: a group chat's creator is not its boss, and
     * a DM has nobody at all, so in a chat this is self-service or nothing.
     */
    public function canSetPublic(MessageContainer&Model $place, User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $place instanceof Server && $place->owner_id === $actor->id;
    }
}
