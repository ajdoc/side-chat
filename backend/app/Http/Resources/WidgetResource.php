<?php

namespace App\Http\Resources;

use App\Services\Widgets\RedactsState;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A widget as its card renders it. `state` is passed through verbatim — its shape is the
 * handler's contract with the matching Vue card, and the API layer stays out of it.
 *
 * The one exception is a handler that declares itself {@see RedactsState}: a widget holding
 * a secret (Skribbl's word, which only the drawer may see) gets the chance to strip what
 * this viewer isn't entitled to before the state leaves the server. Still no shape
 * knowledge here — we only ask the handler.
 *
 * @mixin \App\Models\Widget
 */
class WidgetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'type' => $this->type,
            'state' => $this->stateFor($request),
            'created_at' => $this->created_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function stateFor(Request $request): ?array
    {
        $state = $this->state;
        $handler = app(WidgetService::class)->handlerForType($this->type);

        return ($handler instanceof RedactsState && is_array($state))
            ? $handler->forViewer($state, $request->user())
            : $state;
    }
}
