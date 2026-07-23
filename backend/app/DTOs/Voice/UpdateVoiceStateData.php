<?php

namespace App\DTOs\Voice;

use App\Casts\NullableBooleanCast;
use WendellAdriel\ValidatedDTO\ValidatedDTO;

/**
 * A partial update to how you appear to the rest of the call. Every field is optional:
 * toggling your mic says nothing about your screen, so an omitted field means "leave it
 * as it is" rather than "false".
 */
final class UpdateVoiceStateData extends ValidatedDTO
{
    public ?bool $muted;
    public ?bool $deafened;
    public ?bool $screen_sharing;
    public ?bool $camera_on;
    public ?bool $audio_sharing;

    /**
     * Single source of truth for validation — reused by the matching FormRequest.
     *
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'muted' => ['sometimes', 'boolean'],
            'deafened' => ['sometimes', 'boolean'],
            'screen_sharing' => ['sometimes', 'boolean'],
            'camera_on' => ['sometimes', 'boolean'],
            'audio_sharing' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return self::validationRules();
    }

    /**
     * Null, not false. An absent field means "don't touch it" — defaulting to false would
     * turn a "I started sharing my screen" into "…and unmute me while you're at it".
     *
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'muted' => null,
            'deafened' => null,
            'screen_sharing' => null,
            'camera_on' => null,
            'audio_sharing' => null,
        ];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'muted' => new NullableBooleanCast,
            'deafened' => new NullableBooleanCast,
            'screen_sharing' => new NullableBooleanCast,
            'camera_on' => new NullableBooleanCast,
            'audio_sharing' => new NullableBooleanCast,
        ];
    }

    /**
     * Only the fields the caller actually sent, ready to hand to update().
     *
     * @return array<string, bool>
     */
    public function changes(): array
    {
        return array_filter(
            [
                'muted' => $this->muted,
                'deafened' => $this->deafened,
                'screen_sharing' => $this->screen_sharing,
                'camera_on' => $this->camera_on,
                'audio_sharing' => $this->audio_sharing,
            ],
            fn (?bool $value) => $value !== null
        );
    }
}
