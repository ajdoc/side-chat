<?php

use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Message::class)->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('name');            // original filename
            $table->string('mime_type');
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size'); // bytes
            $table->timestamps();

            $table->index(['message_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
