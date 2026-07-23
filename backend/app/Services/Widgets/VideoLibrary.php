<?php

namespace App\Services\Widgets;

use App\Models\Attachment;
use App\Models\ChunkedUpload;
use App\Models\Message;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The uploaded half of the video widget: turning a staged upload into a playable source,
 * and cleaning its bytes up again.
 *
 * {@see VideoWidget} is a state machine like every other handler and doesn't touch disks, so
 * the file work lives here — the same division {@see YouTubeResolver} has with MusicWidget,
 * which does the network work for it.
 *
 * Claimed clips land in `attachments/{channel_id}/`, deliberately sharing the folder ordinary
 * message attachments use. That's not laziness: {@see AttachmentService::purgeForChannels()}
 * deletes that whole directory when a channel or server goes, so a room's uploaded videos are
 * swept with everything else it owned instead of being stranded on disk by a path nobody
 * remembers. Individual removals are handled by {@see forget()}.
 *
 * Playback goes through a short-lived signed URL, exactly like an attachment: a <video> tag
 * can't send a bearer token, and the file has to stay off any public disk.
 */
final class VideoLibrary
{
    /** Videos larger than this are refused outright, whatever the upload ceiling allows. */
    private const MAX_BYTES = 2 * 1024 * 1024 * 1024;

    /**
     * Claim a completed chunked upload as a playable source.
     *
     * Ownership and completeness are re-checked here rather than trusted: the uuid arrived on
     * a request, so neither is a given. Returns null when the upload isn't this user's, isn't
     * finished, or isn't a video — the caller turns that into a note for the actor.
     *
     * @return array<string, mixed>|null the source fragment, ready for VideoWidget to seat
     */
    public function claim(string $uuid, User $user, int $channelId): ?array
    {
        $upload = ChunkedUpload::query()
            ->where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->first();

        if ($upload === null) {
            return null;
        }

        $disk = Storage::disk($upload->disk);

        // The bytes went missing (a prune sweep, a wiped disk) — drop the row rather than
        // leaving a source pointing at nothing.
        if (! $disk->exists($upload->path) || $upload->size > self::MAX_BYTES || ! $this->isVideo($upload)) {
            $upload->deleteFile();
            $upload->delete();

            return null;
        }

        $extension = $upload->extension ? ".{$upload->extension}" : '';
        $path = "attachments/{$channelId}/".Str::random(40).$extension;
        $disk->move($upload->path, $path);

        // Read everything off the staging row before dropping it, so the source below is built
        // from values rather than from a deleted model's attributes.
        $source = [
            'kind' => 'file',
            'key' => null,
            // Filled in per-viewer as a signed URL — see VideoWidget::forViewer().
            'url' => null,
            'embedUrl' => null,
            'provider' => 'upload',
            'title' => $upload->name,
            'author' => null,
            'duration' => null,
            'thumbnail' => null,
            // Where the bytes actually are. Never leaves the server.
            'disk' => $upload->disk,
            'path' => $path,
            'mime' => $upload->mime_type,
            'size' => $upload->size,
        ];

        $upload->delete();

        return $source;
    }

    /**
     * Add a video *already posted in this chat* to the playlist, by reference.
     *
     * Deliberately not a copy. The bytes are sitting on our disk under a message that owns
     * them, and duplicating a film-sized attachment to put it on a playlist would double the
     * storage for nothing. So the source stores only the attachment's id and borrows its URL
     * per viewer — which also means removing it from the playlist can't take the file out of
     * the conversation it was posted in (see {@see forget()}).
     *
     * The attachment must belong to *this channel* and be a video. That check is the whole
     * authorisation story: an id arrived on a request, and without it a member of one channel
     * could mint themselves a playable URL for a file in another.
     *
     * @return array<string, mixed>|null
     */
    public function claimAttachment(int $attachmentId, int $channelId): ?array
    {
        $attachment = Attachment::query()
            ->where('id', $attachmentId)
            ->whereIn('message_id', Message::where('channel_id', $channelId)->select('id'))
            ->where('disk', '!=', 'remote')
            ->with('message.user')
            ->first();

        if ($attachment === null || ! $this->isVideoFile($attachment->mime_type, $attachment->extension)) {
            return null;
        }

        return [
            'kind' => 'file',
            'key' => null,
            // Filled in per-viewer from the attachment's own signed URL — see VideoWidget::forViewer().
            'url' => null,
            'embedUrl' => null,
            'provider' => 'attachment',
            'title' => $attachment->name,
            'author' => $attachment->message?->user?->name,
            'duration' => null,
            'thumbnail' => null,
            // The reference. No disk/path: these bytes are the message's, not the playlist's.
            'attachmentId' => $attachment->id,
        ];
    }

    /**
     * The live URL for an attachment-backed source, or null if the attachment has since gone
     * (its message was deleted). The card greys out a source it can't get a URL for rather
     * than handing a <video> a link to nothing.
     */
    public function attachmentUrl(int $attachmentId): ?string
    {
        return Attachment::find($attachmentId)?->url();
    }

    /**
     * Delete an uploaded source's bytes. A no-op for anything we don't host.
     *
     * Note what this deliberately does *not* touch: a source that merely references an
     * {@see Attachment} has no `disk`/`path` of its own, so dropping it from the playlist
     * leaves the file exactly where it was posted. Removing a video from tonight's viewing
     * must never delete it out of the conversation.
     */
    public function forget(array $source): void
    {
        $disk = $source['disk'] ?? null;
        $path = $source['path'] ?? null;

        if (is_string($disk) && is_string($path) && $path !== '') {
            Storage::disk($disk)->delete($path);
        }
    }

    /** Delete the bytes of every uploaded source in a playlist — what `v!clear` leaves behind. */
    public function forgetAll(array $sources): void
    {
        foreach ($sources as $source) {
            if (is_array($source)) {
                $this->forget($source);
            }
        }
    }

    /** A short-lived signed URL a <video> can open directly (and range-request to seek). */
    public function signedUrl(int $widgetId, string $sourceId): string
    {
        return URL::temporarySignedRoute(
            'widget-videos.show',
            now()->addHours(6),
            ['widget' => $widgetId, 'source' => $sourceId],
        );
    }

    private function isVideo(ChunkedUpload $upload): bool
    {
        return $this->isVideoFile($upload->mime_type, $upload->extension);
    }

    /**
     * Is this actually a video?
     *
     * Browsers sniff content rather than trusting a declared type, so a mislabelled file
     * would simply fail to play — but an allowlist keeps the widget from becoming a general
     * file host that hands out playable URLs for anything at all. The extension is a fallback
     * for the browsers that post `application/octet-stream` for a .mkv, and it's the same pair
     * of tests {@see AttachmentService::videosForChannel()} filters the picker by, so nothing
     * can appear in that list and then be refused when it's chosen.
     */
    private function isVideoFile(?string $mimeType, ?string $extension): bool
    {
        if (Str::startsWith((string) $mimeType, 'video/')) {
            return true;
        }

        return in_array((string) $extension, ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'mkv'], true);
    }
}
