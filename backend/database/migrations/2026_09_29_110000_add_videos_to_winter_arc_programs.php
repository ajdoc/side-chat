<?php

use App\Models\TrainingProgram;
use App\Support\Training\TrainingTemplates;
use Illuminate\Database\Migrations\Migration;

/**
 * The Ultimate Winter Arc template gained "how it's done" video links. Programs are copies, so
 * the ones already started get the new content here. Safe for ticks: only `videos` was added —
 * no block moved, so every `week:day:block` key still points at the same exercise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! TrainingTemplates::exists('ultimate-winter-arc')) return;
        $content = TrainingTemplates::load('ultimate-winter-arc');

        TrainingProgram::where('template', 'ultimate-winter-arc')->each(
            fn (TrainingProgram $p) => $p->update(['content' => $content]),
        );
    }

    public function down(): void {}
};
