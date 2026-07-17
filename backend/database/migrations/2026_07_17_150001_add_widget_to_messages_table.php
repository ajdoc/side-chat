<?php

use App\Models\Widget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message can *be* a widget's card. When `type` is 'widget' this points at the widget
 * it renders (see the create_widgets migration). Nullable and null-on-delete: a normal
 * message has no widget, and if a widget is ever removed its stray cards simply stop
 * being widgets rather than vanishing mid-timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignIdFor(Widget::class)->nullable()->after('side_chat_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Widget::class);
        });
    }
};
