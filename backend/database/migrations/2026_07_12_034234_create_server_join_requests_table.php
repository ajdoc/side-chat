<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->timestamps();

            // A user can only have one outstanding request per server.
            $table->unique(['server_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_join_requests');
    }
};
