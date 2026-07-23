<?php

namespace App\Http\Requests\Canvas;

/**
 * The validation rules for an Open Canvas card, shared by the channel and side chat requests
 * so the two gates ({@see ChannelCanvasItemRequest}, {@see CanvasItemRequest}) validate the
 * body identically. `content` is a free-form blob whose inner shape is the card kind's
 * contract with its renderer (like a whiteboard stroke's payload), so it's validated only as
 * an array. On update everything is optional — a drag saves just `x`/`y`.
 */
final class CanvasItemRules
{
    /** @return array<string, mixed> */
    public static function forMethod(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind' => [$req, 'string', 'in:note,todo,widget'],
            'content' => [$req, 'array'],
            // A `widget` card names which widget to place; note/todo cards leave this out.
            'content.type' => ['required_if:kind,widget', 'string', 'in:music,video,kanban,poll,shooter,racing,skribbl'],
            'x' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'y' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'w' => ['sometimes', 'integer', 'min:120', 'max:4000'],
            'h' => ['sometimes', 'integer', 'min:80', 'max:4000'],
            'z' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
