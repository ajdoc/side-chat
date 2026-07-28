<?php

namespace App\Http\Requests\Channel;

use App\Http\Requests\ServerStaffRequest;

/**
 * Read a channel's access settings. Staff only, for the same reason the allow-list never
 * rides a broadcast: knowing exactly who is in a private channel is itself private.
 */
class ViewChannelAccessRequest extends ServerStaffRequest {}
