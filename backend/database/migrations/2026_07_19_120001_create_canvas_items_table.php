<?php

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Open Canvas — a Side Desk's free 2D surface of cards (a markdown note, a checklist).
 * Like the whiteboard, a card hangs off exactly one surface: its `side_chat_id` *or* its
 * `channel_id`. Geometry (`x`,`y`,`w`,`h`) is the canvas's logical space, and `z` is stack
 * order. `content` is a free-form JSON blob whose shape belongs to the card `kind`, the same
 * way a whiteboard stroke's payload does. See {@see \App\Models\CanvasItem}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canvas_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->json('content');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->integer('w')->default(240);
            $table->integer('h')->default(180);
            $table->integer('z')->default(0);
            $table->timestamps();

            $table->index(['side_chat_id', 'z']);
            $table->index(['channel_id', 'z']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canvas_items');
    }
};
