<?php

namespace App\Http\Requests\Tracker;

use App\Http\Requests\MemberRequest;

/**
 * Reading a channel's tracker.
 *
 * Membership and nothing more, exactly like the rest of the Side Desk apps: a channel has no
 * roster of its own, so whoever can see the channel can see what's being tracked in it. Every
 * tracker route is addressed to its channel — `channels/{channel}/tracker/...` — which is what
 * lets {@see MemberRequest} answer this without the tracker knowing anything about permissions.
 */
class TrackerRequest extends MemberRequest {}
