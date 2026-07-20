<?php

namespace App\Http\Controllers;

use App\Events\SpaceDocumentAdded;
use App\Events\SpaceDocumentRemoved;
use App\Http\Requests\Document\DocumentRequest;
use App\Http\Requests\SideChat\ViewSideChatRequest;
use App\Http\Resources\AttachmentDocResource;
use App\Http\Resources\SpaceDocumentResource;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\SideChat;
use App\Models\SpaceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * A side chat's Docs app — the view-only document shelf beside the board, notes and canvas.
 * Listing is a channel-membership power (ViewSideChatRequest); uploading and deleting are
 * roster powers (DocumentRequest), exactly the line join draws for taking part. The channel's
 * own shelf is the near-identical {@see ChannelDocumentController}.
 */
class DocumentController extends Controller
{
    /** The shelf plus documents shared in this side chat's own timeline, newest first. */
    public function index(ViewSideChatRequest $request, SideChat $sideChat): JsonResponse
    {
        $shelf = $sideChat->spaceDocuments()->with('user')->latest()->get()
            ->map(fn (SpaceDocument $d) => (new SpaceDocumentResource($d))->resolve());

        $chat = $this->chatDocs($sideChat)
            ->map(fn (Attachment $a) => (new AttachmentDocResource($a))->resolve());

        $data = $shelf->concat($chat)->sortByDesc('created_at')->values();

        return response()->json(['data' => $data]);
    }

    /** Attachments that are office documents, shared in this side chat's timeline. */
    private function chatDocs(SideChat $sideChat): Collection
    {
        return Attachment::query()
            ->whereIn('message_id', Message::where('side_chat_id', $sideChat->id)->select('id'))
            ->whereIn('extension', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv'])
            ->with('message.user')
            ->orderByDesc('id')
            ->get();
    }

    public function store(DocumentRequest $request, SideChat $sideChat): SpaceDocumentResource
    {
        $file = $request->file('file');
        $path = $file->store("space-documents/sidechat/{$sideChat->id}", 'local');

        $document = $sideChat->spaceDocuments()->create([
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

    public function destroy(DocumentRequest $request, SideChat $sideChat, SpaceDocument $document): Response
    {
        abort_unless($document->side_chat_id === $sideChat->id, 404);

        $document->deleteFile();
        $document->delete();
        broadcast(new SpaceDocumentRemoved('sidechat.'.$sideChat->id, $document->id))->toOthers();

        return response()->noContent();
    }
}
