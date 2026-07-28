<?php

namespace App\Http\Requests\SideChatForum;

use App\Http\Requests\MemberRequest;

/** Reading the groups needs only what reading the posts needs: membership of the channel. */
class IndexSideChatForumRequest extends MemberRequest {}
