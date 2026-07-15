<?php

namespace App\Http\Requests\Conversation;

use App\Http\Requests\MemberRequest;

/** Declining a call you were rung for. Membership is the whole rule. */
class DeclineCallRequest extends MemberRequest {}
