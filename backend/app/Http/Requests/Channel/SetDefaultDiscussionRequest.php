<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;

/**
 * Choosing which discussion a channel opens on, for you alone.
 *
 * MemberRequest is the whole of the gate: it asks the discussion, which applies the container's
 * membership rule and both levels of access on top. Nothing further to check — a preference
 * about where *you* land needs no permission beyond being allowed to land there.
 */
class SetDefaultDiscussionRequest extends MemberRequest {}
