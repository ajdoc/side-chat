<?php

namespace App\Http\Requests\Encryption;

use App\Http\Requests\MemberRequest;

/**
 * Reading the identity keys published in a channel, to compare safety numbers.
 *
 * Membership-gated like everything else channel-scoped, but with none of the caution
 * {@see ChannelDeviceRequest} needs: this consumes nothing, so it cannot be used to drain
 * anybody's prekeys. It also asks for no `device_id` — the question is about the *channel's*
 * devices, and which of the caller's own machines is asking has no bearing on the answer.
 */
class ViewIdentitiesRequest extends MemberRequest {}
