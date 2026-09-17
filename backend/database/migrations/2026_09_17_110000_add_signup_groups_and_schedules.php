<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-ups, round two: groups a channel names itself, and recurring schedules.
 *
 * ## Groups replace the fixed categories
 *
 * Training / Bootcamp / Leagues / Other was one team's list. A group is now a row, so a channel
 * can have "U12 Training" and "Summer Camp" instead. Deleting a group ungroups its sheets
 * rather than deleting them — a sheet has names and paid charges on it.
 *
 * ## Schedules
 *
 * A schedule is a sheet template plus a rule: which weekdays, and which occurrences of them in
 * the month (null = every week; [2, 4] = the 2nd and 4th; -1 = the last). Generating a month
 * makes one sheet per matching date. `(series_id, event_date)` is unique, which is what makes
 * generating the same month twice harmless.
 *
 * A generated sheet is a copy: editing the schedule afterwards changes future months only.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'training' => ['Training', 'blue'],
        'bootcamp' => ['Bootcamp', 'orange'],
        'league' => ['Leagues & Tournaments', 'purple'],
        'other' => ['Other events', 'slate'],
    ];

    public function up(): void
    {
        Schema::create('signup_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('slate');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['channel_id', 'position']);
        });

        Schema::create('signup_series', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Channel::class)->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('signup_groups')->nullOnDelete();
            $table->string('title', 120);
            $table->json('header');
            $table->json('columns');
            $table->json('fees');
            $table->unsignedSmallInteger('slots')->default(15);
            $table->json('weekdays');          // 0 = Sunday … 6 = Saturday
            $table->json('weeks')->nullable(); // null = every week; else 1–4 and/or -1 (last)
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('signup_sheets', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('title')->constrained('signup_groups')->nullOnDelete();
            $table->foreignId('series_id')->nullable()->after('group_id')->constrained('signup_series')->nullOnDelete();
            $table->unique(['series_id', 'event_date']);
        });

        // Existing sheets keep their category as a group of the same name.
        $channels = DB::table('signup_sheets')->distinct()->pluck('channel_id');
        foreach ($channels as $channelId) {
            $position = 0;
            foreach (self::DEFAULTS as $category => [$name, $color]) {
                $groupId = DB::table('signup_groups')->insertGetId([
                    'channel_id' => $channelId, 'name' => $name, 'color' => $color,
                    'position' => $position++, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('signup_sheets')
                    ->where('channel_id', $channelId)->where('category', $category)
                    ->update(['group_id' => $groupId]);
            }
        }

        Schema::table('signup_sheets', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'year', 'category']);
            $table->dropColumn('category');
            $table->index(['channel_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::table('signup_sheets', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'event_date']);
            $table->string('category', 24)->default('training');
            $table->index(['channel_id', 'year', 'category']);
            $table->dropUnique(['series_id', 'event_date']);
            $table->dropConstrainedForeignId('series_id');
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::dropIfExists('signup_series');
        Schema::dropIfExists('signup_groups');
    }
};
