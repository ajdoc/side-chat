<?php

namespace App\Support;

/**
 * Finds the mentions in a message body.
 *
 * Two kinds: `@all`, which addresses everyone in the channel, and `@Display Name`, which
 * addresses one member by a name they're shown under. Names are matched against the
 * channel's actual roster rather than a generic `@word` pattern, so a display name with a
 * space in it ("Ada Lovelace") is caught whole and a bare `@` in an email or a code snippet
 * that doesn't name anybody is left alone.
 *
 * A member can answer to more than one name — their account name and whatever they go by
 * in this particular server or chat — so the roster maps an id to a *list*.
 *
 * The frontend renders the same tokens as chips off the very same roster — this is the
 * server-side half, and its only job is to decide whose sidebar should light up.
 */
final class MentionParser
{
    /**
     * @param  array<int, array<int, string>>  $namesById  Member id => every name that
     *                                                     addresses them here. More than one
     *                                                     because a member with a nickname in
     *                                                     this place answers to both it and
     *                                                     their account name — see
     *                                                     NicknameService::mentionNamesFor.
     * @return array{all: bool, user_ids: array<int, int>}
     */
    public static function parse(?string $body, array $namesById): array
    {
        if ($body === null || $body === '') {
            return ['all' => false, 'user_ids' => []];
        }

        // `@all` — but not `x@all` (an address) or `@already` (a name that starts with it).
        $all = preg_match('/(?<![\w@])@all(?![\w])/i', $body) === 1;

        $userIds = [];
        foreach ($namesById as $id => $names) {
            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }

                $pattern = '/(?<![\w@])@'.preg_quote($name, '/').'(?![\w])/i';
                if (preg_match($pattern, $body) === 1) {
                    $userIds[] = (int) $id;
                    break; // one name is enough; they're the same person
                }
            }
        }

        return ['all' => $all, 'user_ids' => array_values(array_unique($userIds))];
    }
}
