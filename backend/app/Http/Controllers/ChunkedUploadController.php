<?php

namespace App\Http\Controllers;

use App\Http\Requests\Upload\AppendChunkRequest;
use App\Http\Requests\Upload\StartChunkedUploadRequest;
use App\Models\ChunkedUpload;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Staging a large file in pieces, so an attachment isn't limited to what fits in one request.
 *
 * Three steps: open an upload (declaring name, size and how many pieces), post the pieces in
 * order, then hand the uuid to whatever wants the file — today, a message send, which turns it
 * into an attachment and clears the row. Nothing here decides *where* the file may go; that's
 * still the send request's business, exactly as it is for an ordinary upload.
 *
 * Chunks must arrive in order and are appended straight to the assembling file, so memory use
 * is one chunk regardless of how big the file is. An out-of-order chunk comes back 409 with the
 * index the server actually wants, which is what makes a dropped connection resumable: ask,
 * then carry on from there rather than re-sending everything.
 */
class ChunkedUploadController extends Controller
{
    public function store(StartChunkedUploadRequest $request): JsonResponse
    {
        $name = (string) $request->validated('name');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: null;

        $upload = ChunkedUpload::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'name' => $name,
            'mime_type' => $request->validated('mime_type') ?: 'application/octet-stream',
            'extension' => $extension,
            'size' => (int) $request->validated('size'),
            'total_chunks' => (int) $request->validated('total_chunks'),
            'disk' => AttachmentService::DISK,
            'path' => 'chunked-uploads/'.Str::random(40),
        ]);

        // Create the (empty) target now, so appending never has to care whether it exists.
        Storage::disk($upload->disk)->put($upload->path, '');

        return response()->json(['data' => $this->state($upload)], 201);
    }

    /**
     * Append one chunk. Returns the upload's state either way — including on the 409, so a
     * client that lost track (or raced itself) learns where to resume without a second call.
     */
    public function update(AppendChunkRequest $request, ChunkedUpload $upload): JsonResponse
    {
        if ($upload->isComplete()) {
            return response()->json(['data' => $this->state($upload)]);
        }

        $index = (int) $request->validated('index');
        if ($index !== $upload->received_chunks) {
            return response()->json([
                'message' => 'Out-of-order chunk.',
                'data' => $this->state($upload),
            ], 409);
        }

        $this->append($upload, $request->file('chunk')->getRealPath());

        // More bytes than were declared means the client is not sending the file it described.
        if (filesize($upload->absolutePath()) > $upload->size) {
            $upload->deleteFile();
            $upload->delete();

            return response()->json(['message' => 'This upload sent more data than it declared.'], 422);
        }

        $upload->increment('received_chunks');

        if ($upload->received_chunks >= $upload->total_chunks) {
            $upload->update(['completed_at' => now()]);
        }

        return response()->json(['data' => $this->state($upload)]);
    }

    /** Abandon an upload — the composer's remove button, or a page closing mid-transfer. */
    public function destroy(AppendChunkRequest $request, ChunkedUpload $upload): Response
    {
        $upload->deleteFile();
        $upload->delete();

        return response()->noContent();
    }

    /**
     * Stream one chunk onto the end of the file being assembled.
     *
     * Stream-to-stream rather than `file_get_contents`: the whole point of chunking is that
     * no single step holds a large file in memory, and reading a chunk into a string to write
     * it back out again would quietly undo half of that.
     */
    private function append(ChunkedUpload $upload, string $chunkPath): void
    {
        $in = fopen($chunkPath, 'rb');
        $out = fopen($upload->absolutePath(), 'ab');

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** What the client needs to drive the next step. @return array<string, mixed> */
    private function state(ChunkedUpload $upload): array
    {
        return [
            'id' => $upload->uuid,
            'received_chunks' => $upload->received_chunks,
            'total_chunks' => $upload->total_chunks,
            // The piece the server wants next — a resume point, not just a counter.
            'next_index' => $upload->received_chunks,
            'completed' => $upload->isComplete(),
        ];
    }
}
