<?php

namespace App\Http\Requests\Nickname;

use App\Models\User;
use App\Services\NicknameService;

/**
 * Set or clear one naming.
 *
 * Two different permissions hide behind one endpoint, told apart by `scope`:
 *
 *  - `private` — what *you* call somebody, seen by nobody else. Any member may do this to
 *    any other member; it's a note you're writing to yourself.
 *  - `public` — what somebody is called here, seen by everyone. Yours to set, or the
 *    server owner's to set on your behalf. See NicknameService::canSetPublic.
 *
 * Both need the target to actually be in the place, or you could name people into a room
 * they aren't in.
 */
class UpdateNicknameRequest extends NicknameRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Null (or blank) clears the naming and falls back to the next name down.
            'nickname' => ['present', 'nullable', 'string', 'max:50'],
            'scope' => ['required', 'string', 'in:public,private'],
        ];
    }

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $actor = $this->user();
        $target = $this->target();

        if ($actor === null || ! $this->place()->hasMember($target)) {
            return false;
        }

        // A private alias is the actor's own note about someone; nothing more to prove.
        if ($this->input('scope') === 'private') {
            return true;
        }

        return app(NicknameService::class)->canSetPublic($this->place(), $actor, $target);
    }

    /** Whose name is being set — the `{member}` the route bound. */
    public function target(): User
    {
        $member = $this->route('member');

        abort_if(! $member instanceof User, 404);

        return $member;
    }

    public function isPublicScope(): bool
    {
        return $this->input('scope') === 'public';
    }
}
