<?php

namespace App\Http\Controllers;

use App\Http\Requests\Upload\AppendChunkRequest;
use App\Http\Requests\Upload\OwnedUploadRequest;
use App\Http\Requests\Upload\StartChunkedUploadRequest;
use App\Models\ChunkedUpload;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Staging a large file, so an attachment isn't limited to what fits in one request.
 *
 * Three steps either way: open an upload (declaring name, size and how many pieces), get the
 * bytes up, then hand the uuid to whatever wants the file — today, a message send, which turns
 * it into an attachment and clears the row. Nothing here decides *where* the file may go; that's
 * still the send request's business, exactly as it is for an ordinary upload.
 *
 * How the middle step works depends on the disk, and the opening response says which it is so
 * the client doesn't have to guess:
 *
 *  - `direct` — the disk can sign an upload URL (any S3-compatible store), so the browser PUTs
 *    the whole file straight to the bucket and then calls {@see complete}. The bytes never enter
 *    this application, which is the point: no body limit, no request timeout, and nothing to lose
 *    if the container serving the API is recycled mid-transfer.
 *  - `chunked` — a local disk has no such URL, so the file arrives here in slices that are
 *    appended to the assembling file. Chunks must come in order and are streamed straight
 *    through, so memory use is one chunk regardless of file size. An out-of-order chunk comes
 *    back 409 with the index the server actually wants, which is what makes a dropped connection
 *    resumable: ask, then carry on from there rather than re-sending everything.
 *
 * The chunked path only works when every request lands on the same machine with the same disk,
 * which is why it is the local-disk branch and not the general one.
 */
class ChunkedUploadController extends Controller
{
    /** How long a signed upload URL stays valid — long enough for a big file on a slow line. */
    private const PRESIGN_TTL_MINUTES = 60;

    public function store(StartChunkedUploadRequest $request): JsonResponse
    {
        $name = (string) $request->validated('name');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: null;
        $disk = AttachmentService::disk();
        $direct = $this->signsUploads($disk);

        $upload = ChunkedUpload::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'name' => $name,
            'mime_type' => $request->validated('mime_type') ?: 'application/octet-stream',
            'extension' => $extension,
            'size' => (int) $request->validated('size'),
            // A direct upload is one PUT whatever the client planned to slice it into.
            'total_chunks' => $direct ? 1 : (int) $request->validated('total_chunks'),
            'disk' => $disk,
            'path' => 'chunked-uploads/'.Str::random(40),
        ]);

        if ($direct) {
            return response()->json(['data' => $this->state($upload) + $this->presign($upload)], 201);
        }

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
        // Appending needs a real file to seek to the end of. An upload staged on a bucket has
        // no such path; it was handed a signed URL instead and should be finishing that way.
        abort_if($this->signsUploads($upload->disk), 409, 'This upload takes a direct PUT, not chunks.');

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

    /**
     * Finish a direct upload: the client has PUT the file to the bucket and is telling us so.
     *
     * The signed URL was a licence to write one object, and nothing about it could constrain
     * what the client actually wrote — so what landed is checked here rather than trusted. An
     * object that never arrived, or that is bigger than the size the upload declared (and was
     * validated against the configured ceiling), is deleted along with its row: the alternative
     * is an attachment whose recorded size is a fiction, and a ceiling that means nothing.
     */
    public function complete(OwnedUploadRequest $request, ChunkedUpload $upload): JsonResponse
    {
        if ($upload->isComplete()) {
            return response()->json(['data' => $this->state($upload)]);
        }

        abort_unless($this->signsUploads($upload->disk), 409, 'This upload is assembled from chunks.');

        $disk = Storage::disk($upload->disk);

        if (! $disk->exists($upload->path)) {
            return response()->json(['message' => 'No file was uploaded.'], 422);
        }

        if ($disk->size($upload->path) > $upload->size) {
            $upload->deleteFile();
            $upload->delete();

            return response()->json(['message' => 'This upload sent more data than it declared.'], 422);
        }

        $upload->update(['received_chunks' => $upload->total_chunks, 'completed_at' => now()]);

        return response()->json(['data' => $this->state($upload)]);
    }

    /** Abandon an upload — the composer's remove button, or a page closing mid-transfer. */
    public function destroy(OwnedUploadRequest $request, ChunkedUpload $upload): Response
    {
        $upload->deleteFile();
        $upload->delete();

        return response()->noContent();
    }

    /** Whether a disk can hand out a signed URL the browser can upload straight to. */
    private function signsUploads(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /**
     * The signed PUT the browser should send the file to.
     *
     * The headers come back with it because they're part of what was signed — sending a
     * different `Content-Type` than the signature covers is rejected by the store, so the
     * client has to echo these rather than compose its own.
     *
     * @return array<string, mixed>
     */
    private function presign(ChunkedUpload $upload): array
    {
        ['url' => $url, 'headers' => $headers] = Storage::disk($upload->disk)->temporaryUploadUrl(
            $upload->path,
            now()->addMinutes(self::PRESIGN_TTL_MINUTES),
        );

        return ['url' => $url, 'headers' => $headers];
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
            // Which of the two transfer shapes above this upload is: 'direct' or 'chunked'.
            'mode' => $this->signsUploads($upload->disk) ? 'direct' : 'chunked',
            'received_chunks' => $upload->received_chunks,
            'total_chunks' => $upload->total_chunks,
            // The piece the server wants next — a resume point, not just a counter.
            'next_index' => $upload->received_chunks,
            'completed' => $upload->isComplete(),
        ];
    }
}
