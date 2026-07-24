<?php

use App\Models\Channel;
use App\Models\SideChat;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Side Desk document — a file (PDF, Word, Excel) uploaded to a surface's Docs app and
 * viewed there. Storage mirrors {@see \App\Models\Attachment}: bytes live on a private disk
 * and are served through short-lived signed URLs. Like every Side Desk entity, a document
 * hangs off exactly one surface — its `side_chat_id` *or* its `channel_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(SideChat::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Channel::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('name');            // original filename
            $table->string('mime_type');
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size'); // bytes
            $table->timestamps();

            $table->index(['side_chat_id', 'id']);
            $table->index(['channel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_documents');
    }
};
