<?php

namespace App\Http\Controllers;

use App\Actions\Message\SendMessageAction;
use App\DTOs\Message\SendMessageData;
use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreGiveawayRequest;
use App\Models\Automation;
use App\Models\Channel;
use App\Models\Giveaway;
use App\Models\Server;
use App\Services\Automation\TriggerRegistry;
use App\Services\Giveaways\GiveawayDrawer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Timed giveaways: a prize, a message to react to, and a closing time.
 *
 * Entries arrive through the automation engine — creating one creates a `reaction.added`
 * rule whose action is `enter_giveaway`. See the giveaways migration for why that's the
 * mechanism rather than a listener of its own.
 */
class GiveawayController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        $giveaways = $server->giveaways()
            ->with('requiredBadge:id,name')
            ->withCount('entries')
            ->latest('id')
            ->get();

        return response()->json(['data' => $giveaways->map($this->present(...))]);
    }

    public function store(StoreGiveawayRequest $request, Server $server, SendMessageAction $send): JsonResponse
    {
        $data = $request->validated();
        $bot = $server->automationBot();

        // The announcement is *from* somebody and the emoji has to be placed by an account.
        // Refused up front rather than leaving a giveaway nobody can enter.
        if ($bot?->user === null) {
            return response()->json([
                'message' => 'Pick a bot to run automations before starting a giveaway.',
            ], 422);
        }

        $channel = Channel::where('server_id', $server->getKey())->findOrFail($data['channel_id']);

        if (! $channel->hasMember($bot->user)) {
            return response()->json(['message' => "The bot isn't in #{$channel->name}."], 422);
        }

        $giveaway = DB::transaction(function () use ($server, $channel, $data, $bot, $send) {
            $emoji = $data['emoji'] ?? '🎉';

            // The row first: the announcement has to name it, and the entry rule has to
            // point at both it and the message.
            $giveaway = $server->giveaways()->create([
                'channel_id' => $channel->getKey(),
                'prize' => $data['prize'],
                'emoji' => $emoji,
                'winner_count' => $data['winner_count'] ?? 1,
                'required_badge_id' => $data['required_badge_id'] ?? null,
                'ends_at' => $data['ends_at'],
            ]);

            $message = $send->handle($channel, $bot->user, SendMessageData::fromArray([
                'body' => $this->announcement($giveaway),
            ]));

            $giveaway->forceFill(['message_id' => $message->getKey()])->save();

            // Seeded directly rather than through ToggleReactionAction: that fires the
            // triggers, and the bot placing the emoji must not enter itself.
            $message->reactions()->firstOrCreate([
                'user_id' => $bot->user->getKey(),
                'emoji' => $emoji,
            ]);

            $rule = $server->automations()->create([
                'name' => "Giveaway: {$giveaway->prize}",
                'trigger' => TriggerRegistry::REACTION_ADDED,
                'builtin' => Automation::BUILTIN_GIVEAWAY,
                'trigger_config' => ['giveaway_id' => $giveaway->getKey(), 'message_id' => $message->getKey()],
                'conditions' => [
                    ['field' => 'message_id', 'operator' => 'equals', 'value' => $message->getKey()],
                    ['field' => 'emoji', 'operator' => 'equals', 'value' => $emoji],
                ],
            ]);

            $rule->actions()->create([
                'type' => 'enter_giveaway',
                'config' => ['giveaway_id' => $giveaway->getKey()],
                'position' => 0,
            ]);

            return $giveaway;
        });

        return response()->json(['data' => $this->present($giveaway->loadCount('entries'))], 201);
    }

    /**
     * Draw it now, before its closing time.
     *
     * The same path the runner takes, so an early draw and an on-time one are identical —
     * including the announcement naming the winners.
     */
    public function draw(
        ManageAutomationsRequest $request,
        Server $server,
        Giveaway $giveaway,
        GiveawayDrawer $drawer,
    ): JsonResponse {
        $this->belongsTo($server, $giveaway);

        if ($giveaway->drawn_at !== null) {
            return response()->json(['message' => 'That giveaway has already been drawn.'], 422);
        }

        $drawer->draw($giveaway);

        return response()->json(['data' => $this->present($giveaway->fresh()->loadCount('entries'))]);
    }

    /**
     * Call it off.
     *
     * Cancelled rather than deleted: people entered, and the record of a giveaway that was
     * called off is more honest than one that silently never existed. The entry rule goes,
     * so reacting stops meaning anything.
     */
    public function destroy(ManageAutomationsRequest $request, Server $server, Giveaway $giveaway): Response
    {
        $this->belongsTo($server, $giveaway);

        DB::transaction(function () use ($server, $giveaway) {
            $giveaway->forceFill(['cancelled_at' => now()])->save();
            $this->entryRules($server, $giveaway)->each->delete();
        });

        return response()->noContent();
    }

    private function announcement(Giveaway $giveaway): string
    {
        $lines = [
            "🎁 **Giveaway: {$giveaway->prize}**",
            '',
            "React with {$giveaway->emoji} to enter.",
            $giveaway->winner_count > 1 ? "**{$giveaway->winner_count}** winners." : '**1** winner.',
        ];

        return implode("\n", $lines);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Automation> */
    private function entryRules(Server $server, Giveaway $giveaway)
    {
        return $server->automations()
            ->where('builtin', Automation::BUILTIN_GIVEAWAY)
            ->get()
            ->filter(fn (Automation $rule) => (int) $rule->triggerOption('giveaway_id') === $giveaway->getKey());
    }

    /** @return array<string, mixed> */
    private function present(Giveaway $giveaway): array
    {
        return [
            'id' => $giveaway->id,
            'server_id' => $giveaway->server_id,
            'channel_id' => $giveaway->channel_id,
            'message_id' => $giveaway->message_id,
            'prize' => $giveaway->prize,
            'emoji' => $giveaway->emoji,
            'winner_count' => (int) $giveaway->winner_count,
            'required_badge' => $giveaway->requiredBadge?->name,
            'ends_at' => $giveaway->ends_at,
            'drawn_at' => $giveaway->drawn_at,
            // Derived, not stored — see the model.
            'status' => $giveaway->status(),
            'entries_count' => $giveaway->entries_count ?? $giveaway->entries()->count(),
            'winners' => $giveaway->drawn_at === null
                ? []
                : $giveaway->entries()->with('user:id,name')->where('won', true)->get()
                    ->map(fn ($entry) => $entry->user?->name)->filter()->values(),
        ];
    }

    private function belongsTo(Server $server, Giveaway $giveaway): void
    {
        abort_if($giveaway->server_id !== $server->getKey(), 404);
    }
}
