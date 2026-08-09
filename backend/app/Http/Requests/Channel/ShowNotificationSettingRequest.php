<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;

/** Reading your own setting for a place: membership is the whole of the rule. */
class ShowNotificationSettingRequest extends MemberRequest {}
