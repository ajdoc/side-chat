<?php

namespace App\Support\Arpg;

/**
 * Every skill in the Labyrinth, and the rules about who may learn one.
 *
 * ## Why the catalogue lives here and not in the engine
 *
 * The client fights the fight — it's the only thing that knows where anybody is standing — so it
 * would be natural to put the skills next to it. They're here instead, and served to the client
 * over {@see \App\Http\Controllers\ArpgSkillController}, because *learning* a skill is a durable
 * decision about a character: it spends a point, it's bounded by a cap, and it has to be the same
 * fact tomorrow. Two copies of that table would drift the first time a number was tuned, and the
 * copy that mattered would be the one on the client, which is the wrong one.
 *
 * So the split is the same as everywhere else in the crawl: the server owns what a skill *is*,
 * the engine owns what it *does*. Which is why every skill here reduces to a `kind` and some
 * numbers — the engine implements six verbs, not fifty-odd skills, and a new skill (or a whole new
 * job tier) is rows in this file rather than a change to the client.
 *
 * ## The kinds
 *
 *   - `melee`      — swing in reach; `radius` > 0 hits everything around the target.
 *   - `projectile` — a bolt that travels; may pierce, splash, or come in a spread.
 *   - `nova`       — everything around *you*, at once.
 *   - `heal`       — you, or everyone near you.
 *   - `summon`     — something that fights for you until it expires.
 *   - `buff`       — a number on you (or the party) for a while.
 *
 * Numbers scale with the skill's own level: a value is `base + per_level × (level − 1)`, which
 * the engine applies uniformly so the tuning stays in this table.
 */
class Skills
{
    /**
     * The catalogue.
     *
     * `job` is the job it belongs to — a skill from outside your *line* ({@see Jobs::line}) is
     * inheritance, and is what the per-tier foreign cap counts. `level` is the character level it
     * opens at. There's deliberately no `tier` here: that's the job's, and duplicating it would
     * be a second place to get it wrong.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CATALOGUE = [

        // --- swordsman: reach, and everything in it ---
        'cleave' => [
            'job' => 'swordsman', 'name' => 'Cleave', 'level' => 1, 'kind' => 'melee',
            'mana' => 2, 'cooldown' => 0.6,
            'blurb' => 'A wide swing that catches everything beside your target.',
            'params' => ['damage' => 1.1, 'damage_per_level' => 0.12, 'radius' => 1.4],
        ],
        'bash' => [
            'job' => 'swordsman', 'name' => 'Bash', 'level' => 3, 'kind' => 'melee',
            'mana' => 6, 'cooldown' => 2.4,
            'blurb' => 'One enormous blow to one unfortunate skull.',
            'params' => ['damage' => 2.4, 'damage_per_level' => 0.35, 'radius' => 0],
        ],
        'war_cry' => [
            'job' => 'swordsman', 'name' => 'War Cry', 'level' => 6, 'kind' => 'buff',
            'mana' => 14, 'cooldown' => 16,
            'blurb' => 'You and everyone near you hit harder for a while.',
            'params' => ['stat' => 'damage', 'amount' => 4, 'amount_per_level' => 2, 'duration' => 12, 'radius' => 6],
        ],
        'second_wind' => [
            'job' => 'swordsman', 'name' => 'Second Wind', 'level' => 10, 'kind' => 'heal',
            'mana' => 18, 'cooldown' => 20,
            'blurb' => 'Shake it off.',
            'params' => ['amount' => 25, 'amount_per_level' => 10, 'radius' => 0],
        ],

        // --- crusader: the wall that heals ---
        'smite' => [
            'job' => 'crusader', 'name' => 'Smite', 'level' => 1, 'kind' => 'melee',
            'mana' => 3, 'cooldown' => 0.9,
            'blurb' => 'A shield to the face, with conviction behind it.',
            'params' => ['damage' => 1.35, 'damage_per_level' => 0.18, 'radius' => 0],
        ],
        'shield_wall' => [
            'job' => 'crusader', 'name' => 'Shield Wall', 'level' => 3, 'kind' => 'buff',
            'mana' => 10, 'cooldown' => 14,
            'blurb' => 'Armour, for you and anyone sheltering behind you.',
            'params' => ['stat' => 'armour', 'amount' => 6, 'amount_per_level' => 3, 'duration' => 10, 'radius' => 5],
        ],
        'consecrate' => [
            'job' => 'crusader', 'name' => 'Consecrate', 'level' => 6, 'kind' => 'heal',
            'mana' => 16, 'cooldown' => 12,
            'blurb' => 'Holy ground: everyone standing on it mends.',
            'params' => ['amount' => 18, 'amount_per_level' => 7, 'radius' => 5],
        ],
        'holy_bolt' => [
            'job' => 'crusader', 'name' => 'Holy Bolt', 'level' => 10, 'kind' => 'projectile',
            'mana' => 8, 'cooldown' => 0.8,
            'blurb' => 'A shaft of light that does not much care for the undead.',
            'params' => ['damage' => 1.5, 'damage_per_level' => 0.2, 'speed' => 11, 'pierce' => 0, 'splash' => 0, 'count' => 1],
        ],

        // --- archer: everything at range ---
        'arrow_shot' => [
            'job' => 'archer', 'name' => 'Arrow Shot', 'level' => 1, 'kind' => 'projectile',
            'mana' => 1, 'cooldown' => 0.45,
            'blurb' => 'Fast, cheap, and always in your hand.',
            'params' => ['damage' => 1.0, 'damage_per_level' => 0.12, 'speed' => 14, 'pierce' => 0, 'splash' => 0, 'count' => 1],
        ],
        'multishot' => [
            'job' => 'archer', 'name' => 'Multishot', 'level' => 3, 'kind' => 'projectile',
            'mana' => 7, 'cooldown' => 1.4,
            'blurb' => 'Three arrows, one draw, a corridor full of regret.',
            'params' => ['damage' => 0.8, 'damage_per_level' => 0.1, 'speed' => 13, 'pierce' => 0, 'splash' => 0, 'count' => 3, 'spread' => 0.35],
        ],
        'piercing_shot' => [
            'job' => 'archer', 'name' => 'Piercing Shot', 'level' => 6, 'kind' => 'projectile',
            'mana' => 9, 'cooldown' => 1.8,
            'blurb' => 'Goes through the first one. And the second.',
            'params' => ['damage' => 1.6, 'damage_per_level' => 0.22, 'speed' => 16, 'pierce' => 4, 'splash' => 0, 'count' => 1],
        ],
        'eagle_eye' => [
            'job' => 'archer', 'name' => 'Eagle Eye', 'level' => 10, 'kind' => 'buff',
            'mana' => 12, 'cooldown' => 18,
            'blurb' => 'Steady hands, for a while.',
            'params' => ['stat' => 'damage', 'amount' => 6, 'amount_per_level' => 2, 'duration' => 14, 'radius' => 0],
        ],

        // --- thief: in, out, gone ---
        'backstab' => [
            'job' => 'thief', 'name' => 'Backstab', 'level' => 1, 'kind' => 'melee',
            'mana' => 3, 'cooldown' => 1.1,
            'blurb' => 'It is not dishonourable if nobody sees it.',
            'params' => ['damage' => 2.0, 'damage_per_level' => 0.28, 'radius' => 0],
        ],
        'dagger_throw' => [
            'job' => 'thief', 'name' => 'Dagger Throw', 'level' => 3, 'kind' => 'projectile',
            'mana' => 4, 'cooldown' => 0.7,
            'blurb' => 'For the ones that run.',
            'params' => ['damage' => 1.1, 'damage_per_level' => 0.14, 'speed' => 15, 'pierce' => 0, 'splash' => 0, 'count' => 1],
        ],
        'evasion' => [
            'job' => 'thief', 'name' => 'Evasion', 'level' => 6, 'kind' => 'buff',
            'mana' => 10, 'cooldown' => 15,
            'blurb' => 'Harder to hit than to catch.',
            'params' => ['stat' => 'armour', 'amount' => 8, 'amount_per_level' => 3, 'duration' => 10, 'radius' => 0],
        ],
        'shadow_step' => [
            'job' => 'thief', 'name' => 'Shadow Step', 'level' => 10, 'kind' => 'buff',
            'mana' => 8, 'cooldown' => 10,
            'blurb' => 'Somewhere else, quickly.',
            'params' => ['stat' => 'speed', 'amount' => 1.2, 'amount_per_level' => 0.3, 'duration' => 6, 'radius' => 0],
        ],

        // --- mage: the good stuff ---
        'firebolt' => [
            'job' => 'mage', 'name' => 'Firebolt', 'level' => 1, 'kind' => 'projectile',
            'mana' => 3, 'cooldown' => 0.5,
            'blurb' => 'The first thing they teach you, and the last thing you stop using.',
            'params' => ['damage' => 1.2, 'damage_per_level' => 0.16, 'speed' => 12, 'pierce' => 0, 'splash' => 0, 'count' => 1],
        ],
        'frost_nova' => [
            'job' => 'mage', 'name' => 'Frost Nova', 'level' => 3, 'kind' => 'nova',
            'mana' => 12, 'cooldown' => 4,
            'blurb' => 'For when the room is suddenly very full.',
            'params' => ['damage' => 1.3, 'damage_per_level' => 0.2, 'radius' => 3.6],
        ],
        'fireball' => [
            'job' => 'mage', 'name' => 'Fireball', 'level' => 6, 'kind' => 'projectile',
            'mana' => 14, 'cooldown' => 1.6,
            'blurb' => 'Lands heavily, and takes the neighbours with it.',
            'params' => ['damage' => 1.8, 'damage_per_level' => 0.3, 'speed' => 9, 'pierce' => 0, 'splash' => 2.2, 'count' => 1],
        ],
        'arcane_shield' => [
            'job' => 'mage', 'name' => 'Arcane Shield', 'level' => 10, 'kind' => 'buff',
            'mana' => 16, 'cooldown' => 20,
            'blurb' => 'A frail body, briefly reconsidered.',
            'params' => ['stat' => 'armour', 'amount' => 10, 'amount_per_level' => 4, 'duration' => 12, 'radius' => 0],
        ],

        // --- priest: the reason anyone brings a priest ---
        'heal' => [
            'job' => 'priest', 'name' => 'Heal', 'level' => 1, 'kind' => 'heal',
            'mana' => 8, 'cooldown' => 2.5,
            'blurb' => 'You, or whoever near you needs it more.',
            'params' => ['amount' => 22, 'amount_per_level' => 9, 'radius' => 4.5],
        ],
        'smiting_word' => [
            'job' => 'priest', 'name' => 'Smiting Word', 'level' => 3, 'kind' => 'projectile',
            'mana' => 5, 'cooldown' => 0.8,
            'blurb' => 'Said sharply.',
            'params' => ['damage' => 1.15, 'damage_per_level' => 0.15, 'speed' => 12, 'pierce' => 0, 'splash' => 0, 'count' => 1],
        ],
        'bless' => [
            'job' => 'priest', 'name' => 'Bless', 'level' => 6, 'kind' => 'buff',
            'mana' => 15, 'cooldown' => 18,
            'blurb' => 'The party hits harder. You take the credit quietly.',
            'params' => ['stat' => 'damage', 'amount' => 5, 'amount_per_level' => 2, 'duration' => 15, 'radius' => 7],
        ],
        'sanctuary' => [
            'job' => 'priest', 'name' => 'Sanctuary', 'level' => 10, 'kind' => 'nova',
            'mana' => 20, 'cooldown' => 8,
            'blurb' => 'A hard shove for anything that does not belong here.',
            'params' => ['damage' => 1.6, 'damage_per_level' => 0.25, 'radius' => 4.2],
        ],

        // --- necromancer: bring friends ---
        'bone_spear' => [
            'job' => 'necromancer', 'name' => 'Bone Spear', 'level' => 1, 'kind' => 'projectile',
            'mana' => 5, 'cooldown' => 0.9,
            'blurb' => 'Straight down the corridor, through whatever is in it.',
            'params' => ['damage' => 1.3, 'damage_per_level' => 0.18, 'speed' => 13, 'pierce' => 3, 'splash' => 0, 'count' => 1],
        ],
        'raise_skeleton' => [
            'job' => 'necromancer', 'name' => 'Raise Skeleton', 'level' => 3, 'kind' => 'summon',
            'mana' => 15, 'cooldown' => 6,
            'blurb' => 'They were not using it.',
            'params' => ['minion' => 'skeleton', 'count' => 1, 'hp' => 30, 'hp_per_level' => 12, 'damage' => 5, 'damage_per_level' => 2.5, 'duration' => 45],
        ],
        'corpse_burst' => [
            'job' => 'necromancer', 'name' => 'Corpse Burst', 'level' => 6, 'kind' => 'nova',
            'mana' => 13, 'cooldown' => 5,
            'blurb' => 'Waste not.',
            'params' => ['damage' => 1.5, 'damage_per_level' => 0.22, 'radius' => 3.2],
        ],
        'life_tap' => [
            'job' => 'necromancer', 'name' => 'Life Tap', 'level' => 10, 'kind' => 'heal',
            'mana' => 12, 'cooldown' => 9,
            'blurb' => 'Somebody has to pay for it, and it will not be you.',
            'params' => ['amount' => 20, 'amount_per_level' => 8, 'radius' => 0],
        ],

        // --- druid: the wolf, and the weather ---
        'lightning' => [
            'job' => 'druid', 'name' => 'Lightning', 'level' => 1, 'kind' => 'projectile',
            'mana' => 4, 'cooldown' => 0.6,
            'blurb' => 'Quick and unkind.',
            'params' => ['damage' => 1.15, 'damage_per_level' => 0.15, 'speed' => 18, 'pierce' => 1, 'splash' => 0, 'count' => 1],
        ],
        'summon_wolf' => [
            'job' => 'druid', 'name' => 'Summon Wolf', 'level' => 3, 'kind' => 'summon',
            'mana' => 14, 'cooldown' => 8,
            'blurb' => 'Two of them, and they are faster than you.',
            'params' => ['minion' => 'wolf', 'count' => 2, 'hp' => 24, 'hp_per_level' => 9, 'damage' => 4, 'damage_per_level' => 2, 'duration' => 40],
        ],
        'thorns' => [
            'job' => 'druid', 'name' => 'Thorns', 'level' => 6, 'kind' => 'buff',
            'mana' => 11, 'cooldown' => 16,
            'blurb' => 'Bark, in the literal sense.',
            'params' => ['stat' => 'armour', 'amount' => 7, 'amount_per_level' => 3, 'duration' => 14, 'radius' => 4],
        ],
        'bear_form' => [
            'job' => 'druid', 'name' => 'Bear Form', 'level' => 10, 'kind' => 'buff',
            'mana' => 20, 'cooldown' => 24,
            'blurb' => 'Briefly, enormously, a bear.',
            'params' => ['stat' => 'damage', 'amount' => 9, 'amount_per_level' => 3, 'duration' => 15, 'radius' => 0],
        ],

        /*
         * --- second jobs ---
         *
         * Three apiece rather than four: a second job should read as an arrival, not as a second
         * homework assignment, and three that each change how you fight beats four that pad a
         * list. Every one of them is still one of the same six kinds — the whole point of the
         * kind design is that a tier costs the engine nothing.
         *
         * They open at 30, 34 and 40, and they cost real mana; the numbers are a step up rather
         * than a nudge, because the level you advance at is the level the dungeon stops being
         * polite.
         */

        // --- knight ---
        'whirlwind' => [
            'job' => 'knight', 'name' => 'Whirlwind', 'level' => 30, 'kind' => 'melee',
            'mana' => 12, 'cooldown' => 1.6,
            'blurb' => 'Everything within arm’s reach, all at once, repeatedly.',
            'params' => ['damage' => 1.9, 'damage_per_level' => 0.24, 'radius' => 2.4],
        ],
        'crushing_blow' => [
            'job' => 'knight', 'name' => 'Crushing Blow', 'level' => 34, 'kind' => 'melee',
            'mana' => 22, 'cooldown' => 4,
            'blurb' => 'The one you save for whatever is guarding the stairs.',
            'params' => ['damage' => 3.8, 'damage_per_level' => 0.5, 'radius' => 0],
        ],
        'iron_bulwark' => [
            'job' => 'knight', 'name' => 'Iron Bulwark', 'level' => 40, 'kind' => 'buff',
            'mana' => 30, 'cooldown' => 22,
            'blurb' => 'The party stops being a party and becomes a fortification.',
            'params' => ['stat' => 'armour', 'amount' => 16, 'amount_per_level' => 5, 'duration' => 16, 'radius' => 7],
        ],

        // --- paladin ---
        'divine_storm' => [
            'job' => 'paladin', 'name' => 'Divine Storm', 'level' => 30, 'kind' => 'nova',
            'mana' => 26, 'cooldown' => 6,
            'blurb' => 'Light, in every direction, unkindly.',
            'params' => ['damage' => 2.3, 'damage_per_level' => 0.3, 'radius' => 5],
        ],
        'lay_on_hands' => [
            'job' => 'paladin', 'name' => 'Lay on Hands', 'level' => 34, 'kind' => 'heal',
            'mana' => 34, 'cooldown' => 18,
            'blurb' => 'Everyone standing anywhere near you, back on their feet.',
            'params' => ['amount' => 60, 'amount_per_level' => 18, 'radius' => 7],
        ],
        'aura_of_valour' => [
            'job' => 'paladin', 'name' => 'Aura of Valour', 'level' => 40, 'kind' => 'buff',
            'mana' => 32, 'cooldown' => 24,
            'blurb' => 'The whole party hits like you do.',
            'params' => ['stat' => 'damage', 'amount' => 12, 'amount_per_level' => 4, 'duration' => 20, 'radius' => 9],
        ],

        // --- ranger ---
        'arrow_storm' => [
            'job' => 'ranger', 'name' => 'Arrow Storm', 'level' => 30, 'kind' => 'projectile',
            'mana' => 18, 'cooldown' => 2.2,
            'blurb' => 'Six at once. Aim roughly.',
            'params' => ['damage' => 1.2, 'damage_per_level' => 0.16, 'speed' => 15, 'pierce' => 1, 'splash' => 0, 'count' => 6, 'spread' => 0.5],
        ],
        'explosive_shot' => [
            'job' => 'ranger', 'name' => 'Explosive Shot', 'level' => 34, 'kind' => 'projectile',
            'mana' => 24, 'cooldown' => 3,
            'blurb' => 'Arrives, then arrives again for everything nearby.',
            'params' => ['damage' => 2.6, 'damage_per_level' => 0.4, 'speed' => 12, 'pierce' => 0, 'splash' => 3, 'count' => 1],
        ],
        'hunters_focus' => [
            'job' => 'ranger', 'name' => 'Hunter’s Focus', 'level' => 40, 'kind' => 'buff',
            'mana' => 26, 'cooldown' => 20,
            'blurb' => 'Nothing else in the room but the shot.',
            'params' => ['stat' => 'damage', 'amount' => 14, 'amount_per_level' => 4, 'duration' => 16, 'radius' => 0],
        ],

        // --- assassin ---
        'shadow_strike' => [
            'job' => 'assassin', 'name' => 'Shadow Strike', 'level' => 30, 'kind' => 'melee',
            'mana' => 16, 'cooldown' => 2.4,
            'blurb' => 'One target, once, conclusively.',
            'params' => ['damage' => 4.2, 'damage_per_level' => 0.55, 'radius' => 0],
        ],
        'fan_of_knives' => [
            'job' => 'assassin', 'name' => 'Fan of Knives', 'level' => 34, 'kind' => 'nova',
            'mana' => 20, 'cooldown' => 4.5,
            'blurb' => 'For when they have you surrounded, which they will.',
            'params' => ['damage' => 1.9, 'damage_per_level' => 0.26, 'radius' => 4],
        ],
        'vanish' => [
            'job' => 'assassin', 'name' => 'Vanish', 'level' => 40, 'kind' => 'buff',
            'mana' => 18, 'cooldown' => 14,
            'blurb' => 'Not here any more.',
            'params' => ['stat' => 'speed', 'amount' => 2.2, 'amount_per_level' => 0.4, 'duration' => 8, 'radius' => 0],
        ],

        // --- wizard ---
        'chain_lightning' => [
            'job' => 'wizard', 'name' => 'Chain Lightning', 'level' => 30, 'kind' => 'projectile',
            'mana' => 20, 'cooldown' => 1.4,
            'blurb' => 'Down the corridor and through everything in it.',
            'params' => ['damage' => 2.0, 'damage_per_level' => 0.28, 'speed' => 21, 'pierce' => 6, 'splash' => 0, 'count' => 1],
        ],
        'meteor' => [
            'job' => 'wizard', 'name' => 'Meteor', 'level' => 34, 'kind' => 'projectile',
            'mana' => 34, 'cooldown' => 5,
            'blurb' => 'Slow, and worth waiting for.',
            'params' => ['damage' => 3.4, 'damage_per_level' => 0.55, 'speed' => 7, 'pierce' => 0, 'splash' => 3.6, 'count' => 1],
        ],
        'blizzard' => [
            'job' => 'wizard', 'name' => 'Blizzard', 'level' => 40, 'kind' => 'nova',
            'mana' => 38, 'cooldown' => 8,
            'blurb' => 'The room, briefly, becomes weather.',
            'params' => ['damage' => 2.6, 'damage_per_level' => 0.35, 'radius' => 6],
        ],

        // --- bishop ---
        'mass_heal' => [
            'job' => 'bishop', 'name' => 'Mass Heal', 'level' => 30, 'kind' => 'heal',
            'mana' => 30, 'cooldown' => 8,
            'blurb' => 'The entire floor’s worth of party, mended at once.',
            'params' => ['amount' => 55, 'amount_per_level' => 16, 'radius' => 9],
        ],
        'holy_nova' => [
            'job' => 'bishop', 'name' => 'Holy Nova', 'level' => 34, 'kind' => 'nova',
            'mana' => 28, 'cooldown' => 5,
            'blurb' => 'A shove outward, from something older than you.',
            'params' => ['damage' => 2.2, 'damage_per_level' => 0.3, 'radius' => 5.5],
        ],
        'divine_shield' => [
            'job' => 'bishop', 'name' => 'Divine Shield', 'level' => 40, 'kind' => 'buff',
            'mana' => 36, 'cooldown' => 26,
            'blurb' => 'Everyone near you stops being quite so mortal.',
            'params' => ['stat' => 'armour', 'amount' => 20, 'amount_per_level' => 6, 'duration' => 14, 'radius' => 8],
        ],

        // --- warlock ---
        'raise_horde' => [
            'job' => 'warlock', 'name' => 'Raise Horde', 'level' => 30, 'kind' => 'summon',
            'mana' => 34, 'cooldown' => 12,
            'blurb' => 'Three of them, and they last.',
            'params' => ['minion' => 'skeleton', 'count' => 3, 'hp' => 70, 'hp_per_level' => 22, 'damage' => 14, 'damage_per_level' => 4, 'duration' => 60],
        ],
        'soul_harvest' => [
            'job' => 'warlock', 'name' => 'Soul Harvest', 'level' => 34, 'kind' => 'nova',
            'mana' => 26, 'cooldown' => 5,
            'blurb' => 'Takes something from everything standing near you.',
            'params' => ['damage' => 2.4, 'damage_per_level' => 0.32, 'radius' => 4.5],
        ],
        'dark_pact' => [
            'job' => 'warlock', 'name' => 'Dark Pact', 'level' => 40, 'kind' => 'heal',
            'mana' => 24, 'cooldown' => 10,
            'blurb' => 'The terms are unfavourable to somebody else.',
            'params' => ['amount' => 55, 'amount_per_level' => 16, 'radius' => 0],
        ],

        // --- archdruid ---
        'summon_pack' => [
            'job' => 'archdruid', 'name' => 'Summon Pack', 'level' => 30, 'kind' => 'summon',
            'mana' => 30, 'cooldown' => 12,
            'blurb' => 'The whole pack, and they get there first.',
            'params' => ['minion' => 'wolf', 'count' => 3, 'hp' => 58, 'hp_per_level' => 18, 'damage' => 12, 'damage_per_level' => 3.5, 'duration' => 55],
        ],
        'tempest' => [
            'job' => 'archdruid', 'name' => 'Tempest', 'level' => 34, 'kind' => 'nova',
            'mana' => 30, 'cooldown' => 6,
            'blurb' => 'You brought the weather indoors.',
            'params' => ['damage' => 2.3, 'damage_per_level' => 0.3, 'radius' => 5.5],
        ],
        'dire_bear' => [
            'job' => 'archdruid', 'name' => 'Dire Bear', 'level' => 40, 'kind' => 'buff',
            'mana' => 34, 'cooldown' => 26,
            'blurb' => 'Not briefly, and not a small one.',
            'params' => ['stat' => 'damage', 'amount' => 18, 'amount_per_level' => 5, 'duration' => 20, 'radius' => 0],
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        return self::CATALOGUE[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return isset(self::CATALOGUE[$id]);
    }

    /** Which tier a skill belongs to — its job's, since a skill is only ever one job's. */
    public static function tier(string $id): int
    {
        $skill = self::find($id);

        return $skill === null ? 1 : Jobs::tier($skill['job']);
    }

    /** Every skill belonging to one job, in the order they unlock. */
    public static function forJob(string $job): array
    {
        return array_filter(self::CATALOGUE, static fn ($skill) => $skill['job'] === $job);
    }

    /**
     * Every skill a hero of this job counts as their own — their whole line, not just where
     * they're standing. A wizard doesn't forget Firebolt.
     */
    public static function forLine(string $job): array
    {
        $line = Jobs::line($job);

        return array_filter(self::CATALOGUE, static fn ($skill) => in_array($skill['job'], $line, true));
    }

    /** Is this skill part of the hero's own line, or is learning it inheritance? */
    public static function isOwn(string $id, string $job): bool
    {
        $skill = self::find($id);

        return $skill !== null && in_array($skill['job'], Jobs::line($job), true);
    }

    /** The one skill a hero of this job is born knowing, so nobody arrives with no verbs. */
    public static function startingSkill(string $job): ?string
    {
        $lowest = null;
        $at = PHP_INT_MAX;

        foreach (self::CATALOGUE as $id => $skill) {
            if ($skill['job'] === $job && $skill['level'] < $at) {
                $lowest = $id;
                $at = $skill['level'];
            }
        }

        return $lowest;
    }

    /**
     * How many of a hero's skills were borrowed from outside their line, **at one tier**.
     *
     * Per tier rather than one pool, because that's what advancement is for: the three you
     * borrowed as a mage shouldn't quietly spend the three you're owed as a wizard. And
     * *distinct* skills, which is the whole meaning of a cap on breadth — borrowing Heal costs
     * you one of your three whether it sits at level 1 or level 10.
     *
     * @param  array<string, int>  $skills  id => level
     */
    public static function foreignCount(array $skills, string $job, int $tier): int
    {
        $foreign = 0;

        foreach (array_keys($skills) as $id) {
            $id = (string) $id;
            if (self::exists($id) && ! self::isOwn($id, $job) && self::tier($id) === $tier) {
                $foreign++;
            }
        }

        return $foreign;
    }

    /**
     * The catalogue as the client needs it: the same rows, with the id and tier folded in.
     *
     * Tier is derived from the job rather than stored on the skill — one fact, in {@see Jobs} —
     * but the client shouldn't have to do that join itself to group a skill screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payload(): array
    {
        $out = [];

        foreach (self::CATALOGUE as $id => $skill) {
            $out[] = ['id' => $id, 'tier' => Jobs::tier($skill['job'])] + $skill;
        }

        return $out;
    }
}
