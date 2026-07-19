<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\DeleteAttachmentAction;
use App\Http\Requests\Attachment\DeleteAttachmentRequest;
use App\Http\Requests\Attachment\IndexChannelAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\MessageResource;
use App\Models\Attachment;
use App\Models\Channel;
use App\Services\AttachmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    /** Inline (images render in <img>, PDFs open in the browser). Signed URL. */
    public function show(Attachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($attachment->name).'"',
        ]);
    }

    /** Forced download. Signed URL. */
    public function download(Attachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->name);
    }

    /** Files posted in a channel (Info > Files). */
    public function indexForChannel(IndexChannelAttachmentRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return AttachmentResource::collection($this->attachments->forChannel($channel));
    }

    /** GIFs posted in a channel — picked or uploaded (Info > GIFs). */
    public function indexForChannelGifs(IndexChannelAttachmentRequest $request, Channel $channel): AnonymousResourceCollection
    {
        return AttachmentResource::collection($this->attachments->gifsForChannel($channel));
    }

    /** Delete one attachment (and its file). */
    public function destroy(DeleteAttachmentRequest $request, Attachment $attachment, DeleteAttachmentAction $action): MessageResource
    {
        return new MessageResource($action->handle($attachment));
    }
}
