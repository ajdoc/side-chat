<?php

namespace App\Http\Requests\Server;

use App\Http\Requests\MemberRequest;

/**
 * Any member may leave. The owner is a member too, so the "owner can't leave" rule is
 * *not* enforced here — it isn't an authorisation failure (403 would say "this isn't
 * yours", when it is precisely the opposite), it's a rule about what leaving means. It
 * lives in LeaveServerAction and comes back as a 422 that says what to do instead.
 */
class LeaveServerRequest extends MemberRequest {}
