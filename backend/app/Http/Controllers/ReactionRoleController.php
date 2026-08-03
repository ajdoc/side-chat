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

        $channel = Channel::where('server_id', $server->getKey())->findOrFail($data['channel_id']);

        if (! $channel->hasMember($bot->user)) {
            return response()->json(['message' => "The bot isn't in #{$channel->name}."], 422);
        }

        $message = $send->handle($channel, $bot->user, SendMessageData::fromArray([
            'body' => $data['body'],
        ]));

        DB::transaction(function () use ($server, $message, $data, $bot) {
            foreach ($data['pairs'] as $pair) {
                $badge = Badge::where('server_id', $server->getKey())->find($pair['badge_id']);

                if ($badge === null) {
                    continue;
                }

                // Placed by the bot so the emoji is already there to click. Written directly
                // rather than through ToggleReactionAction: that fires the triggers, and a
                // bot seeding the message must not enter itself into its own rules.
                $message->reactions()->firstOrCreate([
                    'user_id' => $bot->user->getKey(),
                    'emoji' => $pair['emoji'],
                ]);

                $this->rule($server, $message, $pair['emoji'], $badge, grant: true);
                $this->rule($server, $message, $pair['emoji'], $badge, grant: false);
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
                'pairs' => $group->map(fn (Automation $rule) => [
                    'emoji' => $rule->triggerOption('emoji'),
                    'badge_id' => (int) $rule->actions->first()?->option('badge_id'),
                    'badge_name' => $badges[(int) $rule->actions->first()?->option('badge_id')]?->name,
                ])->values(),
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
