<?php

use App\Models\TrackerProject;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task in a Tracker project.
 *
 * `number` is the project-local counter that makes the key (HRIP-**2**); it is handed out by
 * the project's `next_number` under a row lock, so two people creating a task at once can't
 * both be given the same one. Unique per project to make that guarantee the database's rather
 * than the application's.
 *
 * `status` and `priority` are short strings from a closed set ({@see \App\Support\Tracker\TrackerFields})
 * rather than enums in the schema: the sets are drawn by the client, which maps them to
 * colours and icons, and adding a column type migration every time a status is added would be
 * the wrong kind of friction. Validation still refuses anything outside the set.
 *
 * `position` orders tasks inside their status group, so a drag within a column persists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TrackerProject::class, 'project_id')->constrained('tracker_projects')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->string('priority', 12)->default('mid');
            $table->foreignIdFor(User::class, 'assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            // A date, not a timestamp: "due Aug 11" means the same day everywhere, and storing a
            // moment would drag it across the date line for somebody.
            $table->date('due_date')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            // The board query: this project's tasks, grouped by status, in order.
            $table->index(['project_id', 'status', 'position']);
            // "Your tasks" on the tracker's home, across every project in the channel.
            $table->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_tasks');
    }
};
