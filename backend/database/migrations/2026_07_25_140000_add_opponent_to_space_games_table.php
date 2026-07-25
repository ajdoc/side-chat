<?php

use App\Models\User;
use App\Services\Games\GameHandler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a challenge was aimed at.
 *
 * Most games are put to the whole room and started by a vote. Some are a duel — one person
 * challenges *one* other, and it starts when that one accepts. This column is how the framework
 * tells the two apart: null for a room-wide game, set for a challenge, and the only person whose
 * vote can start a challenge. See {@see GameHandler::startMode}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_games', function (Blueprint $table) {
            $table->foreignIdFor(User::class, 'opponent_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('space_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opponent_id');
        });
    }
};
