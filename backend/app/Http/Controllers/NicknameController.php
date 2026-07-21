<?php

namespace App\Http\Controllers;

use App\Events\NicknameUpdated;
use App\Http\Requests\Nickname\IndexNicknameRequest;
use App\Http\Requests\Nickname\UpdateNicknameRequest;
use App\Models\Conversation;
use App\Models\Server;
use App\Models\User;
use App\Services\NicknameService;
use Illuminate\Http\JsonResponse;

/**
 * What people are called in one server, DM or group chat.
 *
 * Served as a map rather than stitched into every user the API returns. A name shows up in
 * dozens of payloads — messages, replies, rosters, voice tiles, read receipts, pinned-by
 * lines — and half of those are broadcasts, which have no single reader to resolve a
 * *private* alias for. So the client fetches the map once when it opens a place and does
 * the substitution itself, and every one of those payloads stays viewer-independent.
 *
 * ## Why four entry points for two endpoints
 *
 * Nothing below this line cares whether it's serving a server or a chat — the request
 * hands over a MessageContainer and that's the end of it. But route–model binding decides
 * what to substitute by reading the *action's* signature, so a `{server}` or
 * `{conversation}` segment that no method type-hints is left as a bare string, and the
 * request's container lookup then finds nothing and refuses everyone. The paired methods
 * exist to declare those parameters; each one immediately hands off to the shared half.
 */
class NicknameController extends Controller
{
    public function __construct(private readonly NicknameService $nicknames) {}

    public function indexForServer(IndexNicknameRequest $request, Server $server): JsonResponse
    {
        return $this->index($request);
    }

    public function indexForConversation(IndexNicknameRequest $request, Conversation $conversation): JsonResponse
    {
        return $this->index($request);
    }

    public function updateForServer(UpdateNicknameRequest $request, Server $server, User $member): JsonResponse
    {
        return $this->save($request, $member);
    }

    public function updateForConversation(UpdateNicknameRequest $request, Conversation $conversation, User $member): JsonResponse
    {
        return $this->save($request, $member);
    }

    /**
     * The names in force here, for whoever is asking.
     *
     * Two maps of `{ "<user id>": "name" }`, not one: `public` is what everyone here calls
     * these people and is the only thing safe to write into a message other people read,
     * `private` is the asker's own relabelling. The client lays the second over the first
     * for display and uses the first alone for @mentions. See NicknameService::mapsFor.
     */
    private function index(IndexNicknameRequest $request): JsonResponse
    {
        $maps = $this->nicknames->mapsFor($request->place(), $request->user());

        return response()->json([
            'data' => [
                'public' => (object) $maps['public'],
                'private' => (object) $maps['private'],
            ],
        ]);
    }

    /** Set or clear one naming — see UpdateNicknameRequest for who may set which. */
    private function save(UpdateNicknameRequest $request, User $member): JsonResponse
    {
        $place = $request->place();
        $isPublic = $request->isPublicScope();

        $saved = $this->nicknames->set(
            $place,
            $member,
            $isPublic ? null : $request->user(),
            $request->input('nickname'),
        );

        // Only a public change is everyone's business — see NicknameUpdated.
        if ($isPublic) {
            broadcast(new NicknameUpdated($place, $member->id, $saved?->nickname))->toOthers();
        }

        return response()->json([
            'data' => [
                'user_id' => $member->id,
                'scope' => $isPublic ? 'public' : 'private',
                'nickname' => $saved?->nickname,
            ],
        ]);
    }
}
