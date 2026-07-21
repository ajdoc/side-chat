<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file arriving in pieces — the staging area that lets an attachment be bigger than one
 * request can carry.
 *
 * A browser sending a 300MB file in a single multipart POST is at the mercy of PHP's
 * `upload_max_filesize`, the web server's body limit, and any proxy in between; and a
 * connection that drops at 95% has to start again. So the client cuts the file up, posts the
 * pieces one at a time, and this row is what holds them together in the meantime: where the
 * bytes are being assembled, how many pieces are expected, how many have landed.
 *
 * It is deliberately *not* an attachment. A completed upload is only a file on disk with an
 * owner; it becomes an attachment when a message claims it ({@see \App\Services\
 * AttachmentService::attachUploads()}), and the row is dropped in the same breath. Anything
 * never claimed — a composer closed mid-upload, a send abandoned — is swept up by
 * `uploads:prune`, so the staging area can't quietly become a landfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->id();
            // The client's handle on the upload. A uuid rather than the row id, because it
            // travels back with the send request and shouldn't enumerate other people's.
            $table->uuid()->unique();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_type')->default('application/octet-stream');
            $table->string('extension')->nullable();
            // The size the client declared up front, checked against the bytes that arrive.
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->string('disk');
            $table->string('path');
            // Null until the last chunk lands. Only a completed upload can be claimed.
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            // The prune sweep walks these in age order.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunked_uploads');
    }
};
