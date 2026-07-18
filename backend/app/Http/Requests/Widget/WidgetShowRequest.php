<?php

namespace App\Http\Requests\Widget;

use App\Http\Requests\MemberRequest;

/**
 * Read a widget's live state. Authorized as channel membership (via the bound Widget).
 *
 * Clients call this after a reference-only WidgetUpdated / MessageSent tells them the
 * state moved without carrying it — the state is too big for Pusher's 10KB event cap
 * (see {@see \App\Events\WidgetUpdated}).
 */
class WidgetShowRequest extends MemberRequest
{
}
