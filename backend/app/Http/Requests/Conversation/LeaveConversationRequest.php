<?php

namespace App\Http\Requests\Conversation;

use App\Http\Requests\MemberRequest;

/** Any member may walk out of a group. The action refuses it for a DM. */
class LeaveConversationRequest extends MemberRequest {}
