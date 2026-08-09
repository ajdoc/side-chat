<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A row in `channel_reads` no longer implies a read.
 *
 * The table grew past its name some time ago — `default_child_id` was already a preference
 * rather than a read marker — and notification settings finish the job: muting a channel
 * you have never opened creates a row for somebody who has read nothing in it. `read_at`
 * had to either become nullable or be filled with a lie about when they last read.
 *
 * Nothing downstream is fooled by the null: "seen by" selects on `last_read_message_id`,
 * not on this column, so a settings-only row can't turn up as a read receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable(false)->change();
        });
    }
};
