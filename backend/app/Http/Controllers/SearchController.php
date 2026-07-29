<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\SearchRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\SearchMessageResource;
use App\Http\Resources\SearchSurfaceResource;
use App\Http\Resources\ServerResource;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * One endpoint for every search in the app.
 *
 * Five endpoints was the obvious alternative and it's the wrong shape: the command palette
 * wants all of them at once, and five requests fired at every keystroke is five chances to
 * render results from a query the user has already finished retyping. `type=all` answers
 * the palette in one round trip; a named `type` answers the full-list panel with ordinary
 * pagination, in the same envelope as every other paginated list in this app.
 */
class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function __invoke(SearchRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();
        $term = $request->term();
        $filters = $request->filters();

        return match ($request->type()) {
            'messages' => SearchMessageResource::collection($this->search->messagePage($user, $term, $filters)),
            'channels' => ChannelResource::collection($this->search->channelPage($user, $term, $filters)),
            'conversations' => ConversationResource::collection($this->search->conversationPage($user, $term)),
            'servers' => ServerResource::collection($this->search->serverPage($user, $term)),
            'side_chats' => SearchSurfaceResource::collection($this->search->sideChatPage($user, $term, $filters)),
            'threads' => SearchSurfaceResource::collection($this->search->threadPage($user, $term, $filters)),
            'side_chat_groups' => SearchSurfaceResource::collection($this->search->sideChatGroupPage($user, $term, $filters)),
            default => $this->palette($request),
        };
    }

    /**
     * The ⌘K answer: a few of each kind, grouped, no pagination.
     *
     * Grouped rather than interleaved into one ranked list because the kinds aren't
     * comparable — there is no honest way to say whether a channel called "deploy" beats a
     * side chat titled "Deploy plan" or a message mentioning deploys — and because the eye
     * finds a row faster in a labelled group of five than in a mixed list of thirty.
     */
    private function palette(SearchRequest $request): JsonResponse
    {
        $results = $this->search->everything($request->user(), $request->term(), $request->filters());

        return response()->json([
            'data' => [
                'conversations' => ConversationResource::collection($results['conversations'])->resolve(),
                'channels' => ChannelResource::collection($results['channels'])->resolve(),
                'side_chats' => SearchSurfaceResource::collection($results['side_chats'])->resolve(),
                'threads' => SearchSurfaceResource::collection($results['threads'])->resolve(),
                'side_chat_groups' => SearchSurfaceResource::collection($results['side_chat_groups'])->resolve(),
                'servers' => ServerResource::collection($results['servers'])->resolve(),
                'messages' => SearchMessageResource::collection($results['messages'])->resolve(),
            ],
        ]);
    }
}
