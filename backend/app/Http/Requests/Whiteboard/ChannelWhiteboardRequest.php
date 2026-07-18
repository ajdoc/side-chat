<?php

namespace App\Http\Requests\Whiteboard;

use App\Http\Requests\MemberRequest;

/**
 * A channel's whiteboard has no roster to gate on — anyone in the channel may both read and
 * draw. So every operation on it needs exactly channel membership, which is all
 * {@see MemberRequest} checks (it resolves the container from the bound `channel`).
 */
class ChannelWhiteboardRequest extends MemberRequest {}
