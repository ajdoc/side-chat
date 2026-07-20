<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A file uploaded to a Side Space's Docs app — a PDF, Word or Excel document, hosted on a
 * private disk and served through short-lived signed URLs, exactly like {@see Attachment}.
 * Points at one surface (a side chat or a channel). See {@see \App\Http\Controllers\
 * DocumentController} / {@see \App\Http\Controllers\ChannelDocumentController}.
 */
class SpaceDocument extends Model
{
    protected $fillable = ['side_chat_id', 'channel_id', 'user_id', 'disk', 'path', 'name', 'mime_type', 'extension', 'size'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function sideChat(): BelongsTo
    {
        return $this->belongsTo(SideChat::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How the Docs app should preview this file. PDFs render in a native iframe; sheets and
     * Word docs go through their (dynamically-loaded) viewers; anything else is download-only.
     */
    public function kind(): string
    {
        return match (true) {
            $this->mime_type === 'application/pdf' => 'pdf',
            in_array($this->extension, ['xls', 'xlsx', 'csv'], true) => 'sheet',
            in_array($this->extension, ['doc', 'docx'], true) => 'word',
            default => 'other',
        };
    }

    /** Temporary signed URL that renders inline (PDF in an iframe, or fetched by a viewer). */
    public function url(): string
    {
        return URL::temporarySignedRoute('space-documents.show', now()->addHours(6), ['document' => $this->id]);
    }

    /** Temporary signed URL that forces a download. */
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('space-documents.download', now()->addHours(6), ['document' => $this->id]);
    }

    /** The surface's own broadcast stream — see {@see WhiteboardStroke::streamName()}. */
    public function streamName(): string
    {
        return $this->side_chat_id
            ? 'sidechat.'.$this->side_chat_id
            : 'channel.'.$this->channel_id;
    }

    /** Removes the physical file from disk. */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
