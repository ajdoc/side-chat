<?php

use App\Models\LinkPreview;
use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per distinct URL, shared by every message that links to it — so a
        // link doing the rounds in a channel is fetched once, not once per message.
        Schema::create('link_previews', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique(); // sha256(url) — a URL is too long to index directly
            $table->text('url');
            $table->string('status', 16)->default('pending'); // pending | ok | failed
            $table->string('kind', 16)->default('link');      // link (og card) | image (direct image url)
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('site_name')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('link_preview_message', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Message::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(LinkPreview::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0); // order the links appeared in the body

            $table->unique(['message_id', 'link_preview_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_preview_message');
        Schema::dropIfExists('link_previews');
    }
};
