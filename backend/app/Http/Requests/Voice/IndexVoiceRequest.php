<?php

namespace App\Http\Requests\Voice;

use App\Http\Requests\MemberRequest;

/** Reading a server's voice rosters needs membership and nothing else. */
class IndexVoiceRequest extends MemberRequest {}
