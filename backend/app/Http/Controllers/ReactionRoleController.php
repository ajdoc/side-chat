<?php

namespace App\Http\Controllers;

use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreReactionRoleRequest;
use App\Models\Automation;
use App\Models\Badge;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Server;
use App\Services\Automation\TriggerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * "React with 🎮 to get the Griefer badge."
 *
 * Creating one does three things in a breath, which is the whole point of the page: it posts
 * the announcement as the bot, it puts the emoji on it ready to be clicked, and it writes the
 * rules. Making people do those separately — post a message, find its id, write two rules —
 * is exactly the work this feature exists to remove.
 *
 * The rules are ordinary automations, `builtin: reaction_role`. Two per emoji:
 *
 *  - `reaction.added` on that message + that emoji → grant the badge;
 *  - `reaction.removed`, same conditions → revoke it.
 *
 * The pair is why {@see TriggerRegistry::REACTION_REMOVED} exists at all. A badge you take by
 * reacting has to be one you can give up by un-reacting; anything else is a badge you can
 * never lose.
 */
class ReactionRoleController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        return response()->json(['data' => $this->groups($server)]);
    }

    public function store(
        StoreReactionRoleRequest $request,
        Server $server,
        SendMessageAction $send,
    ): JsonResponse {
        $data = $request->validated();
        $bot = $server->automationBot();

        // Every part of this needs a voice — the announcement is *from* somebody, and the
        // emoji have to be placed by an account. Refused up front rather than half-done.
        if ($bot?->user === null) {
            return response()->json([
                'message' => 'Pick a bot to run automations before creating reaction roles.',
            ], 422);
        }

        /*
         * One post per channel, each with its own rules.
         *
         * A role people pick in #welcome often needs to be pickable in #roles too, and
         * asking somebody to build the same pairs twice is how the two drift apart. Each
         * message gets its own pair of rules because a rule matches on message id — that's
         * what makes reacting in either place grant the same badge.
         */
        $channelIds = array_values(array_unique([$data['channel_id'], ...($data['extra_channel_ids'] ?? [])]));
        $channels = Channel::where('server_id', $server->getKey())->findMany($channelIds);

        $unreachable = $channels->reject(fn (Channel $channel) => $channel->hasMember($bot->user));

        // All or nothing: a half-posted set would leave the same badge reachable from some
        // rooms and not others, with nothing on screen to say which.
        if ($unreachable->isNotEmpty()) {
            return response()->json([
                'message' => 'The bot isn’t in #'.$unreachable->first()->name.'.',
            ], 422);
        }

        DB::transaction(function () use ($server, $channels, $data, $bot, $send) {
            foreach ($channels as $channel) {
                $message = $send->handle($channel, $bot->user, SendMessageData::fromArray([
                    'body' => $data['body'],
                ]));

                foreach ($data['pairs'] as $pair) {
                    $badge = Badge::where('server_id', $server->getKey())->find($pair['badge_id']);

                    if ($badge === null) {
                        continue;
                    }

                    // Placed by the bot so the emoji is already there to click. Written
                    // directly rather than through ToggleReactionAction: that fires the
                    // triggers, and a bot seeding the message must not enter itself.
                    $message->reactions()->firstOrCreate([
                        'user_id' => $bot->user->getKey(),
                        'emoji' => $pair['emoji'],
                    ]);

                    $this->rule($server, $message, $pair['emoji'], $badge, grant: true);
                    $this->rule($server, $message, $pair['emoji'], $badge, grant: false);
                }
            }
        });

        return response()->json(['data' => $this->groups($server)], 201);
    }

    /**
     * Delete a whole reaction-role message's worth of rules.
     *
     * Addressed by message rather than by rule id: what somebody wants to remove is "that
     * post and what it does", and leaving half a pair behind would give a badge nobody could
     * give up. The message itself stays — deleting what a bot said months ago is a separate
     * decision, and one this page shouldn't make silently.
     */
    public function destroy(ManageAutomationsRequest $request, Server $server, Message $message): Response
    {
        $server->automations()
            ->where('builtin', Automation::BUILTIN_REACTION_ROLE)
            ->get()
            ->filter(fn (Automation $rule) => (int) $rule->triggerOption('message_id') === $message->getKey())
            ->each->delete();

        return response()->noContent();
    }

    /**
     * Post the announcement again, and move the rules onto the new message.
     *
     * The old post gets buried, or somebody deletes it, and then the emoji nobody can see is
     * the only way in. Reposting rather than editing, because a message that arrived a month
     * ago stays a month ago however you rewrite it — what's wanted is a *new* one at the
     * bottom of the channel.
     *
     * The rules are re-pointed rather than recreated, so anybody who already holds the badge
     * keeps it and the old post simply stops meaning anything.
     */
    public function resend(
        ManageAutomationsRequest $request,
        Server $server,
        Message $message,
        SendMessageAction $send,
    ): JsonResponse {
        $bot = $server->automationBot();

        if ($bot?->user === null) {
            return response()->json(['message' => 'Pick a bot to run automations first.'], 422);
        }

        $rules = $server->automations()
            ->with('actions')
            ->where('builtin', Automation::BUILTIN_REACTION_ROLE)
            ->get()
            ->filter(fn (Automation $rule) => (int) $rule->triggerOption('message_id') === $message->getKey());

        if ($rules->isEmpty()) {
            return response()->json(['message' => 'That message has no reaction roles on it.'], 404);
        }

        $channel = Channel::where('server_id', $server->getKey())->find($message->channel_id);

        if ($channel === null || ! $channel->hasMember($bot->user)) {
            return response()->json(['message' => 'The bot can’t post in that channel.'], 422);
        }

        // The same words as before. Re-reading the original rather than asking for them again
        // keeps a resend a resend — if the wording is wrong, that's an edit, not this.
        $posted = $send->handle($channel, $bot->user, SendMessageData::fromArray([
            'body' => $message->body,
        ]));

        DB::transaction(function () use ($rules, $posted, $bot) {
            foreach ($rules as $rule) {
                $emoji = (string) $rule->triggerOption('emoji');

                $posted->reactions()->firstOrCreate([
                    'user_id' => $bot->user->getKey(),
                    'emoji' => $emoji,
                ]);

                $rule->update([
                    'trigger_config' => ['message_id' => $posted->getKey(), 'channel_id' => $posted->channel_id, 'emoji' => $emoji],
                    'conditions' => [
                        ['field' => 'message_id', 'operator' => 'equals', 'value' => $posted->getKey()],
                        ['field' => 'emoji', 'operator' => 'equals', 'value' => $emoji],
                    ],
                ]);
            }
        });

        return response()->json(['data' => $this->groups($server)]);
    }

    /**
     * The rules, regrouped into the thing the page shows: one message, several emoji→badge
     * pairs. The grant half is what's read — the revoke half is its mirror and would be a
     * duplicate row on screen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groups(Server $server): array
    {
        $rules = $server->automations()
            ->with('actions')
            ->where('builtin', Automation::BUILTIN_REACTION_ROLE)
            ->where('trigger', TriggerRegistry::REACTION_ADDED)
            ->get();

        $badges = $server->badges()->get()->keyBy('id');

        return $rules->groupBy(fn (Automation $rule) => (int) $rule->triggerOption('message_id'))
            ->map(fn ($group, $messageId) => [
                'message_id' => (int) $messageId,
                'channel_id' => (int) $group->first()->triggerOption('channel_id'),
                'pairs' => $group->map(function (Automation $rule) use ($badges) {
                    $badgeId = (int) $rule->actions->first()?->option('badge_id');

                    return [
                        'emoji' => $rule->triggerOption('emoji'),
                        'badge_id' => $badgeId,
                        // `get`, not `[...]`: a rule can outlive the badge it names — deleting
                        // a badge is allowed and merely makes the rule start failing (see
                        // BadgeController) — and indexing a collection with a missing key
                        // throws, which took the whole dashboard down with a 500.
                        'badge_name' => $badges->get($badgeId)?->name,
                    ];
                })->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * One half of a pair.
     *
     * The message and emoji live in `trigger_config` *and* in the conditions. The conditions
     * are what the engine actually filters on; the config is what this page reads back to
     * regroup the rules, and reading it out of a condition array would mean depending on the
     * order somebody's conditions happen to be in.
     */
    private function rule(Server $server, Message $message, string $emoji, Badge $badge, bool $grant): void
    {
        $automation = $server->automations()->create([
            'name' => ($grant ? 'React for ' : 'Un-react to lose ').$badge->name,
            'trigger' => $grant ? TriggerRegistry::REACTION_ADDED : TriggerRegistry::REACTION_REMOVED,
            'builtin' => Automation::BUILTIN_REACTION_ROLE,
            'trigger_config' => [
                'message_id' => $message->getKey(),
                'channel_id' => $message->channel_id,
                'emoji' => $emoji,
            ],
            'conditions' => [
                ['field' => 'message_id', 'operator' => 'equals', 'value' => $message->getKey()],
                ['field' => 'emoji', 'operator' => 'equals', 'value' => $emoji],
            ],
        ]);

        $automation->actions()->create([
            'type' => $grant ? 'grant_badge' : 'revoke_badge',
            'config' => ['badge_id' => $badge->getKey()],
            'position' => 0,
        ]);
    }
}
