<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Unlocking a door. Carries nothing — the door is in the path and there is no such thing as a
 * partial unlock.
 *
 * Membership here; whether this member may unlock *this* door is decided in the controller
 * against the room the lock was set on, exactly as locking it was.
 */
class DestroySpaceLockRequest extends MemberRequest {}
