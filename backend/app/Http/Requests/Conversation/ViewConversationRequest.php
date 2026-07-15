<?php

namespace App\Http\Requests\Conversation;

use App\Http\Requests\MemberRequest;

/** Reading a chat needs membership of it and nothing else. */
class ViewConversationRequest extends MemberRequest {}
