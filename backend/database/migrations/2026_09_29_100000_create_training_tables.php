<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Training app — multi-week programs a channel follows, and each member's ticks.
 *
 * A program's weeks, days, phases and sessions are one JSON document (`content`), because it
 * is authored as one thing and only ever read whole — the client resolves "which session is
 * Week 5 Tuesday" from it (see lib/training.ts). Ticks are rows, because they are per person
 * and the team view counts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            // Which built-in template it was copied from, if any. A copy: editing the template
            // file later never rewrites a program people are halfway through.
            $table->string('template', 60)->nullable();
            // Monday of Week 1. Day dates are this + (week - 1) * 7 + the day's offset.
            $table->date('starts_on');
            $table->json('content');
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('training_programs')->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('week');
            $table->string('day', 16);
            $table->unsignedSmallInteger('block');
            $table->timestamp('created_at')->nullable();

            $table->unique(['program_id', 'user_id', 'week', 'day', 'block']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_ticks');
        Schema::dropIfExists('training_programs');
    }
};
