<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Tracker project — the thing a tracker channel holds several of.
 *
 * Scoped to a channel like every other surface app's storage, so "the Design channel's tracker"
 * and "the Ops channel's tracker" are different trackers with no shared state.
 *
 * `key` is the human prefix on every task in the project (HRIP-1, HRIP-2). Stored uppercased and
 * unique per channel, because it's an identifier people type and say out loud — two projects
 * answering to the same prefix would make a task key ambiguous exactly where it's most used.
 *
 * `next_number` is the counter behind that suffix. Kept on the project rather than derived as
 * `max(number) + 1`, so a deleted task doesn't hand its number to the next one created: task
 * keys get quoted in chat and in commits, and a number that can be reused is a reference that
 * can silently come to mean something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('key', 10);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('archived')->default(false);
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel_id', 'key']);
            $table->index(['channel_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_projects');
    }
};
