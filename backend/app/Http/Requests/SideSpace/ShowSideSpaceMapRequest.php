<?php

namespace App\Http\Requests\SideSpace;

use App\Http\Requests\MemberRequest;

/**
 * Reading a Side Space's map needs exactly membership of the channel's server — which is all
 * {@see MemberRequest} checks. Everyone in the room may see the room.
 */
class ShowSideSpaceMapRequest extends MemberRequest {}
