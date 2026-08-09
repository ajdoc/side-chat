<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $fillable = ['message_id', 'disk', 'path', 'name', 'mime_type', 'extension', 'size', 'encrypted'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'encrypted' => 'boolean'];
    }

    /**
     * Are the bytes on disk ciphertext?
     *
     * Gates every question about *contents*. The stored MIME type of an encrypted attachment
     * is a placeholder, so asking "is this an image" of one would get a confident, wrong
     * answer — see {@see isImage()}.
     */
    public function isEncrypted(): bool
    {
        return (bool) $this->encrypted;
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /*
     * What kind of file this is — as far as the *server* can tell.
     *
     * All three answer false for an encrypted attachment, and that is the truthful answer
     * rather than a cautious one: the stored MIME type is a placeholder, so there is nothing
     * here to be right about. The client learns the real type from the sealed metadata in the
     * message envelope and renders accordingly, which is the only place it can be known.
     */

    public function isImage(): bool
    {
        return ! $this->isEncrypted() && Str::startsWith($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return ! $this->isEncrypted() && $this->mime_type === 'application/pdf';
    }

    public function isGif(): bool
    {
        return ! $this->isEncrypted() && $this->mime_type === 'image/gif';
    }

    /**
     * A "remote" attachment isn't a file we host — it's a reference to someone else's CDN
     * (a GIF picked from Giphy). `path` holds the full URL; there are no bytes on our disk.
     */
    public function isRemote(): bool
    {
        return $this->disk === 'remote';
    }

    /** Temporary signed URL that renders inline (images, PDFs) — or the CDN URL for remote refs. */
    public function url(): string
    {
        if ($this->isRemote()) {
            return $this->path;
        }

        return URL::temporarySignedRoute('attachments.show', now()->addHours(6), ['attachment' => $this->id]);
    }

    /** Temporary signed URL that forces a download — or the CDN URL for remote refs. */
    public function downloadUrl(): string
    {
        if ($this->isRemote()) {
            return $this->path;
        }

        return URL::temporarySignedRoute('attachments.download', now()->addHours(6), ['attachment' => $this->id]);
    }

    /** Removes the physical file from disk. A remote reference has no file to remove. */
    public function deleteFile(): void
    {
        if ($this->isRemote()) {
            return;
        }

        Storage::disk($this->disk)->delete($this->path);
    }
}
