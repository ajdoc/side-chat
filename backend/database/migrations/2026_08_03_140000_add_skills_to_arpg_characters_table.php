<?php

use App\Support\Arpg\Skills;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Skills, the points that buy them — and eight classes where there were three.
 *
 * The original roster was Diablo 1's (warrior, rogue, sorcerer), and a class was only a starting
 * stat block: three of those is honest, eight would have been the same three wearing different
 * hats. Now that a class owns a *skill tree* there's something for eight of them to differ by, so
 * the three are remapped onto their obvious successors rather than left as orphans — a warrior
 * has always been a swordsman, and every existing hero keeps their level, gold and bag.
 *
 * `skills` is `{id: level}` and deliberately JSON rather than a join table: a learned skill has no
 * identity of its own, is only ever read as a whole with its character, and its *meaning* lives in
 * {@see Skills} where the numbers can be tuned without a migration. Same reasoning as the
 * inventory column beside it.
 */
return new class extends Migration
{
    /** What the old three become. */
    private const REMAP = [
        'warrior' => 'swordsman',
        'rogue' => 'thief',
        'sorcerer' => 'mage',
    ];

    public function up(): void
    {
        Schema::table('arpg_characters', function (Blueprint $table) {
            // {skillId: level}. An empty object is a hero who has spent nothing yet.
            $table->json('skills')->default('{}');
            // Unspent skill points. Attribute points live in `stats.unspent` — they're a
            // different currency and buying one with the other would collapse both choices.
            $table->unsignedInteger('skill_points')->default(0);
        });

        foreach (self::REMAP as $was => $now) {
            DB::table('arpg_characters')->where('class', $was)->update(['class' => $now]);
        }

        // Nobody is left with no way to attack: every hero gets their class's opening skill, and
        // a point for each level they'd already earned to spend on the rest.
        foreach (DB::table('arpg_characters')->get(['id', 'class', 'level']) as $character) {
            $starting = Skills::startingSkill($character->class);

            DB::table('arpg_characters')->where('id', $character->id)->update([
                'skills' => json_encode($starting ? [$starting => 1] : []),
                'skill_points' => max(0, $character->level - 1),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::REMAP as $was => $now) {
            DB::table('arpg_characters')->where('class', $now)->update(['class' => $was]);
        }

        Schema::table('arpg_characters', function (Blueprint $table) {
            $table->dropColumn(['skills', 'skill_points']);
        });
    }
};
