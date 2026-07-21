<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A revision counter for the shared note, so two people editing at once stop overwriting each
 * other. A save carries the version it was based on; the update only lands if the note is
 * still at that version, and otherwise comes back as a conflict for the client to merge (see
 * {@see \App\Models\SpaceNote::applyEdit()}). Existing notes start at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_notes', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('space_notes', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
