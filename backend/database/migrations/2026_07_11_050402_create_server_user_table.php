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
        Schema::create('server_user', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Server::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner | member
            $table->timestamps();

            $table->unique(['server_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_user');
    }
};
