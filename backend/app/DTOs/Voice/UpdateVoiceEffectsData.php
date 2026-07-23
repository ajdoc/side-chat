<?php

namespace App\DTOs\Voice;

use App\Models\Channel;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

/**
 * One decision about what this call plays when somebody comes or goes.
 *
 * `user_id` is what it's *about*: a person, or — when absent — the room itself, which is the
 * default everyone falls back to. One shape for both because they are the same decision at
 * two scopes, and splitting them into two endpoints would mean two of everything to keep in
 * step for no gain.
 *
 * Both effects are always present, unlike UpdateVoiceStateData's partial patch: they come
 * from one small form with both pickers on it, so "nothing" is a choice made there rather
 * than a field left out.
 */
final class UpdateVoiceEffectsData extends ValidatedDTO
{
    public ?int $user_id;

    public ?string $join_effect;

    public ?string $leave_effect;

    /**
     * Single source of truth for validation — reused by the matching FormRequest, which adds
     * the part that needs the route: that this person is actually a member here.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'join_effect' => ['present', 'nullable', 'string', 'in:'.implode(',', Channel::VOICE_EFFECTS)],
            'leave_effect' => ['present', 'nullable', 'string', 'in:'.implode(',', Channel::VOICE_EFFECTS)],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return self::validationRules();
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'user_id' => null,
            'join_effect' => null,
            'leave_effect' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }

    /**
     * The two effects, ready to store. An empty string from a "Nothing" option becomes null —
     * one representation of "nothing happens", so nothing downstream has to know two.
     *
     * @return array{join_effect: string|null, leave_effect: string|null}
     */
    public function effects(): array
    {
        return [
            'join_effect' => $this->join_effect ?: null,
            'leave_effect' => $this->leave_effect ?: null,
        ];
    }

    /** Is this about one person, or about the room? */
    public function isForPerson(): bool
    {
        return $this->user_id !== null;
    }
}
