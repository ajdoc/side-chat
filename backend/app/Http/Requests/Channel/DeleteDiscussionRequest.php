<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\ServerStaffRequest;

/**
 * Removing a discussion, and everything said in it.
 *
 * Staff, not whoever created it — the same rule that already guards deleting a channel. Opening
 * a conversation is cheap and reversible; deleting one takes other people's messages with it,
 * and those are not the creator's to throw away.
 */
class DeleteDiscussionRequest extends ServerStaffRequest {}
