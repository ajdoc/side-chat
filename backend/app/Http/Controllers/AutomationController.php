<?php

namespace App\Http\Controllers;

use App\Http\Requests\Automation\ManageAutomationsRequest;
use App\Http\Requests\Automation\StoreAutomationRequest;
use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\Server;
use App\Services\Automation\AutomationEngine;
use App\Support\Automation\AutomationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The Automations page.
 *
 * Every route re-checks that the rule in the path belongs to the server in the path. Route
 * model binding will happily hand over automation 7 to a request about server 3, and
 * without the check any server's staff could read or rewrite another server's rules by
 * guessing an id — the same trap BotController guards against, for the same reason.
 */
class AutomationController extends Controller
{
    public function index(ManageAutomationsRequest $request, Server $server): AnonymousResourceCollection
    {
        return AutomationResource::collection(
            $server->automations()->with('actions')->latest()->get()
        );
    }

    public function store(StoreAutomationRequest $request, Server $server): JsonResponse
    {
        $automation = $this->save($server, new Automation, $request->validated());

        return (new AutomationResource($automation))->response()->setStatusCode(201);
    }

    public function update(StoreAutomationRequest $request, Server $server, Automation $automation): AutomationResource
    {
        $this->belongsTo($server, $automation);

        return new AutomationResource($this->save($server, $automation, $request->validated()));
    }

    /**
     * Off and on, without opening the editor.
     *
     * Its own route because it's the one edit people make in a hurry — a rule is misfiring
     * and they want it stopped now — and making that a full round trip through the form
     * would be the wrong shape for it.
     */
    public function toggle(ManageAutomationsRequest $request, Server $server, Automation $automation): AutomationResource
    {
        $this->belongsTo($server, $automation);

        $automation->update(['enabled' => ! $automation->enabled]);

        return new AutomationResource($automation->load('actions'));
    }

    public function destroy(ManageAutomationsRequest $request, Server $server, Automation $automation): Response
    {
        $this->belongsTo($server, $automation);

        $automation->delete();

        return response()->noContent();
    }

    /**
     * Run it now, against a made-up event.
     *
     * The "test" button. It runs for real — it posts the message, it grants the badge —
     * because a dry run that only claimed what it would do would be testing the preview
     * rather than the rule, and the failures worth catching (a deleted channel, a bot
     * without access) only show up on the real path.
     *
     * The subject is whoever pressed the button. Not a placeholder: a welcome message
     * tested against a fake name doesn't tell you whether `{user}` works.
     */
    public function run(
        ManageAutomationsRequest $request,
        Server $server,
        Automation $automation,
        AutomationEngine $engine,
    ): JsonResponse {
        $this->belongsTo($server, $automation);

        $user = $request->user();

        $engine->run($automation, new AutomationContext($server->getKey(), $automation->trigger, [
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'server_name' => $server->name,
        ]));

        return response()->json(['data' => ['ran' => true]]);
    }

    /**
     * Write the rule and its actions as one thing.
     *
     * Actions are replaced wholesale rather than diffed. They're ordered, positions shift
     * when one is removed, and a diff would have to reconcile that against ids the client
     * may have reordered — for a list that is almost never longer than three.
     *
     * @param  array<string, mixed>  $data
     */
    private function save(Server $server, Automation $automation, array $data): Automation
    {
        return DB::transaction(function () use ($server, $automation, $data): Automation {
            $automation->fill([
                'server_id' => $server->getKey(),
                'name' => $data['name'],
                'trigger' => $data['trigger'],
                'trigger_config' => $data['trigger_config'] ?? null,
                'conditions' => $data['conditions'] ?? null,
                'enabled' => $data['enabled'] ?? true,
            ])->save();

            $automation->actions()->delete();

            foreach (array_values($data['actions']) as $position => $action) {
                $automation->actions()->create([
                    'type' => $action['type'],
                    'config' => $action['config'] ?? null,
                    'position' => $position,
                ]);
            }

            return $automation->load('actions');
        });
    }

    /** 404 rather than 403: a rule in another server is not a rule this server has. */
    private function belongsTo(Server $server, Automation $automation): void
    {
        abort_if($automation->server_id !== $server->getKey(), 404);
    }
}
