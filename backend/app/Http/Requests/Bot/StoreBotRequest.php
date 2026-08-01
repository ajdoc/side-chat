<?php

namespace App\Http\Requests\Bot;

use App\DTOs\Bot\CreateBotData;
use App\Http\Requests\ServerOwnerRequest;

/**
 * Owner only, not staff.
 *
 * A bot is a credential that posts as a member of the server for as long as it exists, and
 * whoever holds the token holds that. Admins can run the place; handing out standing
 * write access to it stays with the one person who answers for it.
 */
class StoreBotRequest extends ServerOwnerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return CreateBotData::validationRules();
    }
}
