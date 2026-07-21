<?php

namespace App\Http\Requests\Nickname;

use App\Contracts\MessageContainer;
use App\Http\Requests\MemberRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for the nickname endpoints: a member of the place, and a handle on the place itself.
 *
 * The routes come in pairs — `servers/{server}/nicknames` and
 * `conversations/{conversation}/nicknames` — because a nickname belongs to the place and
 * there are two kinds of place. MemberRequest's route walking already turns either binding
 * into a MessageContainer, so both pairs land on the same controller and neither it nor
 * NicknameService ever learns which one it's serving.
 */
abstract class NicknameRequest extends MemberRequest
{
    /**
     * The server or chat these nicknames belong to.
     *
     * Narrowed to `MessageContainer&Model` because the service reaches for `getMorphClass()`
     * and `getKey()` — the contract itself deliberately says nothing about being a model.
     * Only ever called after authorize() has proved the container is there.
     */
    public function place(): MessageContainer&Model
    {
        $container = $this->resolveContainer();

        abort_if(! $container instanceof Model, 404);

        return $container;
    }
}
