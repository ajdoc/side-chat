<?php

namespace App\Http\Requests\SideSpace;

use App\Services\Games\ArpgGame;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rolling a new hero.
 *
 * Yours alone, like your costume and for the same reason — a character belongs to a player, not
 * to a room, and takes the same self down whichever server's dungeon you walk into. The class
 * list is checked against {@see ArpgGame::CLASSES} rather than being any old string, because it
 * picks the starting attributes: an unknown class is a hero with no numbers.
 */
class StoreArpgCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'class' => ['required', Rule::in(array_keys(ArpgGame::CLASSES))],
        ];
    }
}
