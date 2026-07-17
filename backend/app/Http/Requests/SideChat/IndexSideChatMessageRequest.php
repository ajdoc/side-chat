<?php

namespace App\Http\Requests\SideChat;

use App\Http\Requests\MemberRequest;

/** Reading the timeline needs only channel membership — same as viewing the side chat. */
class IndexSideChatMessageRequest extends MemberRequest {}
