<?php

namespace App\Http\Requests\Channel;

use App\Actions\Channel\ChangeChannelTypeAction;
use App\Http\Requests\MemberRequest;
use App\Models\Channel;
use App\Models\Server;
use App\Support\SideSpace\MapPresets;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changing what kind of channel this is.
 *
 * Who may: **staff** in a server, the **owner** of a group chat, and either person in a DM — the
 * same line drawn for muting somebody and for recording a call. It is a change to the *place*
 * rather than to anything in it, which is what makes it the place-owner's call; and in a room of
 * two there is nobody else it could belong to.
 *
 * Deliberately not folded into the rename endpoint, for the reason access settings aren't either:
 * renaming is cosmetic, and this ends any call in progress and can seed a map. A single PATCH
 * where forgetting a field converts a channel is not an API worth having.
 */
class ChangeChannelTypeRequest extends MemberRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $user = $this->user();
        $container = $this->resolveContainer();

        if ($user === null) {
            return false;
        }

        if ($container instanceof Server) {
            return $container->isStaff($user);
        }

        return $container?->owner_id === null || $container?->owner_id === $user->getKey();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ChangeChannelTypeAction::CONVERTIBLE)],
            // Only consulted when the channel is becoming a Side Space *and* hasn't got a map.
            'map_preset' => ['sometimes', 'nullable', 'string', Rule::in(MapPresets::keys())],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $channel = $this->route('channel');

                // An app channel's body is an application with a row of its own; installing and
                // uninstalling is the operation that means this. Said here rather than left to
                // the action so the client gets a field error rather than an exception.
                if ($channel instanceof Channel && ($channel->type === 'app' || $channel->app()->exists())) {
                    $validator->errors()->add('type', 'An app channel can’t be converted — uninstall the app instead.');
                }
            },
        ];
    }
}
