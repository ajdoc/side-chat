<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Message::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            // One user reacts with a given emoji at most once — the toggle relies on this.
            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index(['message_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
