<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Attachment extends Model
{
    /** @use HasFactory<\Database\Factories\AttachmentFactory> */
    use HasFactory;

    protected $fillable = ['message_id', 'disk', 'path', 'name', 'mime_type', 'extension', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return Str::startsWith($this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isGif(): bool
    {
        return $this->mime_type === 'image/gif';
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
