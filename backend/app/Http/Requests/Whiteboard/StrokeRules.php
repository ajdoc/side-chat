<?php

namespace App\Http\Requests\Whiteboard;

use Illuminate\Validation\Rule;

/**
 * The validation for one committed stroke, shared by every surface a board can hang off (a
 * side chat, a channel). `payload` is the whiteboard engine's contract, so this validates
 * the envelope and *caps* sizes rather than pinning down each kind's exact shape — the point
 * is to keep a malformed or oversized mark out of the database, not to re-specify the
 * geometry the client already agrees on. Points are bounded hard: a pen path is committed
 * simplified, and nothing legitimate needs thousands of vertices.
 */
trait StrokeRules
{
    /** The full stroke envelope — for creating one. @return array<string, mixed> */
    protected function strokeRules(): array
    {
        return array_merge([
            'kind' => ['required', Rule::in(['pen', 'rect', 'ellipse', 'line', 'arrow', 'text', 'note', 'bg'])],
            'client_id' => ['required', 'string', 'max:64'],
        ], $this->payloadRules());
    }

    /** Just the geometry/style — for updating one in place (a move or resize). @return array<string, mixed> */
    protected function payloadRules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'payload.color' => ['nullable', 'string', 'max:9'],
            'payload.fill' => ['nullable', 'string', 'max:9'],
            'payload.width' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'payload.text' => ['nullable', 'string', 'max:500'],
            // Shapes/lines carry their two corners; text/notes carry an anchor point (+ note size).
            'payload.x1' => ['nullable', 'numeric'],
            'payload.y1' => ['nullable', 'numeric'],
            'payload.x2' => ['nullable', 'numeric'],
            'payload.y2' => ['nullable', 'numeric'],
            'payload.x' => ['nullable', 'numeric'],
            'payload.y' => ['nullable', 'numeric'],
            'payload.w' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            // A pen path, already simplified on the client.
            'payload.points' => ['nullable', 'array', 'max:1000'],
            'payload.points.*.x' => ['required_with:payload.points', 'numeric'],
            'payload.points.*.y' => ['required_with:payload.points', 'numeric'],
        ];
    }
}
