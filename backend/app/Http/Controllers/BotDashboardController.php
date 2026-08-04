<?php

namespace App\Http\Controllers;

use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\UpdateBotSettingsRequest;
use App\Http\Requests\Automation\UpdateWelcomeRequest;
use App\Http\Resources\BotResource;
use App\Models\Automation;
use App\Models\BotAuditLog;
use App\Models\BotSettings;
use App\Models\Server;
use App\Services\Automation\ActionRegistry;
use App\Services\Automation\TriggerRegistry;
use App\Support\Automation\ConditionEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's two flat screens: Overview and Configuration.
 *
 * Plus the catalogue — what triggers and actions exist, and what each action's form looks
 * like. The builder renders its forms from that rather than from a copy of the schemas kept
 * in the frontend, so an action added in PHP appears in the UI without a matching change in
 * TypeScript. The alternative is two lists that agree until they don't.
 */
class BotDashboardController extends Controller
{
    /** The numbers along the top, and the recent activity underneath. */
    public function overview(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        $bot = $server->automationBot();

        return response()->json(['data' => [
            'bot' => $bot === null ? null : (new BotResource($bot))->resolve($request),
            // "No bot chosen" and "no bots at all" are different dead ends with different
            // fixes, and the warning has to be able to tell somebody which one they're in.
            'has_bots' => $server->bots()->exists(),
            // Total, not online. Who is online is a presence-channel fact the browser holds
            // and the server never sees (see usePresence) — the dashboard intersects the two
            // itself rather than us inventing a number here that would always be stale.
            'member_count' => $server->members()->count(),
            'channel_count' => $server->channels()->count(),
            'automation_count' => $server->automations()->count(),
            'enabled_automation_count' => $server->automations()->where('enabled', true)->count(),
            'badge_count' => $server->badges()->count(),
            // The audit log, newest first. Capped rather than paginated: this panel is a
            // glance, and anybody who wants the history wants the Logging page.
            'recent' => BotAuditLog::with('automation:id,name', 'subject:id,name')
                ->where('server_id', $server->getKey())
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (BotAuditLog $line) => [
                    'id' => $line->id,
                    'action' => $line->action,
                    'outcome' => $line->outcome,
                    'message' => $line->message,
                    'automation' => $line->automation?->name,
                    'subject' => $line->subject?->name,
                    'created_at' => $line->created_at,
                ]),
        ]]);
    }

    /**
     * What the builder can offer.
     *
     * Includes the operators, so the condition row's dropdown is generated from the same
     * list the evaluator switches on.
     */
    public function catalogue(
        ManageAutomationsRequest $request,
        Server $server,
        TriggerRegistry $triggers,
        ActionRegistry $actions,
    ): JsonResponse {
        return response()->json(['data' => [
            'triggers' => collect($triggers->all())
                ->map(fn (array $trigger, string $name) => ['name' => $name] + $trigger)
                ->values(),
            'actions' => $actions->catalogue(),
            'condition_matches' => [
                ['name' => ConditionEvaluator::MATCH_ALL, 'label' => 'all of these are true'],
                ['name' => ConditionEvaluator::MATCH_ANY, 'label' => 'any of these are true'],
            ],
            'operators' => collect(ConditionEvaluator::OPERATORS)
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
                ->values(),
            /*
             * The pickers an action's schema can name.
             *
             * Sent with the catalogue rather than fetched per form: a `command` field needs
             * this server's commands to render at all, and the builder shouldn't have to
             * know which field types imply which extra request. Channels and badges are
             * already loaded by the dashboard, so only these two are new.
             */
            'commands' => $server->customCommands()->orderBy('name')->get(['id', 'name']),
            'schedules' => $server->schedules()->orderBy('name')->get(['id', 'name']),
            'giveaways' => $server->giveaways()->whereNull('drawn_at')->whereNull('cancelled_at')
                ->latest('id')->get(['id', 'prize'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->prize]),
        ]]);
    }

    public function settings(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        return response()->json(['data' => $this->present(BotSettings::forServer($server))]);
    }

    public function updateSettings(UpdateBotSettingsRequest $request, Server $server): JsonResponse
    {
        $settings = BotSettings::forServer($server);
        $settings->update($request->validated());

        return response()->json(['data' => $this->present($settings)]);
    }

    /**
     * The welcome message, which is really an automation wearing a settings form.
     *
     * It lives as a `builtin: welcome` row on `automations` rather than as two columns here,
     * and that is the point: a built-in fires through the same engine as everything people
     * build themselves. A welcome that took a private path would drift from what the builder
     * can express, and then "why can't my rule do what the welcome message does" becomes a
     * real question with no good answer. Setting a channel here is exactly writing a
     * `member.joined` → `post_message` rule.
     */
    public function welcome(ManageAutomationsRequest $request, Server $server): JsonResponse
    {
        return response()->json(['data' => $this->welcomePayload($server)]);
    }

    public function updateWelcome(UpdateWelcomeRequest $request, Server $server): JsonResponse
    {
        $data = $request->validated();
        $automation = $this->welcomeRule($server);

        // Blank channel means "don't greet anybody", and the honest way to store that is to
        // stop having the rule — not to keep a disabled row nobody can see or explain.
        if (($data['channel_id'] ?? null) === null) {
            $automation?->delete();

            return response()->json(['data' => $this->welcomePayload($server)]);
        }

        $automation ??= new Automation([
            'server_id' => $server->getKey(),
            'name' => 'Welcome Message',
            'trigger' => TriggerRegistry::MEMBER_JOINED,
            'builtin' => Automation::BUILTIN_WELCOME,
        ]);

        DB::transaction(function () use ($automation, $server, $data): void {
            $automation->server_id = $server->getKey();
            /*
             * Setting a welcome channel means "switch this on". An `enabled` sent by the
             * client is only honoured for a rule that already exists — belt and braces
             * against the payload bug above, and the right reading either way: nobody
             * picks a channel in order to create something that does nothing.
             */
            $automation->enabled = $automation->exists ? ($data['enabled'] ?? $automation->enabled) : true;
            $automation->save();

            $automation->actions()->delete();
            $automation->actions()->create([
                'type' => 'post_message',
                'config' => ['channel_id' => $data['channel_id'], 'body' => $data['body']],
                'position' => 0,
            ]);
        });

        return response()->json(['data' => $this->welcomePayload($server)]);
    }

    /** @return array<string, mixed> */
    private function welcomePayload(Server $server): array
    {
        // Re-read rather than reuse a model the caller may have just written: the payload
        // should describe what is now stored, not what we asked for.
        $rule = $this->welcomeRule($server);
        $action = $rule?->actions->first();

        return [
            /*
             * True when there is no rule yet — because that is what saving one would
             * produce, and this payload is what the form round-trips back on save.
             *
             * Reporting false here meant the first save of a welcome message echoed
             * `enabled: false` straight back and created the rule switched off. It could
             * then never fire, and nothing on the screen said why.
             */
            'enabled' => $rule === null || (bool) $rule->enabled,
            'channel_id' => $action?->option('channel_id'),
            'body' => $action?->option('body'),
        ];
    }

    private function welcomeRule(Server $server): ?Automation
    {
        return $server->automations()
            ->with('actions')
            ->where('builtin', Automation::BUILTIN_WELCOME)
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(BotSettings $settings): array
    {
        return [
            'command_prefix' => $settings->command_prefix,
            'mod_log_channel_id' => $settings->mod_log_channel_id,
            'announcement_channel_id' => $settings->announcement_channel_id,
            'reminder_channel_id' => $settings->reminder_channel_id,
            'mod_roles' => $settings->mod_roles ?? [],
        ];
    }
}
