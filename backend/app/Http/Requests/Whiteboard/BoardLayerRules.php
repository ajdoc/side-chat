<?php

namespace App\Http\Requests\Whiteboard;

/**
 * Validation for a board's layer list, shared by the channel and side chat gates so both accept
 * the same body.
 *
 * The array index *is* the layer number the strokes carry, so this is deliberately a whole-list
 * replace with no id: reordering would silently renumber every mark on the board. Layers are
 * added, renamed, hidden and shown.
 */
final class BoardLayerRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            // `present`, not `required`: emptying the list is how a board goes back to having no
            // layers at all, and `required` rejects an empty array.
            'layers' => ['present', 'array', 'max:64'],
            'layers.*.name' => ['required', 'string', 'max:40'],
            'layers.*.visible' => ['required', 'boolean'],
        ];
    }
}
