<?php

namespace App\Services;

use App\Http\Controllers\ChunkedUploadController;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\ChunkedUpload;
use App\Models\Message;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AttachmentService
{
    public const DISK = 'local';

    public function __construct(private readonly GifService $gifs) {}

    /**
     * Store uploaded files against a message.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function storeFor(Message $message, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store("attachments/{$message->channel_id}", self::DISK);

            $message->attachments()->create([
                'disk' => self::DISK,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'extension' => strtolower($file->getClientOriginalExtension()) ?: null,
                'size' => $file->getSize(),
            ]);
        }
    }

    /**
     * Claim files staged by {@see ChunkedUploadController} — the large-file
     * path — as attachments on a message.
     *
     * The bytes are already on our disk under the uploader's name, so this *moves* them into the
     * channel's folder rather than copying: a 200MB copy would double both the time and the disk
     * for no reason, and the staging row is finished with either way. Only completed uploads
     * belonging to this user are eligible — the ids came in on a request, so neither is assumed —
     * and they're attached in the order the client asked for, not whatever order the database
     * returns, so a batch of files keeps the order it was picked in.
     *
     * @param  array<int, string>  $uuids
     */
    public function attachUploads(Message $message, array $uuids, User $user): void
    {
        if ($uuids === []) {
            return;
        }

        $uploads = ChunkedUpload::query()
            ->whereIn('uuid', $uuids)
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->get()
            ->sortBy(fn (ChunkedUpload $u) => array_search($u->uuid, $uuids, true));

        foreach ($uploads as $upload) {
            $disk = Storage::disk($upload->disk);

            // The bytes went missing (a prune sweep, a wiped disk): drop the row and move on
            // rather than leaving a row pointing at nothing.
            if (! $disk->exists($upload->path)) {
                $upload->delete();

                continue;
            }

            $extension = $upload->extension ? ".{$upload->extension}" : '';
            $path = "attachments/{$message->channel_id}/".Str::random(40).$extension;
            $disk->move($upload->path, $path);

            $message->attachments()->create([
                'disk' => $upload->disk,
                'path' => $path,
                'name' => $upload->name,
                'mime_type' => $upload->mime_type,
                'extension' => $upload->extension,
                'size' => $upload->size,
            ]);

            $upload->delete();
        }
    }

    /**
     * Record a GIF picked from a provider (Giphy, Klipy) as a *remote* attachment — a
     * reference to their CDN, not a file we host. `path` is the media URL; {@see Attachment::url()}
     * returns it verbatim and {@see Attachment::deleteFile()} is a no-op for it.
     *
     * The URL is user-supplied (it rode in on the send request), so its host is checked
     * against the configured providers' allowlist before we ever store or render it.
     *
     * @param  array{url: string, title?: string|null, width?: int|null, height?: int|null}  $gif
     */
    public function storeGif(Message $message, array $gif): void
    {
        $url = $gif['url'] ?? null;

        if (! is_string($url) || ! $this->isAllowedGifHost($url)) {
            return;
        }

        $message->attachments()->create([
            'disk' => 'remote',
            'path' => $url,
            'name' => (isset($gif['title']) && $gif['title'] !== '') ? $gif['title'] : 'gif',
            'mime_type' => 'image/gif',
            'extension' => 'gif',
            'size' => 0,
        ]);
    }

    /**
     * Copy a message's attachments onto another message — what "forward" does with them.
     *
     * A remote reference (a GIF) is just a row pointing at someone else's CDN, so it's
     * cloned by copying the row. A hosted file has bytes on our disk that belong to the
     * source channel's folder; the copy lands in the *target* channel's folder so it's
     * purged with that channel, and gets its own row pointing at the new path. If the copy
     * can't be made (the source file has gone missing), that one attachment is skipped
     * rather than failing the whole forward.
     *
     * @param  Collection<int, Attachment>  $sources
     */
    public function cloneForMessage(Message $target, Collection $sources): void
    {
        foreach ($sources as $source) {
            if ($source->isRemote()) {
                $target->attachments()->create($source->only(['disk', 'path', 'name', 'mime_type', 'extension', 'size']));

                continue;
            }

            $disk = Storage::disk($source->disk);
            if (! $disk->exists($source->path)) {
                continue; // original bytes are gone — nothing to copy
            }

            $extension = $source->extension ? ".{$source->extension}" : '';
            $newPath = "attachments/{$target->channel_id}/".Str::random(40).$extension;
            $disk->copy($source->path, $newPath);

            $target->attachments()->create([
                'disk' => $source->disk,
                'path' => $newPath,
                'name' => $source->name,
                'mime_type' => $source->mime_type,
                'extension' => $source->extension,
                'size' => $source->size,
            ]);
        }
    }

    /**
     * Attach a *copy* of an already-stored file to a message — the bytes come from somewhere
     * other than an upload (a Side Space document being shared into chat). Mirrors the hosted
     * branch of {@see cloneForMessage}: the copy lands in the target channel's folder so it's
     * purged with that channel, and gets its own row. Returns the new attachment.
     */
    public function attachStoredFile(Message $target, string $disk, string $path, string $name, string $mimeType, ?string $extension, int $size): Attachment
    {
        $ext = $extension ? ".{$extension}" : '';
        $newPath = "attachments/{$target->channel_id}/".Str::random(40).$ext;
        Storage::disk($disk)->copy($path, $newPath);

        return $target->attachments()->create([
            'disk' => $disk,
            'path' => $newPath,
            'name' => $name,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
        ]);
    }

    /** Whether a GIF media URL points at a host one of the configured providers serves. */
    private function isAllowedGifHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $host = strtolower($host);

        foreach ($this->gifs->allowedHosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete the physical files *and* the rows.
     *
     * Done explicitly because a DB-level cascade never fires Eloquent events, which
     * would leave orphaned files on disk.
     *
     * @param  Collection<int, Attachment>  $attachments
     */
    public function purge(Collection $attachments): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        $attachments->each(fn (Attachment $a) => $a->deleteFile());

        Attachment::whereIn('id', $attachments->pluck('id'))->delete();
    }

    /** Purge every attachment belonging to the given messages. */
    public function purgeForMessages(array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        $this->purge(Attachment::whereIn('message_id', $messageIds)->get());
    }

    /**
     * Purge every file belonging to the given channels — rows *and* bytes.
     *
     * This is what "deleting a channel (or a server) deletes its files" actually means.
     * Dropping the channel row cascades messages and attachment rows away at the FK
     * level, but a DB cascade never fires an Eloquent event, so nothing would ever call
     * deleteFile() and every upload would be stranded on disk with no row left pointing
     * at it. So the files have to go first, while we can still see them.
     *
     * Two passes, because neither alone is sufficient:
     *
     *  1. Row by row, honouring each attachment's own `disk`. Chunked, because a busy
     *     channel's uploads are not something to hold in memory all at once.
     *  2. Then the channel's upload *directory*, which is where storeFor() puts every
     *     file for a channel (`attachments/{channel_id}`, threads included — a thread
     *     reply carries its channel_id). This removes the now-empty folder, and sweeps
     *     any file that had already lost its row.
     *
     * @param  array<int, int>  $channelIds
     */
    public function purgeForChannels(array $channelIds): void
    {
        if ($channelIds === []) {
            return;
        }

        Attachment::query()
            ->whereIn('message_id', Message::whereIn('channel_id', $channelIds)->select('id'))
            ->chunkById(500, fn (Collection $chunk) => $this->purge($chunk));

        foreach ($channelIds as $channelId) {
            Storage::disk(self::DISK)->deleteDirectory("attachments/{$channelId}");
        }
    }

    /** Every file posted in a channel (main timeline + threads) - the Info > Files tab. */
    public function forChannel(Channel $channel): LengthAwarePaginator
    {
        return Attachment::query()
            ->whereIn('message_id', Message::where('channel_id', $channel->id)->select('id'))
            ->with(['message.user'])
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * Every *video* file posted anywhere in a channel — what the video widget's "in this chat"
     * picker browses, so a clip someone already dropped in the conversation can be played
     * without re-uploading it.
     *
     * One `channel_id` filter is the whole scope, and that isn't a simplification: a message
     * carries the channel it lives in whether it's on the main timeline, inside a thread, or
     * inside a side chat (the column is NOT NULL — see the side-chat migration). So this
     * reaches every surface a channel contains, which is exactly what the picker offers.
     *
     * Matched on the declared MIME type *or* the extension, because browsers post `.mkv` and
     * the odd `.mov` as `application/octet-stream` — going on MIME alone would hide files that
     * play perfectly well. Remote references (picked GIFs) are excluded: there are no bytes of
     * ours behind them and none of them are video anyway.
     *
     * @return Collection<int, Attachment>
     */
    public function videosForChannel(Channel $channel, ?string $query = null): Collection
    {
        return Attachment::query()
            ->whereIn('message_id', Message::where('channel_id', $channel->id)->select('id'))
            ->where('disk', '!=', 'remote')
            ->where(fn ($q) => $q
                ->where('mime_type', 'like', 'video/%')
                ->orWhereIn('extension', ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'mkv']))
            ->when(
                is_string($query) && trim($query) !== '',
                fn ($q) => $q->where('name', 'ilike', '%'.trim($query).'%'),
            )
            ->with(['message.user'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /** Every GIF posted in a channel (main timeline + threads) - the Info > GIFs tab. */
    public function gifsForChannel(Channel $channel): LengthAwarePaginator
    {
        return Attachment::query()
            ->whereIn('message_id', Message::where('channel_id', $channel->id)->select('id'))
            ->where('mime_type', 'image/gif')
            ->with(['message.user'])
            ->orderByDesc('id')
            ->paginate(50);
    }

    /** Purge only the given attachment ids that belong to this message. */
    public function purgeByIds(Message $message, array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }

        $this->purge(
            Attachment::where('message_id', $message->id)->whereIn('id', $attachmentIds)->get()
        );
    }
}
