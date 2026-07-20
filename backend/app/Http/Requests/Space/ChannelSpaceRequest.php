<?php

namespace App\Http\Requests\Space;

use App\Http\Requests\MemberRequest;

/**
 * A channel's Side Space has no roster to gate on — anyone in the channel may both read and
 * author it. So reading a channel note needs exactly channel membership, which is all
 * {@see MemberRequest} checks. Writing adds content validation on top: {@see UpdateChannelSpaceNoteRequest}.
 */
class ChannelSpaceRequest extends MemberRequest {}
