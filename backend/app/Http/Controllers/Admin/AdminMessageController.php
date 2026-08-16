<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Message\DeleteMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminMessageResource;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The audit view: read any timeline on the instance, and take a message down.
 *
 * This is the screen the other three feed into. A report names a person or a room, and the
 * questions that follow are always the same two — what did they say, and where — so the
 * filters are author, channel, conversation, free text and a date range, and they compose.
 *
 * Two deliberate limits:
 *
 * - **Encrypted messages stay unreadable.** The server holds ciphertext for any timeline
 *   with E2EE switched on and no key to open it. They still appear in the list, flagged, so
 *   a moderator can see that a conversation happened and how much of it — they just can't
 *   read it. This is the feature working, not a gap to close.
 * - **Never a firehose.** There's no unfiltered "all messages" list; the endpoint is
 *   paginated and newest-first, and it exists to answer a question somebody already has.
 *
 * Deleting goes through DeleteMessageAction, the same one the author's own delete uses, so
 * the removal broadcasts and the attachments go with it.
 */
class AdminMessageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'conversation_id' => ['sometimes', 'nullable', 'integer'],
            'server_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $messages = Message::query()
            ->with(['user:id,name,email,avatar,is_bot', 'channel:id,name,type,server_id,conversation_id', 'channel.server:id,name'])
            ->withCount('attachments')
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['channel_id'] ?? null, fn ($q, $id) => $q->where('channel_id', $id))
            ->when(
                $filters['conversation_id'] ?? null,
                fn ($q, $id) => $q->whereHas('channel', fn ($c) => $c->where('conversation_id', $id)),
            )
            ->when(
                $filters['server_id'] ?? null,
                fn ($q, $id) => $q->whereHas('channel', fn ($c) => $c->where('server_id', $id)),
            )
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', $date))
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                // Cleartext only. Matching against ciphertext would return nothing anyway,
                // and excluding it keeps "no results" honest rather than ambiguous.
                $query->where('encrypted', false)->where('body', 'ilike', $like);
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return AdminMessageResource::collection($messages);
    }

    /** Take a message down. Same action as the author's own delete, so it broadcasts. */
    public function destroy(Message $message, DeleteMessageAction $action): Response
    {
        $action->handle($message);

        return response()->noContent();
    }
}
