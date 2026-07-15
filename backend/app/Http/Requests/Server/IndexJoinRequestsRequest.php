<?php

namespace App\Http\Requests\Server;

use App\Http\Requests\MemberRequest;

/** Any member can review pending requests (server roles come later). */
class IndexJoinRequestsRequest extends MemberRequest {}
