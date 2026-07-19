<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
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
