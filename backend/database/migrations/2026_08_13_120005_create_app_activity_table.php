<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has happened to a work item — "magictae created this issue", "moved to In Review".
 *
 * Polymorphic like the comments beside it, and for the same reason: the story of an item is
 * not a tracker-only idea.
 *
 * Rows are append-only and never edited. `kind` says what happened and `data` carries the
 * before/after for whatever changed, which keeps a new kind of tracked change to a constant
 * and a line in the client's renderer rather than a migration.
 *
 * Only `created_at` is kept — an entry that could be updated wouldn't be a history. It is
 * written by the same action that makes the change, inside its transaction, so an activity
 * feed can't drift from the thing it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_activity', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at'], 'app_activity_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_activity');
    }
};
