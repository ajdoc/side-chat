<?php

namespace App\Http\Requests\Bot;

use App\Http\Requests\ServerOwnerRequest;

/**
 * The owner-only bot routes that carry no payload: listing, deleting, rotating a token.
 */
class ManageBotsRequest extends ServerOwnerRequest {}
