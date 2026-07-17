<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\MemberRequest;

/** Only a member of the channel's server or chat may list who's in it. */
class IndexChannelMemberRequest extends MemberRequest {}
