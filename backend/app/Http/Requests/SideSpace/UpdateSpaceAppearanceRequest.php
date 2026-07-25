<?php

namespace App\Http\Requests\SideSpace;

use App\Support\SideSpace\Avatars;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Choosing what you look like in a room, and which starter is following you.
 *
 * Yours alone — there's no channel in the route, because a look belongs to a person and not to
 * a place. Walk into any Side Space on any server and it's the same you.
 *
 * Every value is checked against the catalogue rather than merely being a string, because each
 * one names a *sprite layer*: an unknown hair style isn't a harmless odd value, it's a head
 * with nothing drawn on it in everybody else's browser.
 */
class UpdateSpaceAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar' => ['sometimes', 'array'],
            'avatar.body' => ['required_with:avatar', 'string', Rule::in(Avatars::BODIES)],
            'avatar.hair' => ['required_with:avatar', 'string', Rule::in(Avatars::HAIR)],
            'avatar.hair_color' => ['required_with:avatar', 'string', Rule::in(Avatars::HAIR_COLORS)],
            'avatar.skin' => ['required_with:avatar', 'string', Rule::in(Avatars::SKINS)],
            'avatar.outfit' => ['required_with:avatar', 'string', Rule::in(Avatars::OUTFITS)],

            // Null is a real choice — it's how you send the pet home.
            'pet' => ['sometimes', 'nullable', 'string', Rule::in(Avatars::petKeys())],
        ];
    }
}
