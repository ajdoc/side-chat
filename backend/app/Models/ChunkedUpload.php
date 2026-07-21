<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One in-progress (or finished but unclaimed) chunked upload — see the migration for why the
 * staging area exists at all.
 *
 * Chunks are appended in order, so `received_chunks` doubles as "which piece I want next":
 * a client that lost its connection asks the server where it got to and carries on from
 * there, rather than re-sending 200MB. That's also the concurrency guard — a chunk arriving
 * out of order is refused rather than written to the wrong offset.
 */
class ChunkedUpload extends Model
{
    /**
     * A brand-new upload has received nothing.
     *
     * Not merely tidiness: the column's database default doesn't reach the model instance that
     * `create()` returns, so without this the first response would report a null `next_index` —
     * and the client would faithfully post `index=null` as its first chunk.
     */
    protected $attributes = ['received_chunks' => 0];

    protected $fillable = [
        'uuid', 'user_id', 'name', 'mime_type', 'extension',
        'size', 'total_chunks', 'received_chunks', 'disk', 'path', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** The largest file the chunked path will accept, in bytes. See config/uploads.php. */
    public static function maxBytes(): int
    {
        return (int) config('uploads.max_bytes');
    }

    /** The largest single chunk, in kilobytes — the client sends less; this is the ceiling. */
    public static function maxChunkKb(): int
    {
        return (int) config('uploads.chunk_kb');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** The absolute path of the file being assembled — chunks are appended to it directly. */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /** Drop the half-assembled (or finished but unclaimed) bytes. */
    public function deleteFile(): void
    {
        Storage::disk($this->disk)->delete($this->path);
    }
}
