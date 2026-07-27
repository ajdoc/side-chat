<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * The management list of locks.
 *
 * Membership is enough to *ask*, because the answer is already scoped to what the asker is
 * entitled to see — the server's owner gets every lock in the space, a room owner gets the ones
 * they set, and everybody else gets an empty list. See the controller.
 *
 * An empty list rather than a 403 on purpose: "you have not locked anything" is a true and
 * useful answer, and it lets the client open the panel without first having to work out whether
 * it's allowed to.
 */
class IndexSpaceLocksRequest extends MemberRequest {}
