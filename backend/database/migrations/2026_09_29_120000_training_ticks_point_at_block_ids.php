<?php

use App\Models\TrainingProgram;
use App\Support\Training\TrainingContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticks point at a block's *id* instead of its position, so deleting or reordering exercises
 * never moves anyone's ticks onto a different one.
 *
 * No tick is rewritten: every existing block is given its current position as its id ("0",
 * "1", …), which is what the ticks already say. See TrainingContent::blockId.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE training_ticks ALTER COLUMN block TYPE varchar(40) USING block::varchar');
        } else {
            Schema::table('training_ticks', fn (Blueprint $t) => $t->string('block', 40)->change());
        }

        TrainingProgram::query()->each(
            fn (TrainingProgram $p) => $p->update(['content' => TrainingContent::normalise($p->content)]),
        );
    }

    public function down(): void {}
};
