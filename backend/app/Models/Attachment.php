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

    /** Temporary signed URL that renders inline (images, PDFs). */
    public function url(): string
    {
        return URL::temporarySignedRoute('attachments.show', now()->addHours(6), ['attachment' => $this->id]);
    }

    /** Temporary signed URL that forces a download. */
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('attachments.download', now()->addHours(6), ['attachment' => $this->id]);
    }

    /** Removes the physical file from disk. */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
