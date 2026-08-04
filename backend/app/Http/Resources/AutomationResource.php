<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rule as the dashboard shows and edits it.
 *
 * The whole rule, actions included, rather than a summary plus a second fetch: the list and
 * the editor want the same thing, a rule is small, and a list that had to fetch each row to
 * open it would flicker on every edit.
 */
class AutomationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'trigger_config' => $this->trigger_config ?? [],
            'conditions' => $this->conditions ?? [],
            // 'all' | 'any' — whether every filter must hold or just one of them.
            'condition_match' => $this->condition_match ?? 'all',
            'enabled' => (bool) $this->enabled,
            // Set for the rules that have a dashboard page of their own — the welcome
            // message, a reaction role. The generic list hides these; the feature page
            // finds its row by this.
            'builtin' => $this->builtin,
            // "Has this ever actually fired" is the first question anybody debugging asks.
            'run_count' => (int) $this->run_count,
            'last_run_at' => $this->last_run_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'actions' => $this->whenLoaded('actions', fn () => $this->actions->map(fn ($action) => [
                'id' => $action->id,
                'type' => $action->type,
                'config' => $action->config ?? [],
                'position' => $action->position,
            ])->values()),
        ];
    }
}
