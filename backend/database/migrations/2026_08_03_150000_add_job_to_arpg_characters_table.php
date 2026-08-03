<?php

use App\Support\Arpg\Jobs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a hero is along their line.
 *
 * `class` is who you've always been and never changes — a mage is a mage. `job` is what you are
 * *now*: mage at level 1, wizard from thirty. They're separate columns rather than one because
 * both are asked different questions. The class decides your attributes and is your identity in
 * the roster; the job decides which skills count as your own, and it moves.
 *
 * Existing heroes start where they've always been, at the head of their own line — nobody is
 * retroactively promoted, and the level-30 advancement is theirs to take when they get there.
 *
 * @see Jobs for the tree itself, and why it's a graph rather than a tier number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arpg_characters', function (Blueprint $table) {
            // Nullable only for the backfill below; every row has one by the end of this method.
            $table->string('job', 30)->nullable()->after('class');
        });

        DB::table('arpg_characters')->update(['job' => DB::raw('class')]);
    }

    public function down(): void
    {
        Schema::table('arpg_characters', function (Blueprint $table) {
            $table->dropColumn('job');
        });
    }
};
