<?php

namespace App\DTOs\Server;

use WendellAdriel\ValidatedDTO\ValidatedDTO;

/** Ids of join requests to approve or decline (a single action is just a bulk of one). */
final class BulkJoinRequestData extends ValidatedDTO
{
    /** @var array<int, int> */
    public array $request_ids;

    /** @return array<string, mixed> */
    public static function validationRules(): array
    {
        return [
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['integer'],
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
        return [];
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [];
    }
}
