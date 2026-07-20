<?php

use App\Models\Widget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an Open Canvas card *be* one of the interactive widgets we already have — a music
 * player, kanban board, poll, or a co-op game. A `widget` card points at a {@see Widget} via
 * this column; the widget itself stays channel-scoped and one-per-type as it always was (the
 * canvas card is just a placement of it), so its actions, auth and `WidgetUpdated` broadcast
 * are entirely unchanged. Note and todo cards leave this null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canvas_items', function (Blueprint $table) {
            $table->foreignIdFor(Widget::class)->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('canvas_items', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Widget::class);
        });
    }
};
