<?php

namespace App\Http\Requests\Thread;

use App\Http\Requests\MemberRequest;
use App\Models\Thread;

/**
 * Rename a thread, or delete it — both gate the same way, so they share this base.
 *
 * Whoever started the thread, plus the server's staff. Not "anyone in the channel": a
 * thread's title is how everyone else finds it in the list, and a room where any passer-by
 * can retitle or bin a conversation is a room where titles stop meaning anything. Not
 * "staff only" either — the person who spun the thread up is the person most likely to
 * have mistyped its name.
 *
 * A DM or group chat has no staff, so there it's the creator alone. The rule itself lives
 * on the model ({@link Thread::canManage}) so ThreadResource can report the same answer to
 * the client without a second copy of it drifting.
 */
abstract class ThreadAuthorRequest extends MemberRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $thread = $this->route('thread');
        $user = $this->user();

        return $thread instanceof Thread && $user !== null && $thread->canManage($user);
    }
}
