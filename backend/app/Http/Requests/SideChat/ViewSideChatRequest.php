<?php

namespace App\Http\Requests\SideChat;

use App\Http\Requests\MemberRequest;

/** Anyone in the channel may read a side chat — joining is only needed to take part. */
class ViewSideChatRequest extends MemberRequest {}
