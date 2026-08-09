<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an attachment's bytes on disk are ciphertext.
 *
 * The flag exists so the server can be *honest* rather than clever. It cannot open the file,
 * cannot tell an image from a spreadsheet, and must not claim to: `is_image` on an encrypted
 * attachment would have the client render a broken picture, and a thumbnailer pointed at one
 * would produce garbage. Everything that inspects an attachment's contents asks this first.
 *
 * What is deliberately *not* stored: the real filename, the real MIME type, and the key. The
 * first two are written as neutral placeholders at upload time — a filename is often the most
 * revealing part of a document, and "Q3 redundancies.xlsx" in a plaintext column would undo
 * most of the point of encrypting the file. The real values are sealed inside the message
 * envelope alongside the key, so they arrive only for someone who can already read the
 * message. See the `f` field in the client's `envelope.ts`.
 *
 * `size` stays truthful, because it cannot be hidden — the bytes are on disk and AES-GCM
 * doesn't pad. It leaks roughly how big the file was, which is the residual metadata this
 * design accepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->boolean('encrypted')->default(false)->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};
