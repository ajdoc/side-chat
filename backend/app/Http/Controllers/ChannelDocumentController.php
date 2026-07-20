<?php

namespace App\Http\Controllers;

use App\Actions\Document\ShareSpaceDocumentAction;
use App\Events\SpaceDocumentAdded;
use App\Events\SpaceDocumentRemoved;
use App\Http\Requests\Document\StoreChannelDocumentRequest;
use App\Http\Requests\Space\ChannelSpaceRequest;
use App\Http\Resources\AttachmentDocResource;
use App\Http\Resources\SpaceDocumentResource;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\SpaceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * A channel's (or DM's) Docs app — the same view-only document shelf a side chat has
 * ({@see DocumentController}), hanging off a plain channel. Membership is the whole gate for
 * both listing and uploading. Files land on a private disk and are served through the signed
 * routes in web.php (see {@see SpaceDocumentFileController}).
 */
class ChannelDocumentController extends Controller
{
    /**
     * The Docs shelf, plus the channel's chat-shared documents merged in — one list, newest
     * first, each item tagged `source` ('shelf' | 'chat') so the client knows which actions
     * it may offer. This is the "files in chat are already in Docs" half of the two-way link;
     * the other half (shelf uploads appearing in Info → Files) filters this by `source`.
     */
    public function index(ChannelSpaceRequest $request, Channel $channel): JsonResponse
    {
        $shelf = $channel->spaceDocuments()->with('user')->latest()->get()
            ->map(fn (SpaceDocument $d) => (new SpaceDocumentResource($d))->resolve());

        $chat = $this->chatDocs($channel)
            ->map(fn (Attachment $a) => (new AttachmentDocResource($a))->resolve());

        $data = $shelf->concat($chat)->sortByDesc('created_at')->values();

        return response()->json(['data' => $data]);
    }

    /** The channel's attachments that are office documents — the same pool Info → Files draws. */
    private function chatDocs(Channel $channel): Collection
    {
        return Attachment::query()
            ->whereIn('message_id', Message::where('channel_id', $channel->id)->select('id'))
            ->whereIn('extension', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'])
            ->with('message.user')
            ->orderByDesc('id')
            ->get();
    }

    public function store(StoreChannelDocumentRequest $request, Channel $channel): SpaceDocumentResource
    {
        $file = $request->file('file');
        $path = $file->store("space-documents/channel/{$channel->id}", 'local');

        $document = $channel->spaceDocuments()->create([
            'user_id' => $request->user()->id,
            'disk' => 'local',
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'extension' => strtolower($file->getClientOriginalExtension()) ?: null,
            'size' => $file->getSize(),
        ]);

        broadcast(new SpaceDocumentAdded($document))->toOthers();

        return new SpaceDocumentResource($document->load('user'));
    }

    public function destroy(ChannelSpaceRequest $request, Channel $channel, SpaceDocument $document): Response
    {
        abort_unless($document->channel_id === $channel->id, 404);

        $document->deleteFile();
        $document->delete();
        broadcast(new SpaceDocumentRemoved('channel.'.$channel->id, $document->id))->toOthers();

        return response()->noContent();
    }

    /** Share a shelf document into the channel timeline (the "Send to chat" action). */
    public function sendToChat(ChannelSpaceRequest $request, Channel $channel, SpaceDocument $document, ShareSpaceDocumentAction $action): Response
    {
        abort_unless($document->channel_id === $channel->id, 404);

        $action->handle($channel, $request->user(), $document);

        return response()->noContent();
    }
}
