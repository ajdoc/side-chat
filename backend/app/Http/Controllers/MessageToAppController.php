<?php

namespace App\Http\Controllers;

use App\Http\Requests\Message\ViewMessageInfoRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Support\Apps\MessageParts;
use App\Support\Apps\MessageToApp;
use Illuminate\Http\JsonResponse;

/**
 * "Add this message to an app."
 *
 * Two routes: what the dialog can offer, and doing it. The apps themselves are
 * {@see MessageToApp}, which is where adding the next one is a row.
 *
 * ## Authorisation
 *
 * Two channels again, and the same split as the import: {@see ViewMessageInfoRequest} settles that
 * the caller may read the *message*, and `Channel::visibleTo` settles the *target*. Neither
 * knows about the other, and together they are the whole rule — without the second, filing a
 * message into a channel would be a way to write into a room you can't see.
 */
class MessageToAppController extends Controller
{
    /**
     * Where this message could go, and what it would look like when it got there.
     *
     * One request rather than one per app: the answer is a handful of channels and a parsed
     * preview, and a dialog that fetched per app would flicker through three loading states
     * while somebody made up their mind.
     */
    public function targets(ViewMessageInfoRequest $request, Message $message): JsonResponse
    {
        $user = $request->user();
        $source = $message->channel;

        /*
         * Every channel the caller can see, in three groups.
         *
         * All three are the same thing to the server — a channel id — and they are separated
         * only because they answer different questions for the person choosing. **Any** channel
         * can be a target: a text channel, a DM, a voice room and a Side Space all carry a Side
         * Desk, and their board is the same storage an app channel's is. The first version of
         * this listed only app channels, which quietly made "file it on this team's board" the
         * one thing you couldn't do.
         */
        $channels = Channel::query()
            ->visibleTo($user)
            ->whereKeyNot($source?->getKey())
            ->with(['app', 'server:id,name', 'conversation:id,name'])
            ->orderBy('name')
            // A ceiling rather than pages: this is a picker with a search box in front of it,
            // and somebody in four hundred channels needs the box, not page two.
            ->limit(300)
            ->get();

        // App channels are grouped by which app they run, because an app channel is only a
        // target for *its* app — filing a task into a board channel would have nowhere to go.
        $appChannels = $channels
            ->filter(fn (Channel $c) => $c->type === 'app' && $c->app !== null && MessageToApp::supports($c->app->app_id))
            ->groupBy(fn (Channel $c) => $c->app->app_id)
            ->map(fn ($group) => $group->map(fn (Channel $c) => $this->row($c))->values());

        return response()->json([
            'apps' => MessageToApp::apps(),
            'unsupported' => MessageToApp::unsupported(),
            // "This chat's own apps" — the desk tab, the widget, the canvas card. All one
            // channel id, because that is all any of them ever were.
            'here' => $source === null ? null : $this->row($source),
            'app_channels' => $appChannels,
            // Everything else, for every app. A conversation channel has no app of its own to
            // clash with, so all of them are offered whichever app is chosen.
            'channels' => $channels
                ->filter(fn (Channel $c) => $c->type !== 'app')
                ->map(fn (Channel $c) => $this->row($c))
                ->values(),
            /*
             * An encrypted message is answered honestly rather than parsed.
             *
             * Its stored body is the envelope, so a preview built from it would be a title of
             * base64 — and it can't be filed anyway (see MessageToApp::run). Saying so here is
             * what lets the dialog explain itself instead of offering apps that all fail.
             */
            'encrypted' => $message->isEncrypted(),
            'preview' => $message->isEncrypted() ? null : [
                'title' => MessageParts::title($message->body),
                'body' => MessageParts::rest($message->body),
                'poll' => MessageParts::poll($message->body),
                'files' => $message->attachments->where('encrypted', false)->count(),
            ],
        ]);
    }

    /**
     * One channel as the picker draws it.
     *
     * `type` rides along so the list can say what kind of room it is — a Side Space and a text
     * channel are the same target and very much not the same place, and the icon is how somebody
     * tells two similarly named ones apart. `where` names the server, or the DM it lives in.
     *
     * @return array<string, mixed>
     */
    private function row(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'type' => $channel->type,
            'where' => $channel->server?->name ?? $channel->conversation?->name,
        ];
    }

    public function store(ViewMessageInfoRequest $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string'],
            'target_channel_id' => ['required', 'integer'],
            // App-specific extras — the tracker's project, the kanban column, the calendar's
            // start. Free-form here and validated by the handler that understands it, the same
            // way a widget's state belongs to its handler rather than to the API layer.
            'options' => ['sometimes', 'array'],
        ]);

        $target = Channel::query()->visibleTo($request->user())->whereKey($data['target_channel_id'])->first();

        // 404 for a channel you can't see, so this can't be used to probe which ids exist.
        abort_if($target === null, 404);

        $summary = MessageToApp::run(
            $data['app'],
            $message->load('user', 'attachments'),
            $target,
            $request->user(),
            $data['options'] ?? [],
        );

        return response()->json(['message' => $summary, 'channel_id' => $target->id, 'app' => $data['app']]);
    }
}
