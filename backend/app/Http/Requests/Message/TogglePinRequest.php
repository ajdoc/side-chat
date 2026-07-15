<?php

namespace App\Http\Requests\Message;

use App\Http\Requests\MemberRequest;

/** Any member may pin or unpin — a pin belongs to the channel, not to one person. */
class TogglePinRequest extends MemberRequest {}
