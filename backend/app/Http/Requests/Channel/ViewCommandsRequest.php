<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;

/**
 * Reading the command list needs exactly what typing one needs: membership of the channel.
 */
class ViewCommandsRequest extends MemberRequest {}
