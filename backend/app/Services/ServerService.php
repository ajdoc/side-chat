<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ServerService
{
    public const PER_PAGE = 200;

    /** Servers the user belongs to (the left rail). */
    public function forUser(User $user): LengthAwarePaginator
    {
        return $user->servers()
            ->orderBy('servers.name')
            ->paginate(self::PER_PAGE);
    }
}
