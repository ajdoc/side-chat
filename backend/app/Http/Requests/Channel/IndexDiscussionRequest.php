<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;

/**
 * Reading a channel's discussion directory.
 *
 * MemberRequest is the whole gate: it asks the channel, which applies the container's
 * membership rule and its own access list on top. Which discussions come back is decided
 * per row by `visibleTo` in the controller, so a private one is missing from the list rather
 * than making the whole list refuse.
 */
class IndexDiscussionRequest extends MemberRequest {}
