<?php

namespace App\Support\Moba;

/**
 * The roster, as the API knows it.
 *
 * ## A mirror, and honestly labelled as one
 *
 * The heroes are *defined* in `game/moba/moba-sim/src/ability.rs`; this is a copy of their names
 * and roles so the API can validate a pick and the lobby can draw a grid without asking the game
 * server. Two lists of one fact is exactly the duplication `AppRegistry` was built to remove,
 * and it is accepted here for one reason: the other list is in another language, in another
 * process, that may not be running when someone opens the lobby.
 *
 * What keeps them honest is that only the *ids* are load-bearing. A wrong label here is a
 * cosmetic bug; a wrong id is refused by the game server at seat time, loudly, rather than
 * producing a hero nobody can play. See {@see MobaTest} for the test that walks the pair.
 */
final class Heroes
{
    /** @var array<string, array{name: string, role: string, family: string}> */
    private const ROSTER = [
        'ironclad' => ['name' => 'Ironclad', 'role' => 'Tank', 'family' => 'fantasy'],
        'emberwitch' => ['name' => 'Emberwitch', 'role' => 'Mage', 'family' => 'fantasy'],
        'jukebox' => ['name' => 'Jukebox', 'role' => 'Support', 'family' => 'sidechat'],
        'ghostuser' => ['name' => 'Ghostuser', 'role' => 'Assassin', 'family' => 'sidechat'],
        'overclock' => ['name' => 'Overclock', 'role' => 'Carry', 'family' => 'scifi'],
        'relay' => ['name' => 'Relay', 'role' => 'Summoner', 'family' => 'scifi'],
    ];

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::ROSTER);
    }

    public static function exists(string $id): bool
    {
        return array_key_exists($id, self::ROSTER);
    }

    /** @return array<int, array{id: string, name: string, role: string, family: string}> */
    public static function all(): array
    {
        return array_map(
            fn (string $id, array $hero) => ['id' => $id, ...$hero],
            array_keys(self::ROSTER),
            array_values(self::ROSTER),
        );
    }
}
